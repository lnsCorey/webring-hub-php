<?php
declare(strict_types=1);

namespace WebringHub\Ext\Arcade;

use PDO;
use WebringHub\AppError;
use WebringHub\Extension;
use WebringHub\ExtensionContext;
use WebringHub\ExtensionRequest;

final class ArcadeExtension implements Extension
{
    private const MAX_MANIFEST_BYTES = 65536;
    private const MAX_META_BYTES = 2048;

    private ExtensionContext $ctx;
    private string $tGames = '';
    private string $tScores = '';
    private string $tPlays = '';
    private string $tSessions = '';
    private string $tLimits = '';

    /** @var array<string, string> slug/folder => validation error */
    private array $scanErrors = [];
    private bool $catalogSynced = false;

    /** @var array<string, int>|null play counts per game, loaded in one query per request */
    private ?array $playCounts = null;

    /**
     * @return array<string, int>
     */
    private function countPlaysBySlug(): array
    {
        $counts = [];
        foreach ($this->ctx->pdo()->query("SELECT game_slug, COUNT(*) AS n FROM {$this->tPlays} GROUP BY game_slug")->fetchAll() as $r) {
            $counts[(string) $r['game_slug']] = (int) $r['n'];
        }
        return $counts;
    }

    public function register(ExtensionContext $ctx): void
    {
        $this->ctx = $ctx;
        $this->tGames = $ctx->table('games');
        $this->tScores = $ctx->table('scores');
        $this->tPlays = $ctx->table('plays');
        $this->tSessions = $ctx->table('sessions');
        $this->tLimits = $ctx->table('limits');

        $ctx->migrate(1, function (PDO $pdo, string $driver): void {
            $this->createTables($pdo, $driver);
        });
        // v2: rate-limit rows record the DID they belong to, so removing a player deletes exactly
        // their rows (instead of a LIKE pattern in which `_` and `%` in a DID act as wildcards).
        $ctx->migrate(2, function (PDO $pdo, string $driver): void {
            if ($driver === 'sqlite') {
                $pdo->exec("ALTER TABLE {$this->tLimits} ADD COLUMN did TEXT NOT NULL DEFAULT ''");
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_{$this->tLimits}_did ON {$this->tLimits}(did)");
            } else {
                $pdo->exec("ALTER TABLE {$this->tLimits} ADD COLUMN did VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL DEFAULT '', ADD KEY idx_limits_did (did)");
            }
        });

        // Serve arcade UI (/ext/arcade/) and isolated games (/ext/arcade/games/<slug>/)
        $arcadePageCsp = "default-src 'self'; img-src 'self' https: data: blob:; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; frame-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'";
        // `sandbox` puts every game page on an opaque origin even when it is opened directly
        // instead of inside the arcade's sandboxed iframe, so a game can never read the hub's
        // storage (such as the admin token) or call its APIs with the visitor's session.
        $gameCsp = "sandbox allow-scripts allow-pointer-lock; default-src 'self'; script-src 'self' 'wasm-unsafe-eval'; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; media-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; base-uri 'none'; form-action 'none'; frame-ancestors 'self'";

        if (is_dir($ctx->dir . '/public')) {
            $ctx->serveFiles('', 'public', [
                'csp' => $arcadePageCsp,
                'frameable' => false,
            ]);
        }
        if (is_dir($ctx->dir . '/games')) {
            $ctx->serveFiles('games', 'games', [
                'cors' => true,
                'csp' => $gameCsp,
                'frameable' => true,
            ]);
        }

        $ctx->on('entry.removed', fn (array $e) => $this->purgePlayer((string) ($e['did'] ?? '')));
        $ctx->on('user.extension_opt_out', function (array $e): void {
            if (($e['extensionId'] ?? '') === 'arcade') {
                $this->purgePlayer((string) ($e['did'] ?? ''));
            }
        });
        $ctx->on('cron.refresh', function (): void {
            // The games folder is rescanned here and from the admin routes, never on public
            // requests, so anonymous visitors can't make the hub write to its database.
            $this->syncCatalog(true);
            $this->pruneExpiredAndCapped();
        });

        $ctx->get('v1/games', fn (array $p, ExtensionRequest $req) => $this->listGames($req));
        $ctx->get('v1/games/{slug}', fn (array $p) => $this->getGame($p['slug']));
        $ctx->get('v1/games/{slug}/scores', fn (array $p, ExtensionRequest $req) => $this->getLeaderboard($p['slug'], $req));
        $ctx->post('v1/games/{slug}/sessions', fn (array $p, ExtensionRequest $req) => $this->createSession($p['slug'], $req), ['userAuth' => true, 'requireOptIn' => true]);
        $ctx->post('v1/games/{slug}/scores', fn (array $p, ExtensionRequest $req) => $this->submitScore($p['slug'], $req), ['userAuth' => true, 'requireOptIn' => true]);
        $ctx->get('v1/players/{did}/history', fn (array $p, ExtensionRequest $req) => $this->getPlayerHistory($p['did'], $req));

        $ctx->get('v1/admin/games', fn () => $this->adminListGames(), ['admin' => true]);
        $ctx->post('v1/admin/games/{slug}/enabled', fn (array $p, ExtensionRequest $req) => $this->adminSetEnabled($p['slug'], $req), ['admin' => true]);
        $ctx->delete('v1/admin/players/{did}', fn (array $p) => $this->adminDeletePlayer($p['did']), ['admin' => true]);
        $ctx->delete('v1/admin/games/{slug}/scores', fn (array $p) => $this->adminDeleteGameScores($p['slug']), ['admin' => true]);
    }

    private function settingInt(string $key, int $default, int $min = 1, int $max = 100000): int
    {
        $cfg = $this->ctx->config();
        $val = isset($cfg[$key]) && is_numeric($cfg[$key]) ? (int) $cfg[$key] : $default;
        return min(max($val, $min), $max);
    }

    /**
     * Public requests only read the catalog. It is filled from the games folder on the first
     * request after install (when the table is still empty), and kept current by the cron
     * refresh and the admin routes, which call `syncCatalog`. So anonymous traffic never writes
     * to the database on reads.
     */
    private function ensureCatalog(): void
    {
        if ($this->catalogSynced) {
            return;
        }
        $hasRows = $this->ctx->pdo()->query("SELECT 1 FROM {$this->tGames} LIMIT 1")->fetchColumn();
        if ($hasRows === false) {
            $this->syncCatalog();
        }
        $this->catalogSynced = true;
    }

    /**
     * Scans `extensions/arcade/games/*` for `game.json`, validates each manifest, and syncs
     * the `games` table without letting an invalid folder break the catalog.
     */
    public function syncCatalog(bool $force = false): void
    {
        if ($this->catalogSynced && !$force) {
            return;
        }
        $this->catalogSynced = true;
        $this->scanErrors = [];

        $gamesRoot = $this->ctx->dir . '/games';
        $pdo = $this->ctx->pdo();
        $now = $this->ctx->isoNow();
        $seenSlugs = [];

        if (is_dir($gamesRoot)) {
            $entries = @scandir($gamesRoot) ?: [];
            foreach ($entries as $folder) {
                if ($folder === '.' || $folder === '..' || str_starts_with($folder, '.')) {
                    continue;
                }
                $folderPath = $gamesRoot . '/' . $folder;
                if (!is_dir($folderPath)) {
                    continue;
                }
                try {
                    $manifest = $this->validateGameFolder($folder, $folderPath);
                    $seenSlugs[] = $folder;
                    $this->upsertGameManifest($pdo, $folder, $manifest, $now);
                } catch (\Throwable $err) {
                    $this->scanErrors[$folder] = $err->getMessage();
                }
            }
        }

        if (count($seenSlugs) === 0) {
            $pdo->exec("UPDATE {$this->tGames} SET present = 0");
        } else {
            $placeholders = implode(',', array_fill(0, count($seenSlugs), '?'));
            $stmt = $pdo->prepare("UPDATE {$this->tGames} SET present = 0 WHERE slug NOT IN ({$placeholders})");
            $stmt->execute($seenSlugs);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function validateGameFolder(string $folder, string $folderPath): array
    {
        if (!preg_match('/^[a-z][a-z0-9-]{1,40}$/', $folder)) {
            throw new \RuntimeException('Folder name must match ^[a-z][a-z0-9-]{1,40}$.');
        }
        $manifestFile = $folderPath . '/game.json';
        if (!is_file($manifestFile)) {
            throw new \RuntimeException('Missing game.json.');
        }
        $size = (int) @filesize($manifestFile);
        if ($size <= 0 || $size > self::MAX_MANIFEST_BYTES) {
            throw new \RuntimeException('game.json is empty or exceeds 64 KB.');
        }
        $raw = (string) file_get_contents($manifestFile, false, null, 0, self::MAX_MANIFEST_BYTES + 1);
        $data = json_decode($raw, true);
        if (!is_array($data) || array_is_list($data)) {
            throw new \RuntimeException('game.json must be a JSON object.');
        }
        if (($data['schemaVersion'] ?? null) !== 1) {
            throw new \RuntimeException('schemaVersion must be 1.');
        }
        $slug = (string) ($data['slug'] ?? '');
        if ($slug !== $folder) {
            throw new \RuntimeException("slug \"{$slug}\" must match folder name \"{$folder}\".");
        }
        $title = trim((string) ($data['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 120) {
            throw new \RuntimeException('title is required (1-120 chars).');
        }
        $description = trim((string) ($data['description'] ?? ''));
        if ($description === '' || mb_strlen($description) > 600) {
            throw new \RuntimeException('description is required (1-600 chars).');
        }
        $entry = (string) ($data['entry'] ?? 'index.html');
        if (
            !preg_match('/^[A-Za-z0-9._\/-]+\.(?:html|htm)$/', $entry) ||
            str_contains($entry, '..') ||
            str_starts_with($entry, '/')
        ) {
            throw new \RuntimeException('entry must be a relative .html/.htm path inside the game folder.');
        }
        $realFolder = realpath($folderPath);
        $realEntry = realpath($folderPath . '/' . $entry);
        if (
            $realFolder === false ||
            $realEntry === false ||
            !is_file($realEntry) ||
            !str_starts_with($realEntry, rtrim($realFolder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)
        ) {
            throw new \RuntimeException("entry file \"{$entry}\" does not exist inside the game folder.");
        }

        $thumbnail = '';
        if (!empty($data['thumbnail']) && is_string($data['thumbnail'])) {
            $thumbRel = $data['thumbnail'];
            if (
                preg_match('/^[A-Za-z0-9._\/-]+\.(?:svg|png|jpg|jpeg|webp|gif)$/', $thumbRel) &&
                !str_contains($thumbRel, '..') &&
                !str_starts_with($thumbRel, '/')
            ) {
                $realThumb = realpath($folderPath . '/' . $thumbRel);
                if ($realThumb !== false && is_file($realThumb) && str_starts_with($realThumb, rtrim($realFolder, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                    $thumbnail = $thumbRel;
                }
            }
        }

        $tags = [];
        if (isset($data['tags']) && is_array($data['tags'])) {
            foreach (array_slice($data['tags'], 0, 10) as $t) {
                if (is_string($t)) {
                    $clean = strtolower(trim($t));
                    if ($clean !== '' && mb_strlen($clean) <= 32) {
                        $tags[] = $clean;
                    }
                }
            }
        }
        $tags = array_values(array_unique($tags));

        $scoreIn = is_array($data['score'] ?? null) ? $data['score'] : [];
        $direction = ($scoreIn['direction'] ?? 'higher') === 'lower' ? 'lower' : 'higher';
        $minScore = isset($scoreIn['min']) && is_int($scoreIn['min']) ? $scoreIn['min'] : 0;
        $maxScore = isset($scoreIn['max']) && is_int($scoreIn['max']) ? $scoreIn['max'] : 1000000000;
        if ($minScore < 0 || $maxScore < $minScore) {
            throw new \RuntimeException('Invalid score min/max bounds.');
        }
        $scoreCfg = [
            'enabled' => ($scoreIn['enabled'] ?? true) !== false,
            'direction' => $direction,
            'min' => $minScore,
            'max' => $maxScore,
            'label' => trim((string) ($scoreIn['label'] ?? 'Score')) ?: 'Score',
            'unit' => trim((string) ($scoreIn['unit'] ?? 'points')) ?: 'points',
        ];

        $controls = [];
        if (isset($data['controls']) && is_array($data['controls'])) {
            foreach (array_slice($data['controls'], 0, 12) as $c) {
                if (is_array($c) && !empty($c['action']) && is_string($c['action'])) {
                    $keys = [];
                    if (is_array($c['keys'] ?? null)) {
                        foreach (array_slice($c['keys'], 0, 8) as $k) {
                            if (is_string($k) && trim($k) !== '') {
                                $keys[] = substr(trim($k), 0, 32);
                            }
                        }
                    }
                    $controls[] = [
                        'action' => substr(trim($c['action']), 0, 64),
                        'keys' => $keys,
                    ];
                }
            }
        }

        return [
            'schemaVersion' => 1,
            'slug' => $slug,
            'title' => $title,
            'description' => $description,
            'version' => substr(trim((string) ($data['version'] ?? '1.0.0')) ?: '1.0.0', 0, 32),
            'author' => substr(trim((string) ($data['author'] ?? '')), 0, 120),
            'license' => substr(trim((string) ($data['license'] ?? '')), 0, 64),
            'entry' => $entry,
            'thumbnail' => $thumbnail,
            'tags' => $tags,
            'players' => max(1, min(4, (int) ($data['players'] ?? 1))),
            'orientation' => in_array($data['orientation'] ?? '', ['landscape', 'portrait', 'any'], true) ? $data['orientation'] : 'landscape',
            'aspectRatio' => substr(trim((string) ($data['aspectRatio'] ?? '4 / 3')), 0, 20),
            'controls' => $controls,
            'score' => $scoreCfg,
            'capabilities' => is_array($data['capabilities'] ?? null)
                ? array_values(array_filter(array_map('strval', array_slice($data['capabilities'], 0, 10))))
                : ['canvas', 'keyboard'],
            'sdk' => (int) ($data['sdk'] ?? 1),
        ];
    }

    /**
     * @param array<string, mixed> $manifest
     */
    private function upsertGameManifest(PDO $pdo, string $slug, array $manifest, string $now): void
    {
        $json = json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
        $hash = hash('sha256', $json);
        $tagsText = implode(' ', $manifest['tags']);

        $stmt = $pdo->prepare("SELECT manifest_hash FROM {$this->tGames} WHERE slug = ?");
        $stmt->execute([$slug]);
        $existingHash = $stmt->fetchColumn();

        if ($existingHash === false) {
            $ins = $pdo->prepare("
                INSERT INTO {$this->tGames} (
                    slug, manifest_json, manifest_hash, title, description, tags_text,
                    version, entry, thumbnail, present, enabled, installed_at, updated_at, last_seen_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1, ?, ?, ?)
            ");
            $ins->execute([
                $slug,
                $json,
                $hash,
                $manifest['title'],
                $manifest['description'],
                $tagsText,
                $manifest['version'],
                $manifest['entry'],
                $manifest['thumbnail'],
                $now,
                $now,
                $now,
            ]);
            return;
        }

        if ($existingHash === $hash) {
            $upd = $pdo->prepare("UPDATE {$this->tGames} SET present = 1, last_seen_at = ? WHERE slug = ?");
            $upd->execute([$now, $slug]);
            return;
        }

        $upd = $pdo->prepare("
            UPDATE {$this->tGames} SET
                manifest_json = ?, manifest_hash = ?, title = ?, description = ?, tags_text = ?,
                version = ?, entry = ?, thumbnail = ?, present = 1, updated_at = ?, last_seen_at = ?
            WHERE slug = ?
        ");
        $upd->execute([
            $json,
            $hash,
            $manifest['title'],
            $manifest['description'],
            $tagsText,
            $manifest['version'],
            $manifest['entry'],
            $manifest['thumbnail'],
            $now,
            $now,
            $slug,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function listGames(ExtensionRequest $req): array
    {
        $this->ensureCatalog();
        $pdo = $this->ctx->pdo();
        $q = strtolower(trim((string) ($req->query['q'] ?? '')));
        $tag = strtolower(trim((string) ($req->query['tag'] ?? '')));
        $limit = min(max((int) ($req->query['limit'] ?? 50), 1), 100);
        $offset = max((int) ($req->query['offset'] ?? 0), 0);

        $rows = $pdo->query("SELECT * FROM {$this->tGames} WHERE present = 1 AND enabled = 1 ORDER BY title ASC")->fetchAll();
        $this->playCounts = $this->countPlaysBySlug();
        $filtered = [];
        foreach ($rows as $row) {
            $manifest = json_decode((string) $row['manifest_json'], true) ?: [];
            if ($tag !== '' && !in_array($tag, $manifest['tags'] ?? [], true)) {
                continue;
            }
            if ($q !== '') {
                $haystack = strtolower($row['title'] . ' ' . $row['description'] . ' ' . $row['tags_text']);
                if (!str_contains($haystack, $q)) {
                    continue;
                }
            }
            $filtered[] = $this->formatGameSummary($row, $manifest);
        }

        return [
            'total' => count($filtered),
            'games' => array_slice($filtered, $offset, $limit),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @param array<string, mixed> $manifest
     * @return array<string, mixed>
     */
    private function formatGameSummary(array $row, array $manifest): array
    {
        $slug = (string) $row['slug'];
        if ($this->playCounts === null) {
            $this->playCounts = $this->countPlaysBySlug();
        }
        $playCount = $this->playCounts[$slug] ?? 0;

        $topLeader = $this->queryLeaderboard($slug, (string) ($manifest['score']['direction'] ?? 'higher'), 1, 0);

        return [
            'slug' => $slug,
            'title' => (string) $row['title'],
            'description' => (string) $row['description'],
            'version' => (string) $row['version'],
            'author' => (string) ($manifest['author'] ?? ''),
            'license' => (string) ($manifest['license'] ?? ''),
            'tags' => $manifest['tags'] ?? [],
            'players' => (int) ($manifest['players'] ?? 1),
            'orientation' => (string) ($manifest['orientation'] ?? 'landscape'),
            'aspectRatio' => (string) ($manifest['aspectRatio'] ?? '4 / 3'),
            'entryUrl' => "/ext/arcade/games/{$slug}/" . ltrim((string) $row['entry'], '/'),
            'thumbnailUrl' => $row['thumbnail'] !== '' ? "/ext/arcade/games/{$slug}/" . ltrim((string) $row['thumbnail'], '/') : '',
            'controls' => $manifest['controls'] ?? [],
            'score' => $manifest['score'] ?? [],
            'capabilities' => $manifest['capabilities'] ?? [],
            'stats' => [
                'plays' => $playCount,
                'topScore' => $topLeader[0] ?? null,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getGame(string $slug): array
    {
        $this->ensureCatalog();
        $row = $this->requireActiveGameRow($slug);
        $manifest = json_decode((string) $row['manifest_json'], true) ?: [];
        $limit = $this->settingInt('leaderboard_limit', 25, 1, 100);
        $leaderboard = $this->queryLeaderboard($slug, (string) ($manifest['score']['direction'] ?? 'higher'), $limit, 0);

        return [
            'game' => $this->formatGameSummary($row, $manifest),
            'leaderboard' => $leaderboard,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getLeaderboard(string $slug, ExtensionRequest $req): array
    {
        $this->ensureCatalog();
        $row = $this->requireActiveGameRow($slug);
        $manifest = json_decode((string) $row['manifest_json'], true) ?: [];
        $defaultLimit = $this->settingInt('leaderboard_limit', 25, 1, 100);
        $limit = min(max((int) ($req->query['limit'] ?? $defaultLimit), 1), 100);
        $offset = max((int) ($req->query['offset'] ?? 0), 0);
        $direction = (string) ($manifest['score']['direction'] ?? 'higher');

        return [
            'gameSlug' => $slug,
            'direction' => $direction,
            'scores' => $this->queryLeaderboard($slug, $direction, $limit, $offset),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function queryLeaderboard(string $slug, string $direction, int $limit, int $offset): array
    {
        $order = $direction === 'lower' ? 'ASC' : 'DESC';
        $pdo = $this->ctx->pdo();
        $sql = "
            SELECT s.did, s.score, s.duration_ms, s.achieved_at, s.verified,
                   e.display_name, e.avatar_url, e.site_url
            FROM {$this->tScores} s
            INNER JOIN entries e ON e.did = s.did AND e.status = 'active'
            WHERE s.game_slug = ?
            ORDER BY s.score {$order}, s.achieved_at ASC
            LIMIT ? OFFSET ?
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->bindValue(1, $slug);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->bindValue(3, $offset, PDO::PARAM_INT);
        $stmt->execute();

        $out = [];
        $rank = $offset + 1;
        foreach ($stmt->fetchAll() as $r) {
            $out[] = [
                'rank' => $rank++,
                'did' => (string) $r['did'],
                'displayName' => (string) $r['display_name'],
                'siteUrl' => (string) $r['site_url'],
                'avatarUrl' => $this->safeAvatarUrl((string) $r['avatar_url'], (string) $r['site_url']),
                'score' => (int) $r['score'],
                'durationMs' => (int) $r['duration_ms'],
                'achievedAt' => (string) $r['achieved_at'],
                'verified' => !empty($r['verified']),
            ];
        }
        return $out;
    }

    private function safeAvatarUrl(string $avatarUrl, string $siteUrl): string
    {
        if ($avatarUrl === '' || $siteUrl === '') {
            return '';
        }
        $a = parse_url($avatarUrl);
        $s = parse_url($siteUrl);
        if (!is_array($a) || !is_array($s)) {
            return '';
        }
        if (strtolower((string) ($a['scheme'] ?? '')) !== 'https') {
            return '';
        }
        if (strtolower((string) ($a['host'] ?? '')) !== strtolower((string) ($s['host'] ?? ''))) {
            return '';
        }
        return $avatarUrl;
    }

    /**
     * @return array<string, mixed>
     */
    private function createSession(string $slug, ExtensionRequest $req): array
    {
        $this->ensureCatalog();
        $this->requireActiveGameRow($slug);
        $did = trim((string) ($req->userDid ?? ''));
        if ($did === '') {
            throw new AppError(401, 'auth_required', 'Sign in with your Webring Studio DID to record scores.');
        }
        if (!$req->userOptedIn) {
            throw new AppError(403, 'opt_in_required', 'Opt in to Arcade score recording before starting a scored session.');
        }
        $this->requireActivePlayer($did);

        // Scores are self-reported, so limits are per client: per (client, DID) so nobody can use up
        // another player's quota, and per client overall so one visitor can't flood the boards.
        $client = $req->clientKey !== '' ? $req->clientKey : 'unknown';
        $maxPerHour = $this->settingInt('max_sessions_per_did_hour', 60, 1, 1000);
        $maxPerClient = $this->settingInt('max_sessions_per_ip_hour', 120, 1, 10000);
        if (
            !$this->checkLimit('session:pair:' . hash('sha256', $client . '|' . $did), 3600, $maxPerHour, $did)
            || !$this->checkLimit('session:client:' . $client, 3600, $maxPerClient)
        ) {
            throw new AppError(429, 'rate_limited', "You're starting games too quickly. Try again in a few minutes.");
        }

        $ttl = $this->settingInt('session_ttl_seconds', 7200, 60, 86400);
        $token = bin2hex(random_bytes(24));
        $idHash = hash('sha256', $token);
        $now = time();
        $createdAt = gmdate('Y-m-d\TH:i:s\Z', $now);
        $expiresAt = gmdate('Y-m-d\TH:i:s\Z', $now + $ttl);

        $stmt = $this->ctx->pdo()->prepare("
            INSERT INTO {$this->tSessions} (id_hash, game_slug, did, created_at, expires_at, used_at)
            VALUES (?, ?, ?, ?, ?, NULL)
        ");
        $stmt->execute([$idHash, $slug, $did, $createdAt, $expiresAt]);

        return [201, [
            'sessionId' => $token,
            'gameSlug' => $slug,
            'did' => $did,
            'expiresAt' => $expiresAt,
        ]];
    }

    /**
     * @return array<string, mixed>
     */
    private function submitScore(string $slug, ExtensionRequest $req): array
    {
        $this->ensureCatalog();
        $gameRow = $this->requireActiveGameRow($slug);
        $manifest = json_decode((string) $gameRow['manifest_json'], true) ?: [];
        $scoreCfg = is_array($manifest['score'] ?? null) ? $manifest['score'] : [];
        if (($scoreCfg['enabled'] ?? true) === false) {
            throw new AppError(400, 'scoring_disabled', 'This game does not accept high scores.');
        }

        $body = $req->json();
        $sessionId = trim((string) ($body['sessionId'] ?? ''));
        $did = trim((string) ($req->userDid ?? ''));
        $scoreVal = $body['score'] ?? null;
        $durationVal = $body['durationMs'] ?? 0;

        if ($did === '') {
            throw new AppError(401, 'auth_required', 'Sign in with your Webring Studio DID to submit scores.');
        }
        if (!$req->userOptedIn) {
            throw new AppError(403, 'opt_in_required', 'Opt in to Arcade score recording before submitting scores.');
        }
        if ($sessionId === '') {
            throw new AppError(400, 'invalid_request', 'sessionId is required.');
        }
        $this->requireActivePlayer($did);

        if (!is_int($scoreVal) && !(is_float($scoreVal) && floor($scoreVal) === $scoreVal)) {
            throw new AppError(400, 'invalid_score', 'score must be an integer.');
        }
        $score = (int) $scoreVal;
        $minScore = (int) ($scoreCfg['min'] ?? 0);
        $maxScore = (int) ($scoreCfg['max'] ?? 1000000000);
        if ($score < $minScore || $score > $maxScore) {
            throw new AppError(400, 'score_out_of_bounds', "score must be between {$minScore} and {$maxScore}.");
        }

        $ttlMs = $this->settingInt('session_ttl_seconds', 7200, 60, 86400) * 1000;
        if (!is_int($durationVal) && !(is_float($durationVal) && floor($durationVal) === $durationVal)) {
            throw new AppError(400, 'invalid_duration', 'durationMs must be an integer.');
        }
        $durationMs = (int) $durationVal;
        if ($durationMs < 0 || $durationMs > $ttlMs) {
            throw new AppError(400, 'invalid_duration', 'durationMs is out of bounds.');
        }

        $metaJson = null;
        if (array_key_exists('meta', $body) && $body['meta'] !== null) {
            if (!is_array($body['meta'])) {
                throw new AppError(400, 'invalid_meta', 'meta must be an object.');
            }
            $encoded = json_encode($body['meta'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if ($encoded === false || strlen($encoded) > self::MAX_META_BYTES) {
                throw new AppError(400, 'meta_too_large', 'meta exceeds 2 KB limit.');
            }
            $metaJson = $encoded;
        }

        $client = $req->clientKey !== '' ? $req->clientKey : 'unknown';
        $maxScoresHour = $this->settingInt('max_scores_per_did_hour', 60, 1, 1000);
        $maxScoresClient = $this->settingInt('max_scores_per_ip_hour', 300, 1, 10000);
        if (
            !$this->checkLimit('score:pair:' . hash('sha256', $client . '|' . $did), 3600, $maxScoresHour, $did)
            || !$this->checkLimit('score:client:' . $client, 3600, $maxScoresClient)
        ) {
            throw new AppError(429, 'rate_limited', "You're submitting scores too quickly. Try again in a few minutes.");
        }

        $pdo = $this->ctx->pdo();
        $idHash = hash('sha256', $sessionId);
        $sessStmt = $pdo->prepare("SELECT * FROM {$this->tSessions} WHERE id_hash = ?");
        $sessStmt->execute([$idHash]);
        $sess = $sessStmt->fetch();
        if (!is_array($sess)) {
            throw new AppError(400, 'invalid_session', 'Unknown play session.');
        }
        if ($sess['game_slug'] !== $slug || $sess['did'] !== $did) {
            throw new AppError(403, 'session_mismatch', 'Session does not match this game or player DID.');
        }
        if ($sess['used_at'] !== null) {
            throw new AppError(409, 'session_used', 'This play session has already submitted a score.');
        }
        $expiresTs = strtotime((string) $sess['expires_at']);
        if ($expiresTs === false || time() > $expiresTs) {
            throw new AppError(410, 'session_expired', 'This play session has expired.');
        }

        $nowIso = $this->ctx->isoNow();
        $useUpd = $pdo->prepare("UPDATE {$this->tSessions} SET used_at = ? WHERE id_hash = ? AND used_at IS NULL");
        $useUpd->execute([$nowIso, $idHash]);
        if ($useUpd->rowCount() === 0) {
            throw new AppError(409, 'session_used', 'This play session has already submitted a score.');
        }

        $playIns = $pdo->prepare("
            INSERT INTO {$this->tPlays} (game_slug, did, score, duration_ms, started_at, finished_at, meta_json)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        $playIns->execute([$slug, $did, $score, $durationMs, (string) $sess['created_at'], $nowIso, $metaJson]);
        $playId = (int) $pdo->lastInsertId();

        $direction = (string) ($scoreCfg['direction'] ?? 'higher');
        $bestStmt = $pdo->prepare("SELECT * FROM {$this->tScores} WHERE game_slug = ? AND did = ?");
        $bestStmt->execute([$slug, $did]);
        $currentBest = $bestStmt->fetch();

        $isPersonalBest = false;
        $bestScore = $score;
        if (!is_array($currentBest)) {
            $isPersonalBest = true;
            $insBest = $pdo->prepare("
                INSERT INTO {$this->tScores} (game_slug, did, score, duration_ms, achieved_at, play_id, verified)
                VALUES (?, ?, ?, ?, ?, ?, 0)
            ");
            $insBest->execute([$slug, $did, $score, $durationMs, $nowIso, $playId]);
        } else {
            $prevScore = (int) $currentBest['score'];
            $better = $direction === 'lower' ? ($score < $prevScore) : ($score > $prevScore);
            if ($better) {
                $isPersonalBest = true;
                $updBest = $pdo->prepare("
                    UPDATE {$this->tScores}
                    SET score = ?, duration_ms = ?, achieved_at = ?, play_id = ?, verified = 0
                    WHERE id = ?
                ");
                $updBest->execute([$score, $durationMs, $nowIso, $playId, $currentBest['id']]);
            } else {
                $bestScore = $prevScore;
            }
        }

        $this->prunePlayerGameRuns($slug, $did);

        $cmp = $direction === 'lower' ? '<' : '>';
        $rankStmt = $pdo->prepare("
            SELECT COUNT(*) FROM {$this->tScores} s
            INNER JOIN entries e ON e.did = s.did AND e.status = 'active'
            WHERE s.game_slug = ? AND s.score {$cmp} ?
        ");
        $rankStmt->execute([$slug, $bestScore]);
        $rank = ((int) $rankStmt->fetchColumn()) + 1;

        return [
            'accepted' => true,
            // Scores are reported by the player's browser and never checked, so they are always
            // unverified. The leaderboard labels them that way.
            'verified' => false,
            'isPersonalBest' => $isPersonalBest,
            'best' => $bestScore,
            'rank' => $rank,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function getPlayerHistory(string $did, ExtensionRequest $req): array
    {
        $this->ensureCatalog();
        $entry = $this->requireActivePlayer($did);
        $limit = min(max((int) ($req->query['limit'] ?? 25), 1), 100);
        $offset = max((int) ($req->query['offset'] ?? 0), 0);
        $pdo = $this->ctx->pdo();

        $bestStmt = $pdo->prepare("
            SELECT s.game_slug, s.score, s.duration_ms, s.achieved_at, s.verified, g.title
            FROM {$this->tScores} s
            LEFT JOIN {$this->tGames} g ON g.slug = s.game_slug
            WHERE s.did = ?
            ORDER BY s.achieved_at DESC
        ");
        $bestStmt->execute([$did]);
        $bests = [];
        foreach ($bestStmt->fetchAll() as $b) {
            $bests[] = [
                'gameSlug' => (string) $b['game_slug'],
                'gameTitle' => (string) ($b['title'] ?? $b['game_slug']),
                'score' => (int) $b['score'],
                'durationMs' => (int) $b['duration_ms'],
                'achievedAt' => (string) $b['achieved_at'],
                'verified' => !empty($b['verified']),
            ];
        }

        $playsStmt = $pdo->prepare("
            SELECT p.id, p.game_slug, p.score, p.duration_ms, p.started_at, p.finished_at, p.meta_json, g.title
            FROM {$this->tPlays} p
            LEFT JOIN {$this->tGames} g ON g.slug = p.game_slug
            WHERE p.did = ?
            ORDER BY p.finished_at DESC, p.id DESC
            LIMIT ? OFFSET ?
        ");
        $playsStmt->bindValue(1, $did);
        $playsStmt->bindValue(2, $limit, PDO::PARAM_INT);
        $playsStmt->bindValue(3, $offset, PDO::PARAM_INT);
        $playsStmt->execute();

        $plays = [];
        foreach ($playsStmt->fetchAll() as $p) {
            $plays[] = [
                'id' => (int) $p['id'],
                'gameSlug' => (string) $p['game_slug'],
                'gameTitle' => (string) ($p['title'] ?? $p['game_slug']),
                'score' => (int) $p['score'],
                'durationMs' => (int) $p['duration_ms'],
                'startedAt' => (string) $p['started_at'],
                'finishedAt' => (string) $p['finished_at'],
                'meta' => $p['meta_json'] ? json_decode((string) $p['meta_json'], true) : null,
            ];
        }

        return [
            'did' => (string) $entry['did'],
            'displayName' => (string) $entry['display_name'],
            'bestScores' => $bests,
            'recentPlays' => $plays,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminListGames(): array
    {
        $this->syncCatalog(true);
        $pdo = $this->ctx->pdo();
        $rows = $pdo->query("SELECT * FROM {$this->tGames} ORDER BY slug ASC")->fetchAll();
        $games = [];
        foreach ($rows as $row) {
            $slug = (string) $row['slug'];
            $playsStmt = $pdo->prepare("SELECT COUNT(*) FROM {$this->tPlays} WHERE game_slug = ?");
            $playsStmt->execute([$slug]);
            $scoresStmt = $pdo->prepare("SELECT COUNT(*) FROM {$this->tScores} WHERE game_slug = ?");
            $scoresStmt->execute([$slug]);
            $games[] = [
                'slug' => $slug,
                'title' => (string) $row['title'],
                'version' => (string) $row['version'],
                'present' => (int) $row['present'] === 1,
                'enabled' => (int) $row['enabled'] === 1,
                'plays' => (int) $playsStmt->fetchColumn(),
                'playersScored' => (int) $scoresStmt->fetchColumn(),
                'installedAt' => (string) $row['installed_at'],
                'updatedAt' => (string) $row['updated_at'],
                'lastSeenAt' => (string) $row['last_seen_at'],
            ];
        }
        return [
            'games' => $games,
            'validationErrors' => $this->scanErrors,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminSetEnabled(string $slug, ExtensionRequest $req): array
    {
        $this->syncCatalog();
        $body = $req->json();
        if (!isset($body['enabled']) || !is_bool($body['enabled'])) {
            throw new AppError(400, 'invalid_request', 'Send {"enabled": true} or {"enabled": false}.');
        }
        $pdo = $this->ctx->pdo();
        $stmt = $pdo->prepare("SELECT slug FROM {$this->tGames} WHERE slug = ?");
        $stmt->execute([$slug]);
        if ($stmt->fetchColumn() === false) {
            throw new AppError(404, 'not_found', 'No such game.');
        }
        $upd = $pdo->prepare("UPDATE {$this->tGames} SET enabled = ?, updated_at = ? WHERE slug = ?");
        $upd->execute([$body['enabled'] ? 1 : 0, $this->ctx->isoNow(), $slug]);

        return [
            'slug' => $slug,
            'enabled' => $body['enabled'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminDeletePlayer(string $did): array
    {
        $counts = $this->purgePlayer($did);
        return ['did' => $did, 'purged' => $counts];
    }

    /**
     * @return array<string, mixed>
     */
    private function adminDeleteGameScores(string $slug): array
    {
        $pdo = $this->ctx->pdo();
        $s = $pdo->prepare("DELETE FROM {$this->tScores} WHERE game_slug = ?");
        $s->execute([$slug]);
        $p = $pdo->prepare("DELETE FROM {$this->tPlays} WHERE game_slug = ?");
        $p->execute([$slug]);
        $se = $pdo->prepare("DELETE FROM {$this->tSessions} WHERE game_slug = ?");
        $se->execute([$slug]);
        return [
            'gameSlug' => $slug,
            'deletedScores' => $s->rowCount(),
            'deletedPlays' => $p->rowCount(),
        ];
    }

    /**
     * @return array{scores: int, plays: int, sessions: int}
     */
    private function purgePlayer(string $did): array
    {
        if ($did === '') {
            return ['scores' => 0, 'plays' => 0, 'sessions' => 0];
        }
        $pdo = $this->ctx->pdo();
        $s = $pdo->prepare("DELETE FROM {$this->tScores} WHERE did = ?");
        $s->execute([$did]);
        $p = $pdo->prepare("DELETE FROM {$this->tPlays} WHERE did = ?");
        $p->execute([$did]);
        $se = $pdo->prepare("DELETE FROM {$this->tSessions} WHERE did = ?");
        $se->execute([$did]);
        // Exact matches only: rows written since schema v2 carry the DID; older rows used the keys
        // `session:<did>` and `score:<did>`.
        $li = $pdo->prepare("DELETE FROM {$this->tLimits} WHERE did = ? OR rate_key IN (?, ?)");
        $li->execute([$did, 'session:' . $did, 'score:' . $did]);
        return [
            'scores' => $s->rowCount(),
            'plays' => $p->rowCount(),
            'sessions' => $se->rowCount(),
        ];
    }

    private function prunePlayerGameRuns(string $slug, string $did): void
    {
        $maxRuns = $this->settingInt('max_runs_per_player_game', 100, 5, 10000);
        $pdo = $this->ctx->pdo();
        $stmt = $pdo->prepare("
            SELECT id FROM {$this->tPlays}
            WHERE game_slug = ? AND did = ?
            ORDER BY finished_at DESC, id DESC
            LIMIT 10000 OFFSET ?
        ");
        $stmt->bindValue(1, $slug);
        $stmt->bindValue(2, $did);
        $stmt->bindValue(3, $maxRuns, PDO::PARAM_INT);
        $stmt->execute();
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($ids) > 0) {
            $placeholders = implode(',', array_fill(0, count($ids), '?'));
            $del = $pdo->prepare("DELETE FROM {$this->tPlays} WHERE id IN ({$placeholders})");
            $del->execute(array_map('intval', $ids));
        }
    }

    private function pruneExpiredAndCapped(): void
    {
        $pdo = $this->ctx->pdo();
        $nowIso = $this->ctx->isoNow();
        $delSess = $pdo->prepare("DELETE FROM {$this->tSessions} WHERE expires_at < ?");
        $delSess->execute([$nowIso]);

        $days = $this->settingInt('history_retention_days', 365, 1, 3650);
        $cutoffIso = gmdate('Y-m-d\TH:i:s\Z', time() - ($days * 86400));
        $delPlays = $pdo->prepare("DELETE FROM {$this->tPlays} WHERE finished_at < ?");
        $delPlays->execute([$cutoffIso]);

        $delLimits = $pdo->prepare("DELETE FROM {$this->tLimits} WHERE window_start < ?");
        $delLimits->execute([time() - 7200]);
    }

    /**
     * Fixed-window counter in the extension's own table. `$did` is recorded on the row (when the
     * key concerns one player) so `purgePlayer` can delete exactly that player's rows.
     */
    private function checkLimit(string $rateKey, int $windowSec, int $maxHits, string $did = ''): bool
    {
        $pdo = $this->ctx->pdo();
        $now = time();
        $cutoff = $now - $windowSec;
        $stmt = $pdo->prepare("SELECT window_start, hits FROM {$this->tLimits} WHERE rate_key = ?");
        $stmt->execute([$rateKey]);
        $row = $stmt->fetch();

        if (!is_array($row) || (int) $row['window_start'] <= $cutoff) {
            if ($this->ctx->driver() === 'sqlite') {
                $ins = $pdo->prepare("
                    INSERT INTO {$this->tLimits} (rate_key, window_start, hits, did) VALUES (?, ?, 1, ?)
                    ON CONFLICT(rate_key) DO UPDATE SET window_start = excluded.window_start, hits = 1, did = excluded.did
                ");
            } else {
                $ins = $pdo->prepare("
                    INSERT INTO {$this->tLimits} (rate_key, window_start, hits, did) VALUES (?, ?, 1, ?)
                    ON DUPLICATE KEY UPDATE window_start = VALUES(window_start), hits = 1, did = VALUES(did)
                ");
            }
            $ins->execute([$rateKey, $now, $did]);
            return true;
        }

        if ((int) $row['hits'] >= $maxHits) {
            return false;
        }
        $upd = $pdo->prepare("UPDATE {$this->tLimits} SET hits = hits + 1 WHERE rate_key = ?");
        $upd->execute([$rateKey]);
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireActiveGameRow(string $slug): array
    {
        $stmt = $this->ctx->pdo()->prepare("SELECT * FROM {$this->tGames} WHERE slug = ? AND present = 1 AND enabled = 1");
        $stmt->execute([$slug]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new AppError(404, 'not_found', 'No such active game.');
        }
        return $row;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireActivePlayer(string $did): array
    {
        if ($did === '') {
            throw new AppError(400, 'invalid_did', 'A listed Webring DID is required.');
        }
        $entry = $this->ctx->entryByDid($did);
        if (!is_array($entry) || ($entry['status'] ?? '') !== 'active') {
            throw new AppError(404, 'player_not_found', 'That DID is not an active entry on this hub.');
        }
        return $entry;
    }

    private function createTables(PDO $pdo, string $driver): void
    {
        if ($driver === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS {$this->tGames} (
                    slug TEXT PRIMARY KEY,
                    manifest_json TEXT NOT NULL,
                    manifest_hash TEXT NOT NULL,
                    title TEXT NOT NULL,
                    description TEXT NOT NULL,
                    tags_text TEXT NOT NULL DEFAULT '',
                    version TEXT NOT NULL DEFAULT '1.0.0',
                    entry TEXT NOT NULL DEFAULT 'index.html',
                    thumbnail TEXT NOT NULL DEFAULT '',
                    present INTEGER NOT NULL DEFAULT 1,
                    enabled INTEGER NOT NULL DEFAULT 1,
                    installed_at TEXT NOT NULL,
                    updated_at TEXT NOT NULL,
                    last_seen_at TEXT NOT NULL
                );
                CREATE TABLE IF NOT EXISTS {$this->tScores} (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    game_slug TEXT NOT NULL,
                    did TEXT NOT NULL,
                    score INTEGER NOT NULL,
                    duration_ms INTEGER NOT NULL DEFAULT 0,
                    achieved_at TEXT NOT NULL,
                    play_id INTEGER NOT NULL DEFAULT 0,
                    verified INTEGER NOT NULL DEFAULT 0,
                    UNIQUE (game_slug, did)
                );
                CREATE INDEX IF NOT EXISTS idx_{$this->tScores}_game_score ON {$this->tScores}(game_slug, score);
                CREATE INDEX IF NOT EXISTS idx_{$this->tScores}_did ON {$this->tScores}(did);
                CREATE TABLE IF NOT EXISTS {$this->tPlays} (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    game_slug TEXT NOT NULL,
                    did TEXT NOT NULL,
                    score INTEGER NOT NULL,
                    duration_ms INTEGER NOT NULL DEFAULT 0,
                    started_at TEXT NOT NULL,
                    finished_at TEXT NOT NULL,
                    meta_json TEXT
                );
                CREATE INDEX IF NOT EXISTS idx_{$this->tPlays}_game_score ON {$this->tPlays}(game_slug, score);
                CREATE INDEX IF NOT EXISTS idx_{$this->tPlays}_did_fin ON {$this->tPlays}(did, finished_at);
                CREATE INDEX IF NOT EXISTS idx_{$this->tPlays}_game_did ON {$this->tPlays}(game_slug, did);
                CREATE TABLE IF NOT EXISTS {$this->tSessions} (
                    id_hash TEXT PRIMARY KEY,
                    game_slug TEXT NOT NULL,
                    did TEXT NOT NULL,
                    created_at TEXT NOT NULL,
                    expires_at TEXT NOT NULL,
                    used_at TEXT
                );
                CREATE INDEX IF NOT EXISTS idx_{$this->tSessions}_did ON {$this->tSessions}(did);
                CREATE INDEX IF NOT EXISTS idx_{$this->tSessions}_exp ON {$this->tSessions}(expires_at);
                CREATE TABLE IF NOT EXISTS {$this->tLimits} (
                    rate_key TEXT PRIMARY KEY,
                    window_start INTEGER NOT NULL,
                    hits INTEGER NOT NULL
                );
            ");
            return;
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tGames} (
                slug VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                manifest_json MEDIUMTEXT NOT NULL,
                manifest_hash CHAR(64) NOT NULL,
                title VARCHAR(160) NOT NULL,
                description TEXT NOT NULL,
                tags_text VARCHAR(400) NOT NULL DEFAULT '',
                version VARCHAR(40) NOT NULL DEFAULT '1.0.0',
                entry VARCHAR(255) NOT NULL DEFAULT 'index.html',
                thumbnail VARCHAR(255) NOT NULL DEFAULT '',
                present TINYINT(1) NOT NULL DEFAULT 1,
                enabled TINYINT(1) NOT NULL DEFAULT 1,
                installed_at VARCHAR(40) NOT NULL,
                updated_at VARCHAR(40) NOT NULL,
                last_seen_at VARCHAR(40) NOT NULL,
                PRIMARY KEY (slug)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tScores} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                game_slug VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                did VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                score BIGINT NOT NULL,
                duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
                achieved_at VARCHAR(40) NOT NULL,
                play_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
                verified TINYINT(1) NOT NULL DEFAULT 0,
                PRIMARY KEY (id),
                UNIQUE KEY uq_game_did (game_slug, did),
                KEY idx_game_score (game_slug, score),
                KEY idx_did (did)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tPlays} (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                game_slug VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                did VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                score BIGINT NOT NULL,
                duration_ms INT UNSIGNED NOT NULL DEFAULT 0,
                started_at VARCHAR(40) NOT NULL,
                finished_at VARCHAR(40) NOT NULL,
                meta_json TEXT DEFAULT NULL,
                PRIMARY KEY (id),
                KEY idx_plays_game_score (game_slug, score),
                KEY idx_plays_did_fin (did, finished_at),
                KEY idx_plays_game_did (game_slug, did)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tSessions} (
                id_hash CHAR(64) NOT NULL,
                game_slug VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                did VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                created_at VARCHAR(40) NOT NULL,
                expires_at VARCHAR(40) NOT NULL,
                used_at VARCHAR(40) DEFAULT NULL,
                PRIMARY KEY (id_hash),
                KEY idx_sess_did (did),
                KEY idx_sess_exp (expires_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tLimits} (
                rate_key VARCHAR(320) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                window_start BIGINT NOT NULL,
                hits INT UNSIGNED NOT NULL,
                PRIMARY KEY (rate_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    }
}
