<?php
declare(strict_types=1);

namespace WebringHub\Ext\Social;

use PDO;
use WebringHub\AppError;
use WebringHub\Extension;
use WebringHub\ExtensionContext;
use WebringHub\ExtensionRequest;

require_once __DIR__ . '/Groups.php';

/**
 * Conversations & Reactions (`social`): group engagement between verified hub members.
 *
 * - **Conversations.** Threads are built from items `webhook-sync` collected from members' own
 *   signed `items.json`: an item whose `targetUrl` is another member's item `canonicalUrl` is a
 *   reply to it. Nothing about the content is stored here; it stays on members' sites.
 * - **Reactions.** One-tap reactions recorded by signed-in members who opted in.
 * - **Moderation.** Members report items; the admin hides, dismisses or restores. Hiding only
 *   removes an item from the hub's social pages, never from the member's site.
 * - **Mentions & activity.** Replies to, mentions of and reactions on a member's items, pulled
 *   on demand (no inbox).
 *
 * Reads `webhook-sync`'s items table (declared in `reads`) and never writes to it.
 */
final class SocialExtension implements Extension
{
    use Groups;

    public const RSVP_STATUSES = ['going', 'maybe', 'declined'];
    public const MAX_POLL_OPTIONS = 8;
    public const POLLS_PER_DAY = 5;
    public const RSVPS_PER_HOUR = 60;
    public const VOTES_PER_HOUR = 60;
    public const DEFAULT_REACTIONS = ['like', 'heart', 'celebrate', 'think'];
    public const REPORT_REASONS = ['spam', 'abuse', 'off-topic', 'other'];
    public const MAX_THREAD_DEPTH = 6;
    public const MAX_THREAD_NODES = 300;
    public const MAX_MENTIONS_PER_ITEM = 20;
    public const REACTIONS_PER_HOUR = 120;
    public const REPORTS_PER_DAY = 20;
    public const RECONCILE_DAYS = 30;

    private ExtensionContext $ctx;
    private string $tItems = '';
    private string $tReactions = '';
    private string $tMentions = '';
    private string $tReports = '';
    private string $tHidden = '';
    private string $tSeen = '';
    private string $tLimits = '';

    /** @var array<string, list<string>>|null lowercased handle => DIDs of active entries */
    private ?array $handleMap = null;

    public function register(ExtensionContext $ctx): void
    {
        $this->ctx = $ctx;
        $this->tItems = $ctx->readTable('webhook-sync', 'items');
        $this->tReactions = $ctx->table('reactions');
        $this->tMentions = $ctx->table('mentions');
        $this->tReports = $ctx->table('reports');
        $this->tHidden = $ctx->table('hidden');
        $this->tSeen = $ctx->table('seen');
        $this->tLimits = $ctx->table('limits');

        $ctx->migrate(1, function (PDO $pdo, string $driver): void {
            $this->createTables($pdo, $driver);
            $this->reindexMentions(null);
        });
        $this->registerGroups($ctx);

        $ctx->serveFiles('', 'public', [
            'csp' => "default-src 'self'; img-src 'self' https: data:; style-src 'self'; script-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'",
            'frameable' => false,
        ]);

        $ctx->on('entry.removed', fn (array $e) => $this->purgeMember((string) ($e['did'] ?? '')));
        $ctx->on('entry.status_changed', function (array $e): void {
            if (($e['status'] ?? '') === 'blocked') {
                $this->purgeMember((string) ($e['did'] ?? ''));
            }
        });
        $ctx->on('user.extension_opt_out', function (array $e): void {
            if (($e['extensionId'] ?? '') === 'social') {
                $this->purgeUserData((string) ($e['did'] ?? ''));
            }
        });
        $ctx->on('ext.webhook-sync.items_changed', fn (array $e) => $this->onItemsChanged($e));
        $ctx->on('cron.refresh', function (): void {
            $this->pruneOrphans();
            $this->reindexMentions(gmdate('Y-m-d\TH:i:s.000\Z', time() - self::RECONCILE_DAYS * 86400));
        });

        $ctx->get('v1/feed', fn (array $p, ExtensionRequest $req) => $this->handleFeed($req));
        $ctx->get('v1/thread', fn (array $p, ExtensionRequest $req) => $this->handleThread($req));
        $ctx->get('v1/config', fn (array $p, ExtensionRequest $req) => $this->handleConfig($req));
        $ctx->post('v1/reactions', fn (array $p, ExtensionRequest $req) => $this->handleReaction($req), ['requireOptIn' => true]);
        $ctx->post('v1/reports', fn (array $p, ExtensionRequest $req) => $this->handleReport($req), ['requireOptIn' => true, 'rateLimited' => true]);
        $ctx->get('v1/me/activity', fn (array $p, ExtensionRequest $req) => $this->handleActivity($req), ['userAuth' => true]);
        $ctx->post('v1/me/activity/seen', fn (array $p, ExtensionRequest $req) => $this->handleSeen($req), ['requireOptIn' => true]);

        $ctx->get('v1/admin/summary', fn () => $this->handleAdminSummary(), ['admin' => true]);
        $ctx->get('v1/admin/reports', fn (array $p, ExtensionRequest $req) => $this->handleAdminReports($req), ['admin' => true]);
        $ctx->get('v1/admin/hidden', fn () => $this->handleAdminHidden(), ['admin' => true]);
        $ctx->post('v1/admin/reports/{id}/hide', fn (array $p) => $this->handleAdminHide((string) $p['id']), ['admin' => true]);
        $ctx->post('v1/admin/reports/{id}/dismiss', fn (array $p) => $this->handleAdminDismiss((string) $p['id']), ['admin' => true]);
        $ctx->post('v1/admin/hidden/{id}/restore', fn (array $p) => $this->handleAdminRestore((string) $p['id']), ['admin' => true]);
    }

    // =========================================================================
    // Settings
    // =========================================================================

    /** @return list<string> */
    private function reactionKeys(): array
    {
        $raw = $this->ctx->config()['reactions'] ?? null;
        if (!is_array($raw)) {
            return self::DEFAULT_REACTIONS;
        }
        $keys = [];
        foreach ($raw as $k) {
            if (is_string($k) && preg_match('/^[a-z][a-z0-9-]{0,20}$/', $k) && !in_array($k, $keys, true)) {
                $keys[] = $k;
            }
        }
        return $keys !== [] ? array_slice($keys, 0, 8) : self::DEFAULT_REACTIONS;
    }

    private function membersOnly(): bool
    {
        return !empty($this->ctx->config()['membersOnly']);
    }

    private function autoHideThreshold(): int
    {
        $v = $this->ctx->config()['autoHideReports'] ?? 3;
        return max(0, (int) $v);
    }

    private function requireReader(ExtensionRequest $req): void
    {
        if ($this->membersOnly() && $req->userDid === null) {
            throw new AppError(401, 'auth_required', 'This community is visible to signed-in members only. Sign in with your Webring Studio DID.');
        }
    }

    // =========================================================================
    // Public read routes
    // =========================================================================

    /** @return array<string, mixed> */
    private function handleConfig(ExtensionRequest $req): array
    {
        return [
            'reactions' => $this->reactionKeys(),
            'reportReasons' => self::REPORT_REASONS,
            'membersOnly' => $this->membersOnly(),
            'signedIn' => $req->userDid !== null,
            'optedIn' => $req->userOptedIn,
        ];
    }

    /** @return array<string, mixed> */
    private function handleFeed(ExtensionRequest $req): array
    {
        $this->requireReader($req);
        $limit = max(1, min(100, (int) ($req->query['limit'] ?? 30)));
        $type = is_string($req->query['type'] ?? null) ? trim($req->query['type']) : '';
        $before = is_string($req->query['before'] ?? null) ? trim($req->query['before']) : '';

        $owned = $this->ownedSql('p', 'pe');
        $sql = "SELECT i.*, e.site_url, e.handle, e.display_name, e.avatar_url
                FROM {$this->tItems} i
                JOIN entries e ON e.did = i.did
                WHERE e.status = 'active'
                  AND NOT EXISTS (
                    SELECT 1 FROM {$this->tItems} p JOIN entries pe ON pe.did = p.did
                    WHERE i.target_url IS NOT NULL AND p.canonical_url = i.target_url AND pe.status = 'active' AND {$owned}
                      AND NOT (p.did = i.did AND p.item_id = i.item_id)
                  )
                  AND NOT EXISTS (SELECT 1 FROM {$this->tHidden} h WHERE h.item_did = i.did AND h.item_id = i.item_id)";
        $args = [];
        if ($type !== '') {
            if (!in_array($type, ['post', 'like', 'share', 'recommendation', 'comment'], true)) {
                throw new AppError(400, 'invalid_type', 'Unknown item type.');
            }
            $sql .= ' AND i.content_type = ?';
            $args[] = $type;
        }
        if ($before !== '') {
            $parts = explode('|', $before, 3);
            if (count($parts) === 3) {
                $sql .= ' AND (i.published_at < ? OR (i.published_at = ? AND (i.did < ? OR (i.did = ? AND i.item_id < ?))))';
                array_push($args, $parts[0], $parts[0], $parts[1], $parts[1], $parts[2]);
            } else {
                $sql .= ' AND i.published_at < ?';
                $args[] = $before;
            }
        }
        $sql .= ' ORDER BY i.published_at DESC, i.did DESC, i.item_id DESC LIMIT ' . ($limit + 1);
        $stmt = $this->ctx->pdo()->prepare($sql);
        $stmt->execute($args);
        $rows = $stmt->fetchAll();

        $next = null;
        if (count($rows) > $limit) {
            $rows = array_slice($rows, 0, $limit);
            $last = $rows[$limit - 1];
            $next = $last['published_at'] . '|' . $last['did'] . '|' . $last['item_id'];
        }

        $items = array_map(fn (array $r) => $this->shapeItem($r), $rows);
        $this->decorate($items, $req->userDid, true);
        return ['items' => $items, 'nextCursor' => $next];
    }

    /** @return array<string, mixed> */
    private function handleThread(ExtensionRequest $req): array
    {
        $this->requireReader($req);
        $did = is_string($req->query['did'] ?? null) ? trim($req->query['did']) : '';
        $id = is_string($req->query['id'] ?? null) ? trim($req->query['id']) : '';
        $focus = $this->loadItem($did, $id);
        if ($focus === null) {
            throw new AppError(404, 'not_found', 'That item is not on this hub.');
        }

        // Climb to the conversation's root so a link to a reply shows the whole thread.
        $root = $focus;
        for ($i = 0; $i < self::MAX_THREAD_DEPTH; $i++) {
            $parent = $this->parentOf($root);
            if ($parent === null) {
                break;
            }
            $root = $parent;
        }

        // Breadth-first: a flat list of nodes with parent indices, assembled into a tree after.
        $flat = [$this->shapeItem($root)];
        $parentIdx = [0 => null];
        $seen = [$root['did'] . "
" . $root['item_id'] => true];
        $frontier = [];
        if ($this->ownsUrl((string) $root['site_url'], (string) $root['canonical_url'])) {
            $frontier[(string) $root['canonical_url']] = 0;
        }
        $truncated = false;
        for ($depth = 1; $frontier !== [] && !$truncated; $depth++) {
            if ($depth > self::MAX_THREAD_DEPTH) {
                $truncated = true;
                break;
            }
            $urls = array_map('strval', array_keys($frontier));
            $ph = implode(',', array_fill(0, count($urls), '?'));
            $stmt = $this->ctx->pdo()->prepare(
                "SELECT i.*, e.site_url, e.handle, e.display_name, e.avatar_url
                 FROM {$this->tItems} i JOIN entries e ON e.did = i.did
                 WHERE e.status = 'active' AND i.target_url IN ({$ph})
                 ORDER BY i.published_at ASC, i.did ASC, i.item_id ASC"
            );
            $stmt->execute($urls);
            $next = [];
            foreach ($stmt->fetchAll() as $row) {
                $seenKey = $row['did'] . "
" . $row['item_id'];
                if (isset($seen[$seenKey])) {
                    continue; // a cycle (an item replying to itself or to a descendant)
                }
                if (count($flat) >= self::MAX_THREAD_NODES) {
                    $truncated = true;
                    break;
                }
                $seen[$seenKey] = true;
                $idx = count($flat);
                $flat[$idx] = $this->shapeItem($row);
                $parentIdx[$idx] = $frontier[(string) $row['target_url']];
                $canon = (string) $row['canonical_url'];
                if (!isset($next[$canon]) && $this->ownsUrl((string) $row['site_url'], $canon)) {
                    $next[$canon] = $idx;
                }
            }
            $frontier = $next;
        }

        // Reactions, reply counts and hidden flags for every node in one pass.
        $this->decorate($flat, $req->userDid, false);
        $childIdx = [];
        foreach ($parentIdx as $idx => $parent) {
            if ($parent !== null) {
                $childIdx[$parent][] = $idx;
            }
        }
        $build = function (int $idx) use (&$build, &$flat, &$childIdx): array {
            $node = $flat[$idx];
            $node['children'] = array_map($build, $childIdx[$idx] ?? []);
            return $node;
        };
        $tree = $this->stripHidden($build(0));
        $count = count($flat);

        return [
            'root' => $tree,
            'focus' => ['did' => (string) $focus['did'], 'itemId' => (string) $focus['item_id']],
            'nodeCount' => $count,
            'truncated' => $truncated,
        ];
    }

    // =========================================================================
    // Member actions
    // =========================================================================

    /** @return array<string, mixed> */
    private function handleReaction(ExtensionRequest $req): array
    {
        $did = (string) $req->userDid;
        $body = $req->json();
        $itemDid = is_string($body['did'] ?? null) ? trim($body['did']) : '';
        $itemId = is_string($body['itemId'] ?? null) ? trim($body['itemId']) : '';
        $reaction = is_string($body['reaction'] ?? null) ? trim($body['reaction']) : '';
        $on = !array_key_exists('on', $body) || (bool) $body['on'];

        if (!in_array($reaction, $this->reactionKeys(), true)) {
            throw new AppError(400, 'invalid_reaction', 'That reaction is not available on this hub.');
        }
        $item = $this->loadItem($itemDid, $itemId);
        if ($item === null || $this->isHidden($itemDid, $itemId)) {
            throw new AppError(404, 'not_found', 'That item is not on this hub.');
        }
        if (!$this->checkLimit("react:{$did}", 3600, self::REACTIONS_PER_HOUR, $did)) {
            throw new AppError(429, 'rate_limited', 'You are reacting too quickly. Try again later.');
        }

        $key = self::itemKey($itemDid, $itemId);
        $pdo = $this->ctx->pdo();
        if ($on) {
            $ins = $this->ctx->driver() === 'sqlite'
                ? "INSERT OR IGNORE INTO {$this->tReactions} (item_key, item_did, item_id, reactor_did, reaction, created_at) VALUES (?, ?, ?, ?, ?, ?)"
                : "INSERT IGNORE INTO {$this->tReactions} (item_key, item_did, item_id, reactor_did, reaction, created_at) VALUES (?, ?, ?, ?, ?, ?)";
            $pdo->prepare($ins)->execute([$key, $itemDid, $itemId, $did, $reaction, $this->ctx->isoNow()]);
        } else {
            $pdo->prepare("DELETE FROM {$this->tReactions} WHERE item_key = ? AND reactor_did = ? AND reaction = ?")
                ->execute([$key, $did, $reaction]);
        }

        [$counts, $mine] = $this->reactionsFor([$key], $did);
        return [
            'did' => $itemDid,
            'itemId' => $itemId,
            'reactions' => (object) ($counts[$key] ?? []),
            'myReactions' => $mine[$key] ?? [],
        ];
    }

    /** @return array<string, mixed> */
    private function handleReport(ExtensionRequest $req): array
    {
        $did = (string) $req->userDid;
        $body = $req->json();
        $itemDid = is_string($body['did'] ?? null) ? trim($body['did']) : '';
        $itemId = is_string($body['itemId'] ?? null) ? trim($body['itemId']) : '';
        $reason = is_string($body['reason'] ?? null) ? trim($body['reason']) : '';
        $note = is_string($body['note'] ?? null) ? trim($body['note']) : '';

        if (!in_array($reason, self::REPORT_REASONS, true)) {
            throw new AppError(400, 'invalid_reason', 'Choose a reason: ' . implode(', ', self::REPORT_REASONS) . '.');
        }
        $note = mb_substr(preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $note) ?? '', 0, 500);
        if ($itemDid === $did) {
            throw new AppError(400, 'own_item', 'You cannot report your own item. Remove it from your site in Studio instead.');
        }
        if ($this->loadItem($itemDid, $itemId) === null) {
            throw new AppError(404, 'not_found', 'That item is not on this hub.');
        }
        if (!$this->checkLimit("report:{$did}", 86400, self::REPORTS_PER_DAY, $did)) {
            throw new AppError(429, 'rate_limited', 'You have sent too many reports today. Try again tomorrow.');
        }

        $key = self::itemKey($itemDid, $itemId);
        $pdo = $this->ctx->pdo();
        $exists = $pdo->prepare("SELECT 1 FROM {$this->tReports} WHERE item_key = ? AND reporter_did = ?");
        $exists->execute([$key, $did]);
        if ($exists->fetchColumn()) {
            throw new AppError(409, 'already_reported', 'You already reported this item. The hub admin will review it.');
        }
        $pdo->prepare(
            "INSERT INTO {$this->tReports} (item_key, item_did, item_id, reporter_did, reason, note, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'open', ?)"
        )->execute([$key, $itemDid, $itemId, $did, $reason, $note, $this->ctx->isoNow()]);

        $autoHidden = false;
        $threshold = $this->autoHideThreshold();
        if ($threshold > 0 && !$this->isHidden($itemDid, $itemId)) {
            $cnt = $pdo->prepare("SELECT COUNT(DISTINCT reporter_did) FROM {$this->tReports} WHERE item_key = ? AND status = 'open'");
            $cnt->execute([$key]);
            if ((int) $cnt->fetchColumn() >= $threshold) {
                $this->hideItem($itemDid, $itemId, 'auto');
                $autoHidden = true;
            }
        }

        return [
            'status' => 'reported',
            'autoHidden' => $autoHidden,
            'message' => $autoHidden
                ? 'Thanks. Several members reported this, so it is hidden until the hub admin reviews it.'
                : 'Thanks. The hub admin will review this report.',
        ];
    }

    /** @return array<string, mixed> */
    private function handleActivity(ExtensionRequest $req): array
    {
        $did = (string) $req->userDid;
        $limit = max(1, min(100, (int) ($req->query['limit'] ?? 50)));
        $before = is_string($req->query['before'] ?? null) ? trim($req->query['before']) : '';
        $me = $this->ctx->entryByDid($did);
        $siteUrl = is_array($me) ? rtrim((string) ($me['site_url'] ?? $me['siteUrl'] ?? ''), '/') : '';
        $pdo = $this->ctx->pdo();
        $events = [];

        // Replies (any item type) to my own items.
        if ($siteUrl !== '') {
            $sql = "SELECT c.*, ce.site_url, ce.handle, ce.display_name, ce.avatar_url,
                           p.item_id AS parent_item_id, p.raw_item_json AS parent_raw
                    FROM {$this->tItems} c
                    JOIN entries ce ON ce.did = c.did
                    JOIN {$this->tItems} p ON p.canonical_url = c.target_url
                    WHERE p.did = ? AND c.did <> ? AND ce.status = 'active'
                      AND " . $this->substrFn() . "(p.canonical_url, 1, ?) = ?
                      AND NOT EXISTS (SELECT 1 FROM {$this->tHidden} h WHERE h.item_did = c.did AND h.item_id = c.item_id)";
            $args = [$did, $did, strlen($siteUrl) + 1, $siteUrl . '/'];
            if ($before !== '') {
                $sql .= ' AND c.published_at < ?';
                $args[] = $before;
            }
            $sql .= ' ORDER BY c.published_at DESC LIMIT ' . $limit;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($args);
            foreach ($stmt->fetchAll() as $row) {
                $parent = json_decode((string) $row['parent_raw'], true);
                $events[] = [
                    'kind' => 'reply',
                    'at' => (string) $row['published_at'],
                    'item' => $this->shapeItem($row),
                    'target' => ['did' => $did, 'itemId' => (string) $row['parent_item_id'], 'excerpt' => self::excerpt(is_array($parent) ? $parent : [])],
                ];
            }
        }

        // Mentions of me.
        $sql = "SELECT i.*, e.site_url, e.handle, e.display_name, e.avatar_url, m.source AS mention_source
                FROM {$this->tMentions} m
                JOIN {$this->tItems} i ON i.did = m.item_did AND i.item_id = m.item_id
                JOIN entries e ON e.did = i.did
                WHERE m.mentioned_did = ? AND e.status = 'active'
                  AND NOT EXISTS (SELECT 1 FROM {$this->tHidden} h WHERE h.item_did = i.did AND h.item_id = i.item_id)";
        $args = [$did];
        if ($before !== '') {
            $sql .= ' AND i.published_at < ?';
            $args[] = $before;
        }
        $sql .= ' ORDER BY i.published_at DESC LIMIT ' . $limit;
        $stmt = $pdo->prepare($sql);
        $stmt->execute($args);
        foreach ($stmt->fetchAll() as $row) {
            $events[] = [
                'kind' => 'mention',
                'at' => (string) $row['published_at'],
                'source' => (string) $row['mention_source'],
                'item' => $this->shapeItem($row),
            ];
        }

        // Reactions from other members on my items, grouped per item.
        $sql = "SELECT r.item_id, r.reaction, COUNT(*) AS n, MAX(r.created_at) AS last_at, i.raw_item_json
                FROM {$this->tReactions} r
                JOIN {$this->tItems} i ON i.did = r.item_did AND i.item_id = r.item_id
                WHERE r.item_did = ? AND r.reactor_did <> ?
                GROUP BY r.item_id, r.reaction, i.raw_item_json";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([$did, $did]);
        $grouped = [];
        foreach ($stmt->fetchAll() as $row) {
            $iid = (string) $row['item_id'];
            if (!isset($grouped[$iid])) {
                $raw = json_decode((string) $row['raw_item_json'], true);
                $grouped[$iid] = [
                    'kind' => 'reactions',
                    'at' => '',
                    'target' => ['did' => $did, 'itemId' => $iid, 'excerpt' => self::excerpt(is_array($raw) ? $raw : [])],
                    'reactions' => [],
                ];
            }
            $grouped[$iid]['reactions'][(string) $row['reaction']] = (int) $row['n'];
            if ((string) $row['last_at'] > $grouped[$iid]['at']) {
                $grouped[$iid]['at'] = (string) $row['last_at'];
            }
        }
        foreach ($grouped as $g) {
            if ($before === '' || $g['at'] < $before) {
                $g['reactions'] = (object) $g['reactions'];
                $events[] = $g;
            }
        }

        usort($events, static fn (array $a, array $b): int => strcmp($b['at'], $a['at']));
        $events = array_slice($events, 0, $limit);

        $lastSeen = null;
        $unread = 0;
        if ($req->userOptedIn) {
            $s = $pdo->prepare("SELECT last_seen_at FROM {$this->tSeen} WHERE did = ?");
            $s->execute([$did]);
            $lastSeen = ($v = $s->fetchColumn()) !== false ? (string) $v : null;
            foreach ($events as $ev) {
                if ($lastSeen === null || $ev['at'] > $lastSeen) {
                    $unread++;
                }
            }
        }
        $nextBefore = count($events) === $limit ? $events[$limit - 1]['at'] : null;

        return ['events' => $events, 'unread' => $unread, 'lastSeenAt' => $lastSeen, 'nextBefore' => $nextBefore];
    }

    /** @return array<string, mixed> */
    private function handleSeen(ExtensionRequest $req): array
    {
        $did = (string) $req->userDid;
        $now = $this->ctx->isoNow();
        $sql = $this->ctx->driver() === 'sqlite'
            ? "INSERT INTO {$this->tSeen} (did, last_seen_at) VALUES (?, ?) ON CONFLICT(did) DO UPDATE SET last_seen_at = excluded.last_seen_at"
            : "INSERT INTO {$this->tSeen} (did, last_seen_at) VALUES (?, ?) ON DUPLICATE KEY UPDATE last_seen_at = VALUES(last_seen_at)";
        $this->ctx->pdo()->prepare($sql)->execute([$did, $now]);
        return ['lastSeenAt' => $now];
    }

    // =========================================================================
    // Admin
    // =========================================================================

    /** @return array<string, mixed> */
    private function handleAdminSummary(): array
    {
        $pdo = $this->ctx->pdo();
        $weekAgo = gmdate('Y-m-d\TH:i:s.000\Z', time() - 7 * 86400);
        $open = (int) $pdo->query("SELECT COUNT(DISTINCT item_key) FROM {$this->tReports} WHERE status = 'open'")->fetchColumn();
        $hidden = (int) $pdo->query("SELECT COUNT(*) FROM {$this->tHidden}")->fetchColumn();
        $r = $pdo->prepare("SELECT COUNT(*) FROM {$this->tReactions} WHERE created_at >= ?");
        $r->execute([$weekAgo]);
        $t = $pdo->prepare(
            "SELECT COUNT(DISTINCT c.target_url) FROM {$this->tItems} c
             JOIN {$this->tItems} p ON p.canonical_url = c.target_url
             WHERE c.content_type = 'comment' AND c.published_at >= ?"
        );
        $t->execute([$weekAgo]);
        return [
            'openReports' => $open,
            'hiddenItems' => $hidden,
            'reactions7d' => (int) $r->fetchColumn(),
            'activeThreads7d' => (int) $t->fetchColumn(),
        ];
    }

    /** @return array<string, mixed> */
    private function handleAdminReports(ExtensionRequest $req): array
    {
        $status = is_string($req->query['status'] ?? null) ? $req->query['status'] : 'open';
        if (!in_array($status, ['open', 'hidden', 'dismissed'], true)) {
            $status = 'open';
        }
        $stmt = $this->ctx->pdo()->prepare(
            "SELECT MIN(r.id) AS id, r.item_key, r.item_did, r.item_id, COUNT(*) AS report_count,
                    MIN(r.created_at) AS first_reported, GROUP_CONCAT(r.reason) AS reasons
             FROM {$this->tReports} r WHERE r.status = ?
             GROUP BY r.item_key, r.item_did, r.item_id
             ORDER BY first_reported ASC LIMIT 100"
        );
        $stmt->execute([$status]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $item = $this->loadItem((string) $row['item_did'], (string) $row['item_id'], false);
            $reasons = array_values(array_unique(explode(',', (string) $row['reasons'])));
            $out[] = [
                'id' => (int) $row['id'],
                'author' => $item !== null ? self::authorName($item) : (string) $row['item_did'],
                'did' => (string) $row['item_did'],
                'excerpt' => $item !== null ? self::excerpt(json_decode((string) $item['raw_item_json'], true) ?: []) : '(item no longer on the hub)',
                'reasons' => implode(', ', $reasons),
                'reportCount' => (int) $row['report_count'],
                'firstReported' => (string) $row['first_reported'],
                'visibility' => $this->isHidden((string) $row['item_did'], (string) $row['item_id']) ? 'hidden' : 'visible',
            ];
        }
        return ['reports' => $out];
    }

    /** @return array<string, mixed> */
    private function handleAdminHidden(): array
    {
        $rows = $this->ctx->pdo()->query("SELECT * FROM {$this->tHidden} ORDER BY hidden_at DESC LIMIT 100")->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $item = $this->loadItem((string) $row['item_did'], (string) $row['item_id'], false);
            $out[] = [
                'id' => (int) $row['id'],
                'author' => $item !== null ? self::authorName($item) : (string) $row['item_did'],
                'did' => (string) $row['item_did'],
                'excerpt' => $item !== null ? self::excerpt(json_decode((string) $item['raw_item_json'], true) ?: []) : '(item no longer on the hub)',
                'reason' => (string) $row['reason'],
                'hiddenAt' => (string) $row['hidden_at'],
            ];
        }
        return ['hidden' => $out];
    }

    /** @return array<string, mixed> */
    private function handleAdminHide(string $reportId): array
    {
        $report = $this->reportById($reportId);
        $this->hideItem((string) $report['item_did'], (string) $report['item_id'], 'admin');
        $this->resolveReports((string) $report['item_key'], 'hidden');
        return ['status' => 'hidden'];
    }

    /** @return array<string, mixed> */
    private function handleAdminDismiss(string $reportId): array
    {
        $report = $this->reportById($reportId);
        // Dismissing undoes an automatic hide; an admin's own hide stays until restored.
        $this->ctx->pdo()->prepare("DELETE FROM {$this->tHidden} WHERE item_key = ? AND reason = 'auto'")
            ->execute([(string) $report['item_key']]);
        $this->resolveReports((string) $report['item_key'], 'dismissed');
        return ['status' => 'dismissed'];
    }

    /** @return array<string, mixed> */
    private function handleAdminRestore(string $hiddenId): array
    {
        if (!ctype_digit($hiddenId)) {
            throw new AppError(404, 'not_found', 'No such hidden item.');
        }
        $stmt = $this->ctx->pdo()->prepare("DELETE FROM {$this->tHidden} WHERE id = ?");
        $stmt->execute([(int) $hiddenId]);
        if ($stmt->rowCount() === 0) {
            throw new AppError(404, 'not_found', 'No such hidden item.');
        }
        return ['status' => 'restored'];
    }

    /** @return array<string, mixed> */
    private function reportById(string $id): array
    {
        if (!ctype_digit($id)) {
            throw new AppError(404, 'not_found', 'No such report.');
        }
        $stmt = $this->ctx->pdo()->prepare("SELECT * FROM {$this->tReports} WHERE id = ?");
        $stmt->execute([(int) $id]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new AppError(404, 'not_found', 'No such report.');
        }
        return $row;
    }

    private function resolveReports(string $itemKey, string $status): void
    {
        $this->ctx->pdo()->prepare(
            "UPDATE {$this->tReports} SET status = ?, resolved_at = ? WHERE item_key = ? AND status = 'open'"
        )->execute([$status, $this->ctx->isoNow(), $itemKey]);
    }

    private function hideItem(string $itemDid, string $itemId, string $reason): void
    {
        $key = self::itemKey($itemDid, $itemId);
        $pdo = $this->ctx->pdo();
        if ($this->isHidden($itemDid, $itemId)) {
            if ($reason === 'admin') {
                $pdo->prepare("UPDATE {$this->tHidden} SET reason = 'admin' WHERE item_key = ?")->execute([$key]);
            }
            return;
        }
        $pdo->prepare("INSERT INTO {$this->tHidden} (item_key, item_did, item_id, reason, hidden_at) VALUES (?, ?, ?, ?, ?)")
            ->execute([$key, $itemDid, $itemId, $reason, $this->ctx->isoNow()]);
    }

    private function isHidden(string $itemDid, string $itemId): bool
    {
        $stmt = $this->ctx->pdo()->prepare("SELECT 1 FROM {$this->tHidden} WHERE item_key = ?");
        $stmt->execute([self::itemKey($itemDid, $itemId)]);
        return (bool) $stmt->fetchColumn();
    }

    // =========================================================================
    // Events: indexing and purging
    // =========================================================================

    /**
     * @param array<string, mixed> $e
     */
    private function onItemsChanged(array $e): void
    {
        $did = is_string($e['did'] ?? null) ? $e['did'] : '';
        if ($did === '') {
            return;
        }
        if (!empty($e['removedAll'])) {
            $this->deleteItemRows($did, null);
            return;
        }
        $removed = array_values(array_filter(is_array($e['removed'] ?? null) ? $e['removed'] : [], 'is_string'));
        if ($removed !== []) {
            $this->deleteItemRows($did, $removed);
        }
        $upserted = array_values(array_filter(is_array($e['upserted'] ?? null) ? $e['upserted'] : [], 'is_string'));
        foreach (array_chunk($upserted, 200) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->ctx->pdo()->prepare("SELECT did, item_id, content_type, published_at, raw_item_json FROM {$this->tItems} WHERE did = ? AND item_id IN ({$ph})");
            $stmt->execute(array_merge([$did], $chunk));
            foreach ($stmt->fetchAll() as $row) {
                $this->indexItem($row);
            }
        }
    }

    /**
     * Rebuilds the mention index for items published since `$sinceIso` (all items when null).
     */
    private function reindexMentions(?string $sinceIso): void
    {
        $this->handleMap = null;
        $pdo = $this->ctx->pdo();
        $offset = 0;
        do {
            $sql = "SELECT did, item_id, content_type, published_at, raw_item_json FROM {$this->tItems}"
                . ($sinceIso !== null ? ' WHERE published_at >= ?' : '')
                . ' ORDER BY did, item_id LIMIT 500 OFFSET ' . $offset;
            $stmt = $pdo->prepare($sql);
            $stmt->execute($sinceIso !== null ? [$sinceIso] : []);
            $rows = $stmt->fetchAll();
            foreach ($rows as $row) {
                $this->indexItem($row);
            }
            $offset += 500;
        } while (count($rows) === 500);
    }

    /**
     * Indexes one webhook-sync item: its mentions, and whether it is an event or an ask/offer.
     *
     * @param array<string, mixed> $row
     */
    private function indexItem(array $row): void
    {
        $this->indexMentions($row);
        $this->indexGroupItem($row);
    }

    /**
     * @param array<string, mixed> $row a webhook-sync items row (did, item_id, published_at, raw_item_json)
     */
    private function indexMentions(array $row): void
    {
        $did = (string) $row['did'];
        $itemId = (string) $row['item_id'];
        $key = self::itemKey($did, $itemId);
        $item = json_decode((string) $row['raw_item_json'], true);
        $found = is_array($item) ? $this->extractMentions($item, $did) : [];

        $pdo = $this->ctx->pdo();
        $pdo->prepare("DELETE FROM {$this->tMentions} WHERE item_key = ?")->execute([$key]);
        if ($found === []) {
            return;
        }
        $ins = $pdo->prepare(
            "INSERT INTO {$this->tMentions} (item_key, item_did, item_id, mentioned_did, source, published_at) VALUES (?, ?, ?, ?, ?, ?)"
        );
        foreach ($found as $mentioned => $source) {
            $ins->execute([$key, $did, $itemId, $mentioned, $source, (string) $row['published_at']]);
        }
    }

    /**
     * Members an item mentions: `did:web:` tokens, unambiguous `@handle`s and the target author.
     * Only active entries count, and an author never mentions themselves.
     *
     * @param array<string, mixed> $item
     * @return array<string, string> mentioned DID => source ('did' | 'handle' | 'target')
     */
    public function extractMentions(array $item, string $authorDid): array
    {
        $text = (is_string($item['title'] ?? null) ? $item['title'] : '') . "\n"
            . (is_string($item['commentary'] ?? null) ? $item['commentary'] : '');
        $text = mb_substr($text, 0, 20000);
        $found = [];

        if (preg_match_all('/did:web:[A-Za-z0-9._%:\-]+/', $text, $m)) {
            foreach ($m[0] as $token) {
                $token = rtrim($token, '.:-');
                if ($token !== $authorDid && !isset($found[$token]) && $this->isActiveDid($token)) {
                    $found[$token] = 'did';
                }
            }
        }

        if (preg_match_all('/(?<![\w@\/.])@([A-Za-z0-9][A-Za-z0-9._-]{0,99})/u', $text, $m)) {
            $map = $this->handleMap();
            foreach ($m[1] as $handle) {
                $dids = $map[strtolower(rtrim($handle, '.-'))] ?? [];
                if (count($dids) === 1 && $dids[0] !== $authorDid && !isset($found[$dids[0]])) {
                    $found[$dids[0]] = 'handle';
                }
            }
        }

        $targetAuthor = is_string($item['targetAuthorUrl'] ?? null) ? rtrim(trim($item['targetAuthorUrl']), '/') : '';
        if ($targetAuthor !== '') {
            $stmt = $this->ctx->pdo()->prepare("SELECT did FROM entries WHERE site_url = ? AND status = 'active'");
            $stmt->execute([$targetAuthor]);
            $tDid = $stmt->fetchColumn();
            if (is_string($tDid) && $tDid !== $authorDid && !isset($found[$tDid])) {
                $found[$tDid] = 'target';
            }
        }

        return array_slice($found, 0, self::MAX_MENTIONS_PER_ITEM, true);
    }

    /** @return array<string, list<string>> */
    private function handleMap(): array
    {
        if ($this->handleMap === null) {
            $this->handleMap = [];
            $rows = $this->ctx->pdo()->query("SELECT did, handle FROM entries WHERE status = 'active' AND handle <> ''")->fetchAll();
            foreach ($rows as $row) {
                $h = strtolower(ltrim(trim((string) $row['handle']), '@'));
                if ($h !== '') {
                    $this->handleMap[$h][] = (string) $row['did'];
                }
            }
        }
        return $this->handleMap;
    }

    private function isActiveDid(string $did): bool
    {
        $stmt = $this->ctx->pdo()->prepare("SELECT 1 FROM entries WHERE did = ? AND status = 'active'");
        $stmt->execute([$did]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * Deletes reactions, mentions, reports and hides for a member's items (all of them when
     * `$itemIds` is null).
     *
     * @param list<string>|null $itemIds
     */
    private function deleteItemRows(string $did, ?array $itemIds): void
    {
        $pdo = $this->ctx->pdo();
        $tables = array_merge([$this->tReactions, $this->tMentions, $this->tReports, $this->tHidden], $this->groupItemTables());
        if ($itemIds === null) {
            foreach ($tables as $t) {
                $pdo->prepare("DELETE FROM {$t} WHERE item_did = ?")->execute([$did]);
            }
            return;
        }
        foreach (array_chunk($itemIds, 200) as $chunk) {
            $keys = array_map(static fn (string $id): string => self::itemKey($did, $id), $chunk);
            $ph = implode(',', array_fill(0, count($keys), '?'));
            foreach ($tables as $t) {
                $pdo->prepare("DELETE FROM {$t} WHERE item_key IN ({$ph})")->execute($keys);
            }
        }
    }

    /** Everything about a removed or blocked member: their items' rows and their own actions. */
    private function purgeMember(string $did): void
    {
        if ($did === '') {
            return;
        }
        $this->deleteItemRows($did, null);
        $this->purgeUserData($did);
        $this->ctx->pdo()->prepare("DELETE FROM {$this->tMentions} WHERE mentioned_did = ?")->execute([$did]);
        $this->handleMap = null;
    }

    /** What a member recorded here themselves (opt-out): reactions, reports, read-marker, limits. */
    private function purgeUserData(string $did): void
    {
        if ($did === '') {
            return;
        }
        $pdo = $this->ctx->pdo();
        $pdo->prepare("DELETE FROM {$this->tReactions} WHERE reactor_did = ?")->execute([$did]);
        $pdo->prepare("DELETE FROM {$this->tReports} WHERE reporter_did = ?")->execute([$did]);
        $pdo->prepare("DELETE FROM {$this->tSeen} WHERE did = ?")->execute([$did]);
        $pdo->prepare("DELETE FROM {$this->tLimits} WHERE did = ?")->execute([$did]);
        $this->purgeGroupUserData($did);
    }

    /** Rows whose item left the hub while this extension wasn't listening. */
    private function pruneOrphans(): void
    {
        $pdo = $this->ctx->pdo();
        foreach (array_merge([$this->tReactions, $this->tMentions, $this->tReports, $this->tHidden], $this->groupItemTables()) as $t) {
            $pdo->exec(
                "DELETE FROM {$t} WHERE NOT EXISTS (
                   SELECT 1 FROM {$this->tItems} i WHERE i.did = {$t}.item_did AND i.item_id = {$t}.item_id
                 )"
            );
        }
        $pdo->prepare("DELETE FROM {$this->tLimits} WHERE window_start < ?")->execute([time() - 2 * 86400]);
    }

    // =========================================================================
    // Item helpers
    // =========================================================================

    public static function itemKey(string $did, string $itemId): string
    {
        return hash('sha256', $did . "\n" . $itemId);
    }

    /**
     * One item joined with its active author, or null.
     *
     * @return array<string, mixed>|null
     */
    private function loadItem(string $did, string $itemId, bool $activeOnly = true): ?array
    {
        if ($did === '' || $itemId === '') {
            return null;
        }
        $stmt = $this->ctx->pdo()->prepare(
            "SELECT i.*, e.site_url, e.handle, e.display_name, e.avatar_url, e.status AS entry_status
             FROM {$this->tItems} i JOIN entries e ON e.did = i.did
             WHERE i.did = ? AND i.item_id = ?" . ($activeOnly ? " AND e.status = 'active'" : '')
        );
        $stmt->execute([$did, $itemId]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * The item this one replies to: another hub item whose canonical URL equals this item's
     * target and lives on its author's own site.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>|null
     */
    private function parentOf(array $row): ?array
    {
        $target = (string) ($row['target_url'] ?? '');
        if ($target === '' || $target === (string) ($row['canonical_url'] ?? '')) {
            return null;
        }
        $stmt = $this->ctx->pdo()->prepare(
            "SELECT i.*, e.site_url, e.handle, e.display_name, e.avatar_url
             FROM {$this->tItems} i JOIN entries e ON e.did = i.did
             WHERE i.canonical_url = ? AND e.status = 'active'"
        );
        $stmt->execute([$target]);
        foreach ($stmt->fetchAll() as $cand) {
            if ($this->ownsUrl((string) $cand['site_url'], (string) $cand['canonical_url'])) {
                return $cand;
            }
        }
        return null;
    }

    /**
     * Whether `$url` is on the site at `$siteUrl`, so one member can't claim another member's
     * canonical URL and capture the replies to it.
     */
    private function ownsUrl(string $siteUrl, string $url): bool
    {
        $base = rtrim($siteUrl, '/');
        return $base !== '' && str_starts_with($url, $base . '/');
    }

    /** SQL for "item `$p`'s canonical URL is on its author `$pe`'s site". */
    private function ownedSql(string $p, string $pe): string
    {
        if ($this->ctx->driver() === 'sqlite') {
            return "substr({$p}.canonical_url, 1, length({$pe}.site_url) + 1) = {$pe}.site_url || '/'";
        }
        return "SUBSTRING({$p}.canonical_url, 1, CHAR_LENGTH({$pe}.site_url) + 1) = CONCAT({$pe}.site_url, '/')";
    }

    private function substrFn(): string
    {
        return $this->ctx->driver() === 'sqlite' ? 'substr' : 'SUBSTRING';
    }

    /**
     * The public shape of an item row. Text is passed through as plain text; clients render it
     * with textContent.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function shapeItem(array $row): array
    {
        $raw = json_decode((string) ($row['raw_item_json'] ?? ''), true);
        $raw = is_array($raw) ? $raw : [];
        $str = static fn (string $k, int $max): string => is_string($raw[$k] ?? null) ? mb_substr(trim($raw[$k]), 0, $max) : '';
        $media = $str('mediaUrl', 500);
        $tags = [];
        foreach (is_array($raw['tags'] ?? null) ? $raw['tags'] : [] as $tag) {
            if (is_string($tag) && trim($tag) !== '' && count($tags) < 20) {
                $tags[] = mb_substr(trim($tag), 0, 60);
            }
        }
        $rating = $raw['rating'] ?? null;
        return [
            'did' => (string) $row['did'],
            'itemId' => (string) $row['item_id'],
            'type' => (string) $row['content_type'],
            'canonicalUrl' => (string) $row['canonical_url'],
            'targetUrl' => (string) ($row['target_url'] ?? ''),
            'publishedAt' => (string) $row['published_at'],
            'updatedAt' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
            'title' => $str('title', 300),
            'commentary' => $str('commentary', 5000),
            'mediaUrl' => preg_match('#^https://#i', $media) ? $media : '',
            'tags' => $tags,
            'rating' => is_int($rating) && $rating >= 1 && $rating <= 5 ? $rating : null,
            'targetAuthorName' => $str('targetAuthorName', 200),
            'event' => self::parseEvent($raw, (string) $row['content_type']),
            'listing' => self::parseListing($raw, (string) $row['content_type']),
            'author' => [
                'did' => (string) $row['did'],
                'siteUrl' => (string) ($row['site_url'] ?? ''),
                'displayName' => (string) ($row['display_name'] ?? ''),
                'handle' => (string) ($row['handle'] ?? ''),
                'avatarUrl' => preg_match('#^https://#i', (string) ($row['avatar_url'] ?? '')) ? (string) $row['avatar_url'] : '',
            ],
        ];
    }

    /**
     * Adds `reactions`, `myReactions`, `replyCount` and `hidden` to shaped items.
     *
     * @param array<int|string, array<string, mixed>> $items
     */
    private function decorate(array &$items, ?string $viewerDid, bool $skipHidden): void
    {
        if ($items === []) {
            return;
        }
        $keys = [];
        $urls = [];
        foreach ($items as $it) {
            $keys[] = self::itemKey($it['did'], $it['itemId']);
            if ($this->ownsUrl($it['author']['siteUrl'], $it['canonicalUrl'])) {
                $urls[] = $it['canonicalUrl'];
            }
        }
        [$counts, $mine] = $this->reactionsFor($keys, $viewerDid);

        $hidden = [];
        if (!$skipHidden) {
            foreach (array_chunk($keys, 300) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $stmt = $this->ctx->pdo()->prepare("SELECT item_key FROM {$this->tHidden} WHERE item_key IN ({$ph})");
                $stmt->execute($chunk);
                foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $k) {
                    $hidden[(string) $k] = true;
                }
            }
        }

        $replies = [];
        foreach (array_chunk(array_values(array_unique($urls)), 300) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->ctx->pdo()->prepare(
                "SELECT c.target_url, COUNT(*) AS n FROM {$this->tItems} c JOIN entries ce ON ce.did = c.did
                 WHERE ce.status = 'active' AND c.content_type = 'comment' AND c.target_url IN ({$ph})
                   AND NOT EXISTS (SELECT 1 FROM {$this->tHidden} h WHERE h.item_did = c.did AND h.item_id = c.item_id)
                 GROUP BY c.target_url"
            );
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll() as $row) {
                $replies[(string) $row['target_url']] = (int) $row['n'];
            }
        }

        foreach ($items as $i => $it) {
            $k = self::itemKey($it['did'], $it['itemId']);
            $items[$i]['reactions'] = (object) ($counts[$k] ?? []);
            $items[$i]['myReactions'] = $mine[$k] ?? [];
            $items[$i]['replyCount'] = $this->ownsUrl($it['author']['siteUrl'], $it['canonicalUrl'])
                ? ($replies[$it['canonicalUrl']] ?? 0)
                : 0;
            $items[$i]['hidden'] = isset($hidden[$k]);
        }
    }

    /**
     * @param list<string> $keys
     * @return array{0: array<string, array<string, int>>, 1: array<string, list<string>>}
     */
    private function reactionsFor(array $keys, ?string $viewerDid): array
    {
        $counts = [];
        $mine = [];
        foreach (array_chunk(array_values(array_unique($keys)), 300) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $stmt = $this->ctx->pdo()->prepare(
                "SELECT item_key, reaction, COUNT(*) AS n FROM {$this->tReactions} WHERE item_key IN ({$ph}) GROUP BY item_key, reaction"
            );
            $stmt->execute($chunk);
            foreach ($stmt->fetchAll() as $row) {
                $counts[(string) $row['item_key']][(string) $row['reaction']] = (int) $row['n'];
            }
            if ($viewerDid !== null) {
                $stmt = $this->ctx->pdo()->prepare(
                    "SELECT item_key, reaction FROM {$this->tReactions} WHERE reactor_did = ? AND item_key IN ({$ph})"
                );
                $stmt->execute(array_merge([$viewerDid], $chunk));
                foreach ($stmt->fetchAll() as $row) {
                    $mine[(string) $row['item_key']][] = (string) $row['reaction'];
                }
            }
        }
        return [$counts, $mine];
    }

    /**
     * Replaces hidden nodes' content with a placeholder; their replies stay visible.
     *
     * @param array<string, mixed> $node
     * @return array<string, mixed>
     */
    private function stripHidden(array $node): array
    {
        $children = array_map(fn (array $c) => $this->stripHidden($c), $node['children'] ?? []);
        if (!empty($node['hidden'])) {
            return [
                'did' => $node['did'],
                'itemId' => $node['itemId'],
                'publishedAt' => $node['publishedAt'],
                'hidden' => true,
                'children' => $children,
            ];
        }
        $node['children'] = $children;
        return $node;
    }

    /** @param array<string, mixed> $item */
    private static function excerpt(array $item): string
    {
        $text = trim((is_string($item['title'] ?? null) ? $item['title'] . ' ' : '') . (is_string($item['commentary'] ?? null) ? $item['commentary'] : ''));
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';
        return mb_strlen($text) > 140 ? mb_substr($text, 0, 139) . '…' : ($text !== '' ? $text : '(no text)');
    }

    /** @param array<string, mixed> $row */
    private static function authorName(array $row): string
    {
        $name = trim((string) ($row['display_name'] ?? ''));
        return $name !== '' ? $name : (string) $row['did'];
    }

    /**
     * Fixed-window counter in this extension's own table (same shape as arcade's), recording the
     * DID so a purge removes exactly that member's rows.
     */
    private function checkLimit(string $rateKey, int $windowSec, int $maxHits, string $did): bool
    {
        $pdo = $this->ctx->pdo();
        $now = time();
        $stmt = $pdo->prepare("SELECT window_start, hits FROM {$this->tLimits} WHERE rate_key = ?");
        $stmt->execute([$rateKey]);
        $row = $stmt->fetch();
        if (!is_array($row) || (int) $row['window_start'] <= $now - $windowSec) {
            $sql = $this->ctx->driver() === 'sqlite'
                ? "INSERT INTO {$this->tLimits} (rate_key, did, window_start, hits) VALUES (?, ?, ?, 1)
                   ON CONFLICT(rate_key) DO UPDATE SET window_start = excluded.window_start, hits = 1, did = excluded.did"
                : "INSERT INTO {$this->tLimits} (rate_key, did, window_start, hits) VALUES (?, ?, ?, 1)
                   ON DUPLICATE KEY UPDATE window_start = VALUES(window_start), hits = 1, did = VALUES(did)";
            $pdo->prepare($sql)->execute([$rateKey, $did, $now]);
            return true;
        }
        if ((int) $row['hits'] >= $maxHits) {
            return false;
        }
        $pdo->prepare("UPDATE {$this->tLimits} SET hits = hits + 1 WHERE rate_key = ?")->execute([$rateKey]);
        return true;
    }

    // =========================================================================
    // Schema
    // =========================================================================

    private function createTables(PDO $pdo, string $driver): void
    {
        if ($driver === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS {$this->tReactions} (
                  item_key TEXT NOT NULL,
                  item_did TEXT NOT NULL,
                  item_id TEXT NOT NULL,
                  reactor_did TEXT NOT NULL,
                  reaction TEXT NOT NULL,
                  created_at TEXT NOT NULL,
                  PRIMARY KEY (item_key, reactor_did, reaction)
                );
                CREATE INDEX IF NOT EXISTS idx_{$this->tReactions}_reactor ON {$this->tReactions}(reactor_did);
                CREATE INDEX IF NOT EXISTS idx_{$this->tReactions}_item_did ON {$this->tReactions}(item_did);

                CREATE TABLE IF NOT EXISTS {$this->tMentions} (
                  item_key TEXT NOT NULL,
                  item_did TEXT NOT NULL,
                  item_id TEXT NOT NULL,
                  mentioned_did TEXT NOT NULL,
                  source TEXT NOT NULL,
                  published_at TEXT NOT NULL,
                  PRIMARY KEY (item_key, mentioned_did)
                );
                CREATE INDEX IF NOT EXISTS idx_{$this->tMentions}_mentioned ON {$this->tMentions}(mentioned_did, published_at);
                CREATE INDEX IF NOT EXISTS idx_{$this->tMentions}_item_did ON {$this->tMentions}(item_did);

                CREATE TABLE IF NOT EXISTS {$this->tReports} (
                  id INTEGER PRIMARY KEY AUTOINCREMENT,
                  item_key TEXT NOT NULL,
                  item_did TEXT NOT NULL,
                  item_id TEXT NOT NULL,
                  reporter_did TEXT NOT NULL,
                  reason TEXT NOT NULL,
                  note TEXT NOT NULL DEFAULT '',
                  status TEXT NOT NULL DEFAULT 'open',
                  created_at TEXT NOT NULL,
                  resolved_at TEXT DEFAULT NULL,
                  UNIQUE (item_key, reporter_did)
                );
                CREATE INDEX IF NOT EXISTS idx_{$this->tReports}_status ON {$this->tReports}(status, created_at);
                CREATE INDEX IF NOT EXISTS idx_{$this->tReports}_item_did ON {$this->tReports}(item_did);
                CREATE INDEX IF NOT EXISTS idx_{$this->tReports}_reporter ON {$this->tReports}(reporter_did);

                CREATE TABLE IF NOT EXISTS {$this->tHidden} (
                  id INTEGER PRIMARY KEY AUTOINCREMENT,
                  item_key TEXT NOT NULL UNIQUE,
                  item_did TEXT NOT NULL,
                  item_id TEXT NOT NULL,
                  reason TEXT NOT NULL,
                  hidden_at TEXT NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_{$this->tHidden}_item ON {$this->tHidden}(item_did, item_id);

                CREATE TABLE IF NOT EXISTS {$this->tSeen} (
                  did TEXT PRIMARY KEY,
                  last_seen_at TEXT NOT NULL
                );

                CREATE TABLE IF NOT EXISTS {$this->tLimits} (
                  rate_key TEXT PRIMARY KEY,
                  did TEXT NOT NULL DEFAULT '',
                  window_start INTEGER NOT NULL,
                  hits INTEGER NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_{$this->tLimits}_did ON {$this->tLimits}(did);
            ");
            return;
        }

        $did = 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL';
        $key = 'CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL';
        $tail = 'ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tReactions} (
              item_key {$key},
              item_did {$did},
              item_id {$did},
              reactor_did {$did},
              reaction VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              created_at VARCHAR(40) NOT NULL,
              PRIMARY KEY (item_key, reactor_did, reaction),
              KEY idx_reactions_reactor (reactor_did),
              KEY idx_reactions_item_did (item_did)
            ) {$tail}
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tMentions} (
              item_key {$key},
              item_did {$did},
              item_id {$did},
              mentioned_did {$did},
              source VARCHAR(16) NOT NULL,
              published_at VARCHAR(40) NOT NULL,
              PRIMARY KEY (item_key, mentioned_did),
              KEY idx_mentions_mentioned (mentioned_did, published_at),
              KEY idx_mentions_item_did (item_did)
            ) {$tail}
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tReports} (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              item_key {$key},
              item_did {$did},
              item_id {$did},
              reporter_did {$did},
              reason VARCHAR(20) NOT NULL,
              note VARCHAR(500) NOT NULL DEFAULT '',
              status VARCHAR(20) NOT NULL DEFAULT 'open',
              created_at VARCHAR(40) NOT NULL,
              resolved_at VARCHAR(40) DEFAULT NULL,
              PRIMARY KEY (id),
              UNIQUE KEY uq_reports_item_reporter (item_key, reporter_did),
              KEY idx_reports_status (status, created_at),
              KEY idx_reports_item_did (item_did),
              KEY idx_reports_reporter (reporter_did)
            ) {$tail}
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tHidden} (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              item_key {$key},
              item_did {$did},
              item_id {$did},
              reason VARCHAR(20) NOT NULL,
              hidden_at VARCHAR(40) NOT NULL,
              PRIMARY KEY (id),
              UNIQUE KEY uq_hidden_item (item_key),
              KEY idx_hidden_item (item_did, item_id)
            ) {$tail}
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tSeen} (
              did {$did},
              last_seen_at VARCHAR(40) NOT NULL,
              PRIMARY KEY (did)
            ) {$tail}
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tLimits} (
              rate_key VARCHAR(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
              did {$did} DEFAULT '',
              window_start BIGINT NOT NULL,
              hits INT NOT NULL,
              PRIMARY KEY (rate_key),
              KEY idx_limits_did (did)
            ) {$tail}
        ");
    }
}
