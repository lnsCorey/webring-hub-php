<?php
declare(strict_types=1);

namespace WebringHub\Ext\Social;

use PDO;
use WebringHub\AppError;
use WebringHub\ExtensionContext;
use WebringHub\ExtensionRequest;

/**
 * Tier 4 deeper-structure features for `social`:
 *
 * - **T4.1 Topic channels:** tag index (`item_tags`) derived from members' signed posts, admin-curated
 *   channels (`extension_settings.social.channels`, plus optional open channels), and opt-in follows.
 * - **T4.3 Community picks / shared library:** de-duplicated `share` and `recommendation` URLs with
 *   opt-in upvotes (`pick_votes`) and weekly reading list in the digest. Never fetches remote URLs.
 * - **T4.4 "Now" status wall:** reads `entries.now_text` / `now_updated_at` (hidden after 30 days).
 * - **T4.5 Group challenges:** admin-defined timed prompts matched by tag via `item_tags`, optional
 *   Tier 2 poll voting on close, `ext.social.challenge_closed` event, and `participant`/`champion` badges.
 */
trait Structure
{
    // Constants live on SocialExtension (constants in traits need PHP 8.2).
    private string $tItemTags = '';
    private string $tFollows = '';
    private string $tPickVotes = '';
    private string $tChallenges = '';
    private bool $structureReady = false;

    private function registerStructure(ExtensionContext $ctx): void
    {
        $this->tItemTags = $ctx->table('item_tags');
        $this->tFollows = $ctx->table('follows');
        $this->tPickVotes = $ctx->table('pick_votes');
        $this->tChallenges = $ctx->table('challenges');

        $ctx->migrate(4, function (PDO $pdo, string $driver): void {
            $this->createStructureTables($pdo, $driver);
            $this->structureReady = true;
            $this->reindexItemTags();
        });
        $this->structureReady = $ctx->schemaVersion() >= 4;

        // T4.1 Channels & follows
        $ctx->get('v1/channels', fn (array $p, ExtensionRequest $req) => $this->handleChannels($req));
        $ctx->get('v1/me/follows', fn (array $p, ExtensionRequest $req) => $this->handleGetFollows($req), ['userAuth' => true]);
        $ctx->post('v1/follows', fn (array $p, ExtensionRequest $req) => $this->handleSetFollow($req), ['requireOptIn' => true]);

        // T4.3 Community picks / library
        $ctx->get('v1/library', fn (array $p, ExtensionRequest $req) => $this->handleLibrary($req));
        $ctx->post('v1/library/vote', fn (array $p, ExtensionRequest $req) => $this->handleLibraryVote($req), ['requireOptIn' => true]);

        // T4.4 Now status wall
        $ctx->get('v1/now', fn (array $p, ExtensionRequest $req) => $this->handleNowWall($req));

        // T4.5 Challenges
        $ctx->get('v1/challenges', fn (array $p, ExtensionRequest $req) => $this->handleChallenges($req));
        $ctx->get('v1/challenges/{id}', fn (array $p, ExtensionRequest $req) => $this->handleChallengeDetail((string) $p['id'], $req));
        $ctx->get('v1/admin/challenges', fn () => $this->handleAdminChallenges(), ['admin' => true]);
        $ctx->post('v1/admin/challenges', fn (array $p, ExtensionRequest $req) => $this->handleAdminCreateChallenge($req), ['admin' => true]);
        $ctx->post('v1/admin/challenges/{id}/close', fn (array $p) => $this->closeChallenge((string) $p['id']), ['admin' => true]);
        $ctx->delete('v1/admin/challenges/{id}', fn (array $p) => $this->deleteChallenge((string) $p['id']), ['admin' => true]);
    }

    // =========================================================================
    // Tag & URL normalisation
    // =========================================================================

    public static function normalizeChannelTag(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $clean = strtolower(trim(ltrim(trim($raw), '#')));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{0,31}$/', $clean)) {
            return null;
        }
        return $clean;
    }

    public static function normalizeLibraryUrl(string $raw): ?string
    {
        $u = trim($raw);
        if ($u === '' || strlen($u) > 2000 || !preg_match('#^https?://[^\s<>"\']+$#i', $u)) {
            return null;
        }
        $parts = parse_url($u);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return null;
        }
        $scheme = strtolower((string) $parts['scheme']);
        if ($scheme !== 'http' && $scheme !== 'https') {
            return null;
        }
        $host = strtolower((string) $parts['host']);
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');
        if ($path === '/') {
            $path = '';
        }
        $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
        return "{$scheme}://{$host}{$port}{$path}{$query}";
    }

    public static function urlHash(string $normalisedUrl): string
    {
        return hash('sha256', $normalisedUrl);
    }

    // =========================================================================
    // Indexing & purging
    // =========================================================================

    /** @param array<string, mixed> $row */
    private function indexItemTags(array $row): void
    {
        if (!$this->structureReady) {
            return;
        }
        $did = (string) $row['did'];
        $itemId = (string) $row['item_id'];
        $key = self::itemKey($did, $itemId);
        $publishedAt = (string) $row['published_at'];
        $item = json_decode((string) $row['raw_item_json'], true);
        $rawTags = is_array($item['tags'] ?? null) ? $item['tags'] : [];

        $tags = [];
        foreach ($rawTags as $t) {
            $norm = self::normalizeChannelTag($t);
            if ($norm !== null && !in_array($norm, $tags, true) && count($tags) < 20) {
                $tags[] = $norm;
            }
        }

        $pdo = $this->ctx->pdo();
        $pdo->prepare("DELETE FROM {$this->tItemTags} WHERE item_key = ?")->execute([$key]);
        if ($tags === []) {
            return;
        }
        $ins = $pdo->prepare(
            "INSERT INTO {$this->tItemTags} (item_key, item_did, item_id, tag, published_at) VALUES (?, ?, ?, ?, ?)"
        );
        foreach ($tags as $tag) {
            $ins->execute([$key, $did, $itemId, $tag, $publishedAt]);
        }
    }

    private function reindexItemTags(): void
    {
        if (!$this->structureReady) {
            return;
        }
        $offset = 0;
        do {
            $stmt = $this->ctx->pdo()->query(
                "SELECT did, item_id, published_at, raw_item_json FROM {$this->tItems}
                 ORDER BY did, item_id LIMIT 500 OFFSET {$offset}"
            );
            $rows = $stmt->fetchAll();
            foreach ($rows as $row) {
                $this->indexItemTags($row);
            }
            $offset += 500;
        } while (count($rows) === 500);
    }

    /** @return list<string> */
    private function structureItemTables(): array
    {
        return $this->structureReady ? [$this->tItemTags] : [];
    }

    private function purgeStructureUserData(string $did): void
    {
        if (!$this->structureReady || $did === '') {
            return;
        }
        $pdo = $this->ctx->pdo();
        $pdo->prepare("DELETE FROM {$this->tFollows} WHERE did = ?")->execute([$did]);
        $pdo->prepare("DELETE FROM {$this->tPickVotes} WHERE voter_did = ?")->execute([$did]);
    }

    // =========================================================================
    // T4.1 Channels & follows
    // =========================================================================

    /** @return list<array{tag: string, title: string, description: string, curated: bool}> */
    private function curatedChannels(): array
    {
        $raw = $this->ctx->config()['channels'] ?? [];
        if (!is_array($raw)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($raw as $c) {
            if (!is_array($c)) {
                continue;
            }
            $tag = self::normalizeChannelTag($c['tag'] ?? null);
            if ($tag === null || isset($seen[$tag])) {
                continue;
            }
            $seen[$tag] = true;
            $title = is_string($c['title'] ?? null) && trim($c['title']) !== '' ? mb_substr(trim($c['title']), 0, 60) : '#' . $tag;
            $desc = is_string($c['description'] ?? null) ? mb_substr(trim($c['description']), 0, 200) : '';
            $out[] = ['tag' => $tag, 'title' => $title, 'description' => $desc, 'curated' => true];
            if (count($out) >= 30) {
                break;
            }
        }
        return $out;
    }

    private function openChannelsEnabled(): bool
    {
        return !empty($this->ctx->config()['openChannels']);
    }

    /** @return list<string> */
    private function followedTagsFor(?string $did): array
    {
        if (!$this->structureReady || $did === null || $did === '') {
            return [];
        }
        $stmt = $this->ctx->pdo()->prepare("SELECT tag FROM {$this->tFollows} WHERE did = ? ORDER BY created_at ASC");
        $stmt->execute([$did]);
        return array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<string, mixed> */
    private function handleChannels(ExtensionRequest $req): array
    {
        $this->requireReader($req);
        $pdo = $this->ctx->pdo();
        $weekAgo = gmdate('Y-m-d\TH:i:s.000\Z', time() - 7 * 86400);

        $channels = [];
        $byTag = [];
        foreach ($this->curatedChannels() as $c) {
            $byTag[$c['tag']] = $c;
        }

        if ($this->openChannelsEnabled()) {
            $monthAgo = gmdate('Y-m-d\TH:i:s.000\Z', time() - 30 * 86400);
            $stmt = $pdo->prepare(
                "SELECT t.tag, COUNT(DISTINCT t.item_did) AS authors
                 FROM {$this->tItemTags} t
                 JOIN entries e ON e.did = t.item_did AND e.status = 'active'
                 WHERE t.published_at >= ?
                   AND NOT EXISTS (SELECT 1 FROM {$this->tHidden} h WHERE h.item_key = t.item_key)
                 GROUP BY t.tag HAVING COUNT(DISTINCT t.item_did) >= 3
                 ORDER BY authors DESC LIMIT 30"
            );
            $stmt->execute([$monthAgo]);
            foreach ($stmt->fetchAll() as $r) {
                $tag = (string) $r['tag'];
                if (!isset($byTag[$tag])) {
                    $byTag[$tag] = ['tag' => $tag, 'title' => '#' . $tag, 'description' => '', 'curated' => false];
                }
            }
        }

        $tags = array_keys($byTag);
        $counts = [];
        if ($tags !== []) {
            $ph = implode(',', array_fill(0, count($tags), '?'));
            $cStmt = $pdo->prepare(
                "SELECT t.tag, COUNT(*) AS n
                 FROM {$this->tItemTags} t
                 JOIN entries e ON e.did = t.item_did AND e.status = 'active'
                 WHERE t.tag IN ({$ph}) AND t.published_at >= ?
                   AND NOT EXISTS (SELECT 1 FROM {$this->tHidden} h WHERE h.item_key = t.item_key)
                 GROUP BY t.tag"
            );
            $cStmt->execute(array_merge($tags, [$weekAgo]));
            foreach ($cStmt->fetchAll() as $r) {
                $counts[(string) $r['tag']] = (int) $r['n'];
            }
        }

        $mine = array_flip($this->followedTagsFor($req->userDid));
        foreach ($byTag as $tag => $c) {
            $channels[] = [
                'tag' => $tag,
                'title' => $c['title'],
                'description' => $c['description'],
                'curated' => $c['curated'],
                'items7d' => $counts[$tag] ?? 0,
                'following' => isset($mine[$tag]),
            ];
        }
        return ['channels' => $channels];
    }

    /** @return array<string, mixed> */
    private function handleGetFollows(ExtensionRequest $req): array
    {
        return ['follows' => $this->followedTagsFor($req->userDid)];
    }

    /** @return array<string, mixed> */
    private function handleSetFollow(ExtensionRequest $req): array
    {
        $did = (string) $req->userDid;
        $body = $req->json();
        $tag = self::normalizeChannelTag($body['tag'] ?? null);
        if ($tag === null) {
            throw new AppError(400, 'invalid_tag', 'Channel tag must be 1–32 lowercase letters, digits or hyphens.');
        }
        $on = !array_key_exists('on', $body) || !empty($body['on']);
        $pdo = $this->ctx->pdo();
        if ($on) {
            $existing = $this->followedTagsFor($did);
            if (!in_array($tag, $existing, true) && count($existing) >= self::MAX_FOLLOWS_PER_MEMBER) {
                throw new AppError(400, 'follow_limit', 'You can follow at most ' . self::MAX_FOLLOWS_PER_MEMBER . ' channels.');
            }
            $sql = $this->ctx->driver() === 'sqlite'
                ? "INSERT OR IGNORE INTO {$this->tFollows} (did, tag, created_at) VALUES (?, ?, ?)"
                : "INSERT IGNORE INTO {$this->tFollows} (did, tag, created_at) VALUES (?, ?, ?)";
            $pdo->prepare($sql)->execute([$did, $tag, $this->ctx->isoNow()]);
        } else {
            $pdo->prepare("DELETE FROM {$this->tFollows} WHERE did = ? AND tag = ?")->execute([$did, $tag]);
        }
        return ['follows' => $this->followedTagsFor($did)];
    }

    // =========================================================================
    // T4.3 Community picks / shared library
    // =========================================================================

    /** @return array<string, mixed> */
    private function handleLibrary(ExtensionRequest $req): array
    {
        $this->requireReader($req);
        $days = max(0, min(365, (int) ($req->query['days'] ?? 0)));
        $limit = max(1, min(100, (int) ($req->query['limit'] ?? 40)));
        return ['picks' => $this->loadLibrary($days, $limit, $req->userDid)];
    }

    /** @return list<array<string, mixed>> */
    private function loadLibrary(int $days, int $limit, ?string $viewerDid): array
    {
        if (!$this->structureReady) {
            return [];
        }
        $pdo = $this->ctx->pdo();
        $sql = "SELECT i.did, i.item_id, i.content_type, i.target_url, i.published_at, i.raw_item_json,
                       e.display_name, e.handle, e.site_url
                FROM {$this->tItems} i
                JOIN entries e ON e.did = i.did AND e.status = 'active'
                WHERE i.content_type IN ('share', 'recommendation')
                  AND i.target_url IS NOT NULL AND i.target_url <> ''
                  AND NOT EXISTS (SELECT 1 FROM {$this->tHidden} h WHERE h.item_did = i.did AND h.item_id = i.item_id)";
        $args = [];
        if ($days > 0) {
            $sql .= ' AND i.published_at >= ?';
            $args[] = gmdate('Y-m-d\TH:i:s.000\Z', time() - $days * 86400);
        }
        $sql .= ' ORDER BY i.published_at DESC LIMIT 400';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);

        $byHash = [];
        foreach ($stmt->fetchAll() as $row) {
            $norm = self::normalizeLibraryUrl((string) $row['target_url']);
            if ($norm === null) {
                continue;
            }
            $h = self::urlHash($norm);
            $raw = json_decode((string) $row['raw_item_json'], true);
            $raw = is_array($raw) ? $raw : [];
            $title = is_string($raw['title'] ?? null) && trim($raw['title']) !== '' ? mb_substr(trim($raw['title']), 0, 200) : $norm;
            $note = is_string($raw['commentary'] ?? null) ? mb_substr(trim($raw['commentary']), 0, 300) : '';
            if (!isset($byHash[$h])) {
                $byHash[$h] = [
                    'urlHash' => $h,
                    'url' => $norm,
                    'title' => $title,
                    'excerpt' => $note,
                    'latestAt' => (string) $row['published_at'],
                    'sharers' => [],
                    'votes' => 0,
                    'myVote' => false,
                ];
            }
            $sharerDid = (string) $row['did'];
            if (!isset($byHash[$h]['sharers'][$sharerDid])) {
                $byHash[$h]['sharers'][$sharerDid] = [
                    'did' => $sharerDid,
                    'displayName' => (string) $row['display_name'],
                    'itemId' => (string) $row['item_id'],
                    'type' => (string) $row['content_type'],
                ];
            }
        }
        if ($byHash === []) {
            return [];
        }

        $hashes = array_keys($byHash);
        foreach (array_chunk($hashes, 200) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $vSql = "SELECT v.url_hash, COUNT(*) AS n
                     FROM {$this->tPickVotes} v
                     JOIN entries e ON e.did = v.voter_did AND e.status = 'active'
                     WHERE v.url_hash IN ({$ph})" . ($days > 0 ? ' AND v.created_at >= ?' : '') . ' GROUP BY v.url_hash';
            $vArgs = $days > 0 ? array_merge($chunk, [gmdate('Y-m-d\TH:i:s.000\Z', time() - $days * 86400)]) : $chunk;
            $vs = $pdo->prepare($vSql);
            $vs->execute($vArgs);
            foreach ($vs->fetchAll() as $r) {
                $byHash[(string) $r['url_hash']]['votes'] = (int) $r['n'];
            }
            if ($viewerDid !== null) {
                $ms = $pdo->prepare("SELECT url_hash FROM {$this->tPickVotes} WHERE voter_did = ? AND url_hash IN ({$ph})");
                $ms->execute(array_merge([$viewerDid], $chunk));
                foreach ($ms->fetchAll(PDO::FETCH_COLUMN) as $mh) {
                    $byHash[(string) $mh]['myVote'] = true;
                }
            }
        }

        $list = array_values(array_map(static function (array $p): array {
            $p['sharers'] = array_values($p['sharers']);
            $p['shareCount'] = count($p['sharers']);
            return $p;
        }, $byHash));

        usort($list, static fn (array $a, array $b): int => [$b['votes'], $b['shareCount'], $b['latestAt']] <=> [$a['votes'], $a['shareCount'], $a['latestAt']]);
        return array_slice($list, 0, $limit);
    }

    /** @return array<string, mixed> */
    private function handleLibraryVote(ExtensionRequest $req): array
    {
        $voter = (string) $req->userDid;
        $body = $req->json();
        $norm = self::normalizeLibraryUrl(is_string($body['url'] ?? null) ? $body['url'] : '');
        if ($norm === null) {
            throw new AppError(400, 'invalid_url', 'Provide a valid http(s) URL.');
        }
        $on = !array_key_exists('on', $body) || !empty($body['on']);
        if (!$this->checkLimit("pick:{$voter}", 3600, self::VOTES_PER_HOUR, $voter)) {
            throw new AppError(429, 'rate_limited', 'Too many votes. Try again later.');
        }
        $hash = self::urlHash($norm);
        $pdo = $this->ctx->pdo();
        if ($on) {
            $sql = $this->ctx->driver() === 'sqlite'
                ? "INSERT OR IGNORE INTO {$this->tPickVotes} (url_hash, target_url, voter_did, created_at) VALUES (?, ?, ?, ?)"
                : "INSERT IGNORE INTO {$this->tPickVotes} (url_hash, target_url, voter_did, created_at) VALUES (?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([$hash, $norm, $voter, $this->ctx->isoNow()]);
        } else {
            $pdo->prepare("DELETE FROM {$this->tPickVotes} WHERE url_hash = ? AND voter_did = ?")->execute([$hash, $voter]);
        }
        $c = $pdo->prepare(
            "SELECT COUNT(*) FROM {$this->tPickVotes} v JOIN entries e ON e.did = v.voter_did AND e.status = 'active' WHERE v.url_hash = ?"
        );
        $c->execute([$hash]);
        return ['url' => $norm, 'urlHash' => $hash, 'votes' => (int) $c->fetchColumn(), 'myVote' => $on];
    }

    // =========================================================================
    // T4.4 "Now" status wall
    // =========================================================================

    /** @return array<string, mixed> */
    private function handleNowWall(ExtensionRequest $req): array
    {
        $this->requireReader($req);
        return ['entries' => $this->loadNowWall(50)];
    }

    /** @return list<array<string, mixed>> */
    private function loadNowWall(int $limit = 50): array
    {
        $cutoff = gmdate('Y-m-d\TH:i:s.000\Z', time() - 30 * 86400);
        try {
            $stmt = $this->ctx->pdo()->prepare(
                "SELECT did, display_name, handle, site_url, avatar_url, now_text, now_updated_at
                 FROM entries
                 WHERE status = 'active' AND now_text <> '' AND now_updated_at IS NOT NULL AND now_updated_at >= ?
                 ORDER BY now_updated_at DESC LIMIT {$limit}"
            );
            $stmt->execute([$cutoff]);
            $rows = $stmt->fetchAll();
        } catch (\Throwable) {
            return [];
        }
        $dids = array_map(static fn (array $r): string => (string) $r['did'], $rows);
        [$roles, $badges] = $this->rolesAndBadgesFor($dids);
        $out = [];
        foreach ($rows as $r) {
            $d = (string) $r['did'];
            $out[] = [
                'did' => $d,
                'displayName' => (string) $r['display_name'],
                'handle' => (string) $r['handle'],
                'siteUrl' => (string) $r['site_url'],
                'avatarUrl' => preg_match('#^https://#i', (string) $r['avatar_url']) ? (string) $r['avatar_url'] : '',
                'roles' => $roles[$d] ?? [],
                'badges' => $badges[$d] ?? [],
                'now' => [
                    'text' => (string) $r['now_text'],
                    'updatedAt' => (string) $r['now_updated_at'],
                ],
            ];
        }
        return $out;
    }

    // =========================================================================
    // T4.5 Group challenges
    // =========================================================================

    /** @return array<string, mixed> */
    private function handleChallenges(ExtensionRequest $req): array
    {
        $this->requireReader($req);
        $now = $this->ctx->isoNow();
        $rows = $this->ctx->pdo()->query("SELECT * FROM {$this->tChallenges} ORDER BY starts_at DESC LIMIT 100")->fetchAll();
        $active = [];
        $upcoming = [];
        $past = [];
        foreach ($rows as $r) {
            $shaped = $this->shapeChallenge($r);
            if ($shaped['status'] === 'active') {
                $active[] = $shaped;
            } elseif ($shaped['status'] === 'upcoming') {
                $upcoming[] = $shaped;
            } else {
                $past[] = $shaped;
            }
        }
        return ['active' => $active, 'upcoming' => $upcoming, 'past' => $past, 'now' => $now];
    }

    /** @return array<string, mixed> */
    private function handleChallengeDetail(string $id, ExtensionRequest $req): array
    {
        $this->requireReader($req);
        $row = $this->challengeRow($id);
        $shaped = $this->shapeChallenge($row);
        $entries = $this->challengeEntries($row, $req->userDid);
        return ['challenge' => $shaped, 'entries' => $entries];
    }

    /** @return array<string, mixed> */
    private function handleAdminChallenges(): array
    {
        $rows = $this->ctx->pdo()->query("SELECT * FROM {$this->tChallenges} ORDER BY starts_at DESC LIMIT 100")->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $s = $this->shapeChallenge($r);
            $out[] = [
                'id' => $s['id'],
                'title' => $s['title'],
                'tag' => '#' . $s['tag'],
                'status' => $s['status'],
                'entries' => $s['entryCount'],
                'endsAt' => $s['endsAt'],
            ];
        }
        return ['challenges' => $out];
    }

    /** @return array<string, mixed> */
    private function handleAdminCreateChallenge(ExtensionRequest $req): array
    {
        $body = $req->json();
        $title = is_string($body['title'] ?? null) ? mb_substr(trim($body['title']), 0, 140) : '';
        $prompt = is_string($body['prompt'] ?? null) ? mb_substr(trim($body['prompt']), 0, 1000) : '';
        $tag = self::normalizeChannelTag($body['tag'] ?? null);
        $startsAt = self::isoUtc($body['startsAt'] ?? null) ?: $this->ctx->isoNow();
        $endsAt = self::isoUtc($body['endsAt'] ?? null);
        if ($endsAt === null) {
            $days = max(1, min(90, (int) ($body['durationDays'] ?? 7)));
            $endsAt = gmdate('Y-m-d\TH:i:s.000\Z', (int) strtotime($startsAt) + $days * 86400);
        }
        $voting = ($body['voting'] ?? 'none') === 'poll' ? 'poll' : 'none';
        if ($title === '' || $tag === null || $endsAt <= $startsAt) {
            throw new AppError(400, 'invalid_challenge', 'Provide a title, a valid tag, and an end date after the start.');
        }
        $now = $this->ctx->isoNow();
        $pdo = $this->ctx->pdo();
        $pdo->prepare(
            "INSERT INTO {$this->tChallenges} (title, prompt, tag, starts_at, ends_at, voting, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        )->execute([$title, $prompt, $tag, $startsAt, $endsAt, $voting, $now]);
        $id = (int) $pdo->lastInsertId();
        return [201, ['challenge' => $this->shapeChallenge($this->challengeRow((string) $id))]];
    }

    /** @return array<string, mixed> */
    private function closeChallenge(string $id): array
    {
        $row = $this->challengeRow($id);
        $pdo = $this->ctx->pdo();
        $now = $this->ctx->isoNow();
        $entries = $this->challengeEntries($row, null);

        // Sort entries by total reactions DESC, then publishedAt ASC
        usort($entries, static function (array $a, array $b): int {
            $ra = array_sum((array) ($a['reactions'] ?? []));
            $rb = array_sum((array) ($b['reactions'] ?? []));
            return [$rb, $a['publishedAt']] <=> [$ra, $b['publishedAt']];
        });

        $winnerDid = $entries[0]['did'] ?? null;
        $winnerItemId = $entries[0]['itemId'] ?? null;
        $pollId = $row['poll_id'] !== null ? (int) $row['poll_id'] : null;

        if ((string) $row['voting'] === 'poll' && $pollId === null && count($entries) >= 2 && $this->groupsReady) {
            $top = array_slice($entries, 0, self::MAX_POLL_OPTIONS);
            $pdo->prepare(
                "INSERT INTO {$this->tPolls} (question, description, multi, created_by, created_at, closes_at)
                 VALUES (?, ?, 0, 'admin', ?, ?)"
            )->execute([
                'Challenge vote: ' . (string) $row['title'],
                'Vote for your favourite entry in #' . (string) $row['tag'],
                $now,
                gmdate('Y-m-d\TH:i:s.000\Z', time() + 7 * 86400),
            ]);
            $pollId = (int) $pdo->lastInsertId();
            $optIns = $pdo->prepare("INSERT INTO {$this->tPollOptions} (poll_id, position, label) VALUES (?, ?, ?)");
            foreach ($top as $idx => $en) {
                $lbl = mb_substr(($en['author']['displayName'] ?: $en['did']) . ': ' . ($en['title'] ?: self::excerpt(['commentary' => $en['commentary']])), 0, 120);
                $optIns->execute([$pollId, $idx, $lbl]);
            }
        }

        $pdo->prepare(
            "UPDATE {$this->tChallenges} SET closed_at = ?, poll_id = ?, winner_did = ?, winner_item_id = ? WHERE id = ?"
        )->execute([$now, $pollId, $winnerDid, $winnerItemId, (int) $row['id']]);

        $this->ctx->emit('challenge_closed', [
            'challengeId' => (int) $row['id'],
            'title' => (string) $row['title'],
            'tag' => (string) $row['tag'],
            'pollId' => $pollId,
            'winnerDid' => $winnerDid,
            'winnerItemId' => $winnerItemId,
        ]);
        return ['status' => 'closed', 'pollId' => $pollId, 'winnerDid' => $winnerDid];
    }

    /** @return array<string, mixed> */
    private function deleteChallenge(string $id): array
    {
        $row = $this->challengeRow($id);
        $this->ctx->pdo()->prepare("DELETE FROM {$this->tChallenges} WHERE id = ?")->execute([(int) $row['id']]);
        return ['status' => 'deleted'];
    }

    private function closeExpiredChallenges(): void
    {
        if (!$this->structureReady) {
            return;
        }
        $stmt = $this->ctx->pdo()->prepare(
            "SELECT id FROM {$this->tChallenges} WHERE closed_at IS NULL AND ends_at <= ?"
        );
        $stmt->execute([$this->ctx->isoNow()]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $this->closeChallenge((string) $id);
        }
    }

    /** Awards `participant` (3+ challenges entered) and `champion` (1+ challenge win) badges. */
    private function recomputeChallengeBadges(): void
    {
        if (!$this->structureReady || !$this->belongingReady) {
            return;
        }
        $pdo = $this->ctx->pdo();
        $now = $this->ctx->isoNow();
        $ins = $pdo->prepare(
            $this->ctx->driver() === 'sqlite'
                ? "INSERT OR IGNORE INTO {$this->tBadges} (did, badge, earned_at) VALUES (?, ?, ?)"
                : "INSERT IGNORE INTO {$this->tBadges} (did, badge, earned_at) VALUES (?, ?, ?)"
        );

        // participant: entered N+ distinct challenges
        $minPart = $this->badgeThreshold('participant', 3);
        $s = $pdo->query(
            "SELECT t.item_did FROM {$this->tItemTags} t
             JOIN {$this->tChallenges} c ON c.tag = t.tag AND t.published_at >= c.starts_at AND t.published_at <= c.ends_at
             JOIN entries e ON e.did = t.item_did AND e.status = 'active'
             GROUP BY t.item_did HAVING COUNT(DISTINCT c.id) >= {$minPart}"
        );
        foreach ($s->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $ins->execute([(string) $d, 'participant', $now]);
        }

        // champion: won a challenge
        $c = $pdo->query(
            "SELECT DISTINCT ch.winner_did FROM {$this->tChallenges} ch
             JOIN entries e ON e.did = ch.winner_did AND e.status = 'active'
             WHERE ch.winner_did IS NOT NULL AND ch.winner_did <> ''"
        );
        foreach ($c->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $ins->execute([(string) $d, 'champion', $now]);
        }
    }

    /** @return array<string, mixed> */
    private function challengeRow(string $id): array
    {
        if (!ctype_digit($id)) {
            throw new AppError(404, 'not_found', 'No such challenge.');
        }
        $stmt = $this->ctx->pdo()->prepare("SELECT * FROM {$this->tChallenges} WHERE id = ?");
        $stmt->execute([(int) $id]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new AppError(404, 'not_found', 'No such challenge.');
        }
        return $row;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shapeChallenge(array $row): array
    {
        $now = $this->ctx->isoNow();
        $closed = $row['closed_at'] !== null || (string) $row['ends_at'] <= $now;
        $status = $closed ? 'past' : ((string) $row['starts_at'] > $now ? 'upcoming' : 'active');
        $c = $this->ctx->pdo()->prepare(
            "SELECT COUNT(DISTINCT t.item_key) FROM {$this->tItemTags} t
             JOIN entries e ON e.did = t.item_did AND e.status = 'active'
             JOIN {$this->tItems} i ON i.did = t.item_did AND i.item_id = t.item_id AND i.content_type = 'post'
             WHERE t.tag = ? AND t.published_at >= ? AND t.published_at <= ?
               AND NOT EXISTS (SELECT 1 FROM {$this->tHidden} h WHERE h.item_key = t.item_key)"
        );
        $c->execute([(string) $row['tag'], (string) $row['starts_at'], (string) $row['ends_at']]);
        return [
            'id' => (int) $row['id'],
            'title' => (string) $row['title'],
            'prompt' => (string) $row['prompt'],
            'tag' => (string) $row['tag'],
            'startsAt' => (string) $row['starts_at'],
            'endsAt' => (string) $row['ends_at'],
            'voting' => (string) $row['voting'],
            'status' => $status,
            'pollId' => $row['poll_id'] !== null ? (int) $row['poll_id'] : null,
            'winnerDid' => $row['winner_did'] !== null ? (string) $row['winner_did'] : null,
            'winnerItemId' => $row['winner_item_id'] !== null ? (string) $row['winner_item_id'] : null,
            'entryCount' => (int) $c->fetchColumn(),
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return list<array<string, mixed>>
     */
    private function challengeEntries(array $row, ?string $viewerDid): array
    {
        $stmt = $this->ctx->pdo()->prepare(
            "SELECT i.*, e.site_url, e.handle, e.display_name, e.avatar_url
             FROM {$this->tItemTags} t
             JOIN {$this->tItems} i ON i.did = t.item_did AND i.item_id = t.item_id AND i.content_type = 'post'
             JOIN entries e ON e.did = i.did AND e.status = 'active'
             WHERE t.tag = ? AND t.published_at >= ? AND t.published_at <= ?
               AND NOT EXISTS (SELECT 1 FROM {$this->tHidden} h WHERE h.item_key = t.item_key)
             ORDER BY i.published_at DESC LIMIT 100"
        );
        $stmt->execute([(string) $row['tag'], (string) $row['starts_at'], (string) $row['ends_at']]);
        $items = array_map(fn (array $r) => $this->shapeItem($r), $stmt->fetchAll());
        $this->decorate($items, $viewerDid, true);
        return $items;
    }

    // =========================================================================
    // Schema (migration 4)
    // =========================================================================

    private function createStructureTables(PDO $pdo, string $driver): void
    {
        if ($driver === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS {$this->tItemTags} (
                  item_key TEXT NOT NULL,
                  item_did TEXT NOT NULL,
                  item_id TEXT NOT NULL,
                  tag TEXT NOT NULL,
                  published_at TEXT NOT NULL,
                  PRIMARY KEY (item_key, tag)
                );
                CREATE INDEX IF NOT EXISTS idx_{$this->tItemTags}_tag ON {$this->tItemTags}(tag, published_at);
                CREATE INDEX IF NOT EXISTS idx_{$this->tItemTags}_item_did ON {$this->tItemTags}(item_did);

                CREATE TABLE IF NOT EXISTS {$this->tFollows} (
                  did TEXT NOT NULL,
                  tag TEXT NOT NULL,
                  created_at TEXT NOT NULL,
                  PRIMARY KEY (did, tag)
                );
                CREATE INDEX IF NOT EXISTS idx_{$this->tFollows}_tag ON {$this->tFollows}(tag);

                CREATE TABLE IF NOT EXISTS {$this->tPickVotes} (
                  url_hash TEXT NOT NULL,
                  target_url TEXT NOT NULL,
                  voter_did TEXT NOT NULL,
                  created_at TEXT NOT NULL,
                  PRIMARY KEY (url_hash, voter_did)
                );
                CREATE INDEX IF NOT EXISTS idx_{$this->tPickVotes}_voter ON {$this->tPickVotes}(voter_did);
                CREATE INDEX IF NOT EXISTS idx_{$this->tPickVotes}_created ON {$this->tPickVotes}(created_at);

                CREATE TABLE IF NOT EXISTS {$this->tChallenges} (
                  id INTEGER PRIMARY KEY AUTOINCREMENT,
                  title TEXT NOT NULL,
                  prompt TEXT NOT NULL DEFAULT '',
                  tag TEXT NOT NULL,
                  starts_at TEXT NOT NULL,
                  ends_at TEXT NOT NULL,
                  voting TEXT NOT NULL DEFAULT 'none',
                  poll_id INTEGER DEFAULT NULL,
                  winner_did TEXT DEFAULT NULL,
                  winner_item_id TEXT DEFAULT NULL,
                  closed_at TEXT DEFAULT NULL,
                  created_at TEXT NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_{$this->tChallenges}_tag ON {$this->tChallenges}(tag);
            ");
            return;
        }

        $did = 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL';
        $key = 'CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL';
        $tagCol = 'VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL';
        $tail = 'ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tItemTags} (
              item_key {$key},
              item_did {$did},
              item_id {$did},
              tag {$tagCol},
              published_at VARCHAR(40) NOT NULL,
              PRIMARY KEY (item_key, tag),
              KEY idx_item_tags_tag (tag, published_at),
              KEY idx_item_tags_item_did (item_did)
            ) {$tail}
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tFollows} (
              did {$did},
              tag {$tagCol},
              created_at VARCHAR(40) NOT NULL,
              PRIMARY KEY (did, tag),
              KEY idx_follows_tag (tag)
            ) {$tail}
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tPickVotes} (
              url_hash {$key},
              target_url VARCHAR(2000) NOT NULL,
              voter_did {$did},
              created_at VARCHAR(40) NOT NULL,
              PRIMARY KEY (url_hash, voter_did),
              KEY idx_pick_votes_voter (voter_did),
              KEY idx_pick_votes_created (created_at)
            ) {$tail}
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tChallenges} (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              title VARCHAR(140) NOT NULL,
              prompt VARCHAR(1000) NOT NULL DEFAULT '',
              tag {$tagCol},
              starts_at VARCHAR(40) NOT NULL,
              ends_at VARCHAR(40) NOT NULL,
              voting VARCHAR(16) NOT NULL DEFAULT 'none',
              poll_id BIGINT UNSIGNED DEFAULT NULL,
              winner_did VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
              winner_item_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL,
              closed_at VARCHAR(40) DEFAULT NULL,
              created_at VARCHAR(40) NOT NULL,
              PRIMARY KEY (id),
              KEY idx_challenges_tag (tag)
            ) {$tail}
        ");
    }
}
