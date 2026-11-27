<?php
declare(strict_types=1);

namespace WebringHub\Ext\Social;

use PDO;
use WebringHub\AppError;
use WebringHub\ExtensionContext;
use WebringHub\ExtensionRawResponse;
use WebringHub\ExtensionRequest;

/**
 * Tier 3 belonging & identity features for `social`:
 *
 * - **Vouches (T3.1):** signed `post` items with a `vouch` object (`subjectDid`, `subjectSiteUrl`,
 *   `kind`: `met`|`skill`|`worked-with`, `skill`). Indexed from `webhook-sync`; subjects may hide
 *   vouches about themselves under opt-in.
 * - **Welcome wall & spotlight (T3.2):** newcomers listed within `welcomeDays` with preset-only
 *   greetings (`wave`, `welcome`, `coffee`) and a daily deterministic member spotlight.
 * - **Roles & badges (T3.3):** admin-assigned role labels (`organizer`, `moderator`, plus up to 5
 *   custom labels) and nightly computed earned badges (`host`, `helper`, `voice`, `welcomer`,
 *   `voucher`, `founding`).
 * - **Guestbooks (T3.4):** owner opt-in plain-text guestbooks (<=280 chars) signed by verified,
 *   opted-in members, with owner delete, standard reports/moderation and CSV export.
 */
trait Belonging
{
    // Constants live on SocialExtension (constants in traits need PHP 8.2).
    private string $tVouches = '';
    private string $tVouchPrefs = '';
    private string $tGreetings = '';
    private string $tRoles = '';
    private string $tBadges = '';
    private string $tGuestbooks = '';
    private string $tGuestbookEntries = '';
    private string $tGuestbookReports = '';
    private bool $belongingReady = false;
    private ?bool $hasKeyWitnessTable = null;

    private function registerBelonging(ExtensionContext $ctx): void
    {
        $this->tVouches = $ctx->table('vouches');
        $this->tVouchPrefs = $ctx->table('vouch_prefs');
        $this->tGreetings = $ctx->table('greetings');
        $this->tRoles = $ctx->table('roles');
        $this->tBadges = $ctx->table('badges');
        $this->tGuestbooks = $ctx->table('guestbooks');
        $this->tGuestbookEntries = $ctx->table('guestbook_entries');
        $this->tGuestbookReports = $ctx->table('guestbook_reports');

        $ctx->migrate(3, function (PDO $pdo, string $driver): void {
            $this->createBelongingTables($pdo, $driver);
            $this->belongingReady = true;
            $this->reindexVouches();
            $this->recomputeBadges();
        });
        $this->belongingReady = $ctx->schemaVersion() >= 3;

        // T3.1 Vouches & People directory
        $ctx->get('v1/people', fn (array $p, ExtensionRequest $req) => $this->handlePeople($req));
        $ctx->get('v1/members/vouches', fn (array $p, ExtensionRequest $req) => $this->handleMemberVouches($req));
        $ctx->get('v1/vouches/recent', fn (array $p, ExtensionRequest $req) => $this->handleRecentVouches($req));
        $ctx->get('v1/me/vouch-prefs', fn (array $p, ExtensionRequest $req) => $this->handleGetVouchPrefs($req), ['userAuth' => true]);
        $ctx->post('v1/me/vouch-prefs', fn (array $p, ExtensionRequest $req) => $this->handleSetVouchPrefs($req), ['requireOptIn' => true]);

        // T3.2 Welcome wall & spotlight
        $ctx->get('v1/welcome', fn (array $p, ExtensionRequest $req) => $this->handleWelcome($req));
        $ctx->post('v1/greetings', fn (array $p, ExtensionRequest $req) => $this->handleGreeting($req), ['requireOptIn' => true]);
        $ctx->get('v1/spotlight', fn (array $p, ExtensionRequest $req) => $this->handleSpotlight($req));

        // T3.3 Roles & badges (admin)
        $ctx->get('v1/admin/roles', fn () => $this->handleAdminRoles(), ['admin' => true]);
        $ctx->post('v1/admin/roles', fn (array $p, ExtensionRequest $req) => $this->handleAdminAssignRole($req), ['admin' => true]);
        $ctx->delete('v1/admin/roles/{id}', fn (array $p) => $this->handleAdminRemoveRole((string) $p['id']), ['admin' => true]);
        $ctx->post('v1/admin/badges/recompute', fn () => $this->handleAdminRecomputeBadges(), ['admin' => true]);

        // T3.4 Guestbooks
        $ctx->get('v1/guestbook', fn (array $p, ExtensionRequest $req) => $this->handleGetGuestbook($req));
        $ctx->post('v1/guestbook', fn (array $p, ExtensionRequest $req) => $this->handleSignGuestbook($req), ['requireOptIn' => true, 'rateLimited' => true]);
        $ctx->delete('v1/guestbook/{id}', fn (array $p, ExtensionRequest $req) => $this->handleDeleteGuestbookEntry((string) $p['id'], $req), ['userAuth' => true]);
        $ctx->post('v1/guestbook/{id}/report', fn (array $p, ExtensionRequest $req) => $this->handleReportGuestbookEntry((string) $p['id'], $req), ['requireOptIn' => true, 'rateLimited' => true]);
        $ctx->post('v1/me/guestbook', fn (array $p, ExtensionRequest $req) => $this->handleSetGuestbookEnabled($req), ['requireOptIn' => true]);
        $ctx->get('v1/me/guestbook.csv', fn (array $p, ExtensionRequest $req) => $this->handleGuestbookCsv($req), ['userAuth' => true]);

        $ctx->get('v1/admin/guestbook/reports', fn (array $p, ExtensionRequest $req) => $this->handleAdminGuestbookReports($req), ['admin' => true]);
        $ctx->get('v1/admin/guestbook/hidden', fn () => $this->handleAdminGuestbookHidden(), ['admin' => true]);
        $ctx->post('v1/admin/guestbook/reports/{id}/hide', fn (array $p) => $this->handleAdminGuestbookHide((string) $p['id']), ['admin' => true]);
        $ctx->post('v1/admin/guestbook/reports/{id}/dismiss', fn (array $p) => $this->handleAdminGuestbookDismiss((string) $p['id']), ['admin' => true]);
        $ctx->post('v1/admin/guestbook/hidden/{id}/restore', fn (array $p) => $this->handleAdminGuestbookRestore((string) $p['id']), ['admin' => true]);
    }

    // =========================================================================
    // Settings
    // =========================================================================

    private function welcomeDays(): int
    {
        return max(1, min(90, (int) ($this->ctx->config()['welcomeDays'] ?? 14)));
    }

    private function introTag(): string
    {
        $raw = $this->ctx->config()['introTag'] ?? 'intro';
        if (!is_string($raw)) {
            return 'intro';
        }
        $clean = mb_strtolower(trim(ltrim(trim($raw), '#')));
        return $clean !== '' ? mb_substr($clean, 0, 40) : 'intro';
    }

    private function spotlightEnabled(): bool
    {
        $v = $this->ctx->config()['spotlight'] ?? true;
        return $v !== false && $v !== 0 && $v !== '0' && $v !== 'false';
    }

    /** @return list<string> */
    private function allowedRoles(): array
    {
        $roles = self::BUILTIN_ROLES;
        $raw = $this->ctx->config()['roles'] ?? [];
        if (is_array($raw)) {
            $custom = 0;
            foreach ($raw as $r) {
                if (!is_string($r)) {
                    continue;
                }
                $clean = mb_strtolower(trim($r));
                if (preg_match('/^[a-z][a-z0-9-]{1,23}$/', $clean) && !in_array($clean, $roles, true)) {
                    $roles[] = $clean;
                    $custom++;
                    if ($custom >= 5) {
                        break;
                    }
                }
            }
        }
        return $roles;
    }

    private function badgeThreshold(string $key, int $default): int
    {
        $cfg = $this->ctx->config()['badgeThresholds'] ?? [];
        if (is_array($cfg) && isset($cfg[$key])) {
            return max(1, (int) $cfg[$key]);
        }
        return $default;
    }

    // =========================================================================
    // Parsing & indexing vouches (T3.1)
    // =========================================================================

    /**
     * Parses the `vouch` object of a post, or null if missing/invalid.
     *
     * @param array<string, mixed> $item
     * @return array{subjectDid: string, subjectSiteUrl: string, kind: string, skill: string}|null
     */
    public static function parseVouch(array $item, string $type): ?array
    {
        $v = $item['vouch'] ?? null;
        if ($type !== 'post' || !is_array($v)) {
            return null;
        }
        $subjectDid = is_string($v['subjectDid'] ?? null) ? trim($v['subjectDid']) : '';
        $subjectSiteUrl = is_string($v['subjectSiteUrl'] ?? null) ? rtrim(trim($v['subjectSiteUrl']), '/') : '';
        $kind = is_string($v['kind'] ?? null) ? trim($v['kind']) : '';
        if ($subjectDid === '' || strlen($subjectDid) > 255 || !preg_match('/^did:web:[A-Za-z0-9._%:\-]+$/', $subjectDid)) {
            return null;
        }
        if ($subjectSiteUrl === '' || strlen($subjectSiteUrl) > 500 || !preg_match('#^https?://[^\s]+$#i', $subjectSiteUrl)) {
            return null;
        }
        if (!in_array($kind, self::VOUCH_KINDS, true)) {
            return null;
        }
        $skill = '';
        if ($kind === 'skill') {
            $skill = is_string($v['skill'] ?? null) ? mb_substr(trim(preg_replace('/\s+/u', ' ', $v['skill']) ?? ''), 0, 60) : '';
            if ($skill === '') {
                return null;
            }
        }
        return [
            'subjectDid' => $subjectDid,
            'subjectSiteUrl' => $subjectSiteUrl,
            'kind' => $kind,
            'skill' => $skill,
        ];
    }

    /**
     * Indexes one webhook-sync item for vouches.
     * Accepts a vouch only if:
     * - subject is an active entry whose site_url matches subjectSiteUrl;
     * - voucher !== subject;
     * - at most one vouch per (voucher, subject, kind, skill) is kept (newest wins).
     *
     * @param array<string, mixed> $row
     */
    private function indexVouch(array $row): void
    {
        if (!$this->belongingReady) {
            return;
        }
        $voucherDid = (string) $row['did'];
        $itemId = (string) $row['item_id'];
        $key = self::itemKey($voucherDid, $itemId);
        $pdo = $this->ctx->pdo();
        $item = json_decode((string) $row['raw_item_json'], true);
        $item = is_array($item) ? $item : [];
        $type = (string) ($row['content_type'] ?? ($item['type'] ?? 'post'));
        $publishedAt = (string) $row['published_at'];

        $vouch = self::parseVouch($item, $type);
        if ($vouch === null || $vouch['subjectDid'] === $voucherDid) {
            $pdo->prepare("DELETE FROM {$this->tVouches} WHERE item_key = ?")->execute([$key]);
            return;
        }

        // Subject must be an active entry whose site_url matches subjectSiteUrl.
        $sub = $pdo->prepare("SELECT site_url FROM entries WHERE did = ? AND status = 'active'");
        $sub->execute([$vouch['subjectDid']]);
        $siteUrl = $sub->fetchColumn();
        if (!is_string($siteUrl) || rtrim($siteUrl, '/') !== $vouch['subjectSiteUrl']) {
            $pdo->prepare("DELETE FROM {$this->tVouches} WHERE item_key = ?")->execute([$key]);
            return;
        }

        // Deduplicate (voucher, subject, kind, skill): keep the newest.
        $dup = $pdo->prepare(
            "SELECT item_key, published_at FROM {$this->tVouches}
             WHERE voucher_did = ? AND subject_did = ? AND kind = ? AND skill = ? AND item_key <> ?"
        );
        $dup->execute([$voucherDid, $vouch['subjectDid'], $vouch['kind'], $vouch['skill'], $key]);
        foreach ($dup->fetchAll() as $existing) {
            if ((string) $existing['published_at'] > $publishedAt) {
                // An even newer vouch already exists; do not overwrite with an older one.
                $pdo->prepare("DELETE FROM {$this->tVouches} WHERE item_key = ?")->execute([$key]);
                return;
            }
            $pdo->prepare("DELETE FROM {$this->tVouches} WHERE item_key = ?")->execute([(string) $existing['item_key']]);
        }

        $sqlite = $this->ctx->driver() === 'sqlite';
        $sql = $sqlite
            ? "INSERT INTO {$this->tVouches} (item_key, item_did, item_id, voucher_did, subject_did, kind, skill, published_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?)
               ON CONFLICT(item_key) DO UPDATE SET subject_did = excluded.subject_did, kind = excluded.kind, skill = excluded.skill, published_at = excluded.published_at"
            : "INSERT INTO {$this->tVouches} (item_key, item_did, item_id, voucher_did, subject_did, kind, skill, published_at)
               VALUES (?, ?, ?, ?, ?, ?, ?, ?)
               ON DUPLICATE KEY UPDATE subject_did = VALUES(subject_did), kind = VALUES(kind), skill = VALUES(skill), published_at = VALUES(published_at)";
        $pdo->prepare($sql)->execute([
            $key, $voucherDid, $itemId, $voucherDid, $vouch['subjectDid'], $vouch['kind'], $vouch['skill'], $publishedAt,
        ]);
    }

    private function reindexVouches(): void
    {
        if (!$this->belongingReady) {
            return;
        }
        $offset = 0;
        do {
            $stmt = $this->ctx->pdo()->query(
                "SELECT did, item_id, content_type, published_at, raw_item_json FROM {$this->tItems}
                 WHERE content_type = 'post' ORDER BY did, item_id LIMIT 500 OFFSET {$offset}"
            );
            $rows = $stmt->fetchAll();
            foreach ($rows as $row) {
                $this->indexVouch($row);
            }
            $offset += 500;
        } while (count($rows) === 500);
    }

    /** @return list<string> */
    private function belongingItemTables(): array
    {
        return $this->belongingReady ? [$this->tVouches] : [];
    }

    private function purgeBelongingMember(string $did): void
    {
        if (!$this->belongingReady || $did === '') {
            return;
        }
        $pdo = $this->ctx->pdo();
        $pdo->prepare("DELETE FROM {$this->tVouches} WHERE voucher_did = ? OR subject_did = ?")->execute([$did, $did]);
        $pdo->prepare("DELETE FROM {$this->tVouchPrefs} WHERE did = ?")->execute([$did]);
        $pdo->prepare("DELETE FROM {$this->tGreetings} WHERE subject_did = ? OR greeter_did = ?")->execute([$did, $did]);
        $pdo->prepare("DELETE FROM {$this->tRoles} WHERE did = ?")->execute([$did]);
        $pdo->prepare("DELETE FROM {$this->tBadges} WHERE did = ?")->execute([$did]);
        $this->purgeGuestbookMember($did);
    }

    /** Opt-out deletes member-stored preferences, greetings and guestbook data, but NOT admin roles or earned badges. */
    private function purgeBelongingUserData(string $did): void
    {
        if (!$this->belongingReady || $did === '') {
            return;
        }
        $pdo = $this->ctx->pdo();
        $pdo->prepare("DELETE FROM {$this->tVouchPrefs} WHERE did = ?")->execute([$did]);
        $pdo->prepare("DELETE FROM {$this->tGreetings} WHERE subject_did = ? OR greeter_did = ?")->execute([$did, $did]);
        $this->purgeGuestbookMember($did);
    }

    private function purgeGuestbookMember(string $did): void
    {
        $pdo = $this->ctx->pdo();
        // Delete reports for entries owned or authored by this DID, and reports filed by this DID.
        $pdo->prepare("DELETE FROM {$this->tGuestbookReports} WHERE reporter_did = ?")->execute([$did]);
        $pdo->prepare(
            "DELETE FROM {$this->tGuestbookReports} WHERE entry_id IN (
               SELECT id FROM {$this->tGuestbookEntries} WHERE owner_did = ? OR author_did = ?
             )"
        )->execute([$did, $did]);
        $pdo->prepare("DELETE FROM {$this->tGuestbookEntries} WHERE owner_did = ? OR author_did = ?")->execute([$did, $did]);
        $pdo->prepare("DELETE FROM {$this->tGuestbooks} WHERE owner_did = ?")->execute([$did]);
    }

    // =========================================================================
    // Optional key-witness check for vouches
    // =========================================================================

    /**
     * Returns voucher DID => latest key-change timestamp from key-witness, if that extension's
     * table exists on this hub.
     *
     * @param list<string> $dids
     * @return array<string, string>
     */
    private function keyChangeTimes(array $dids): array
    {
        $dids = array_values(array_unique(array_filter($dids, static fn ($d) => $d !== '')));
        if ($dids === [] || $this->hasKeyWitnessTable === false) {
            return [];
        }
        $out = [];
        try {
            foreach (array_chunk($dids, 200) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $stmt = $this->ctx->pdo()->prepare(
                    "SELECT did, MAX(first_seen_at) AS changed_at
                     FROM ext_key_witness_observations
                     WHERE did IN ({$ph}) AND outcome IN ('rotated', 'operator-accepted')
                     GROUP BY did"
                );
                $stmt->execute($chunk);
                foreach ($stmt->fetchAll() as $row) {
                    if (is_string($row['changed_at'] ?? null) && $row['changed_at'] !== '') {
                        $out[(string) $row['did']] = (string) $row['changed_at'];
                    }
                }
            }
            $this->hasKeyWitnessTable = true;
        } catch (\Throwable) {
            $this->hasKeyWitnessTable = false;
            return [];
        }
        return $out;
    }

    // =========================================================================
    // Roles & badges decoration helpers (T3.3)
    // =========================================================================

    /**
     * @param list<string> $dids
     * @return array{0: array<string, list<string>>, 1: array<string, list<string>>} [rolesByDid, badgesByDid]
     */
    private function rolesAndBadgesFor(array $dids): array
    {
        if (!$this->belongingReady) {
            return [[], []];
        }
        $dids = array_values(array_unique(array_filter($dids, static fn ($d) => $d !== '')));
        if ($dids === []) {
            return [[], []];
        }
        $roles = [];
        $badges = [];
        $pdo = $this->ctx->pdo();
        foreach (array_chunk($dids, 200) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            $s = $pdo->prepare("SELECT did, role FROM {$this->tRoles} WHERE did IN ({$ph}) ORDER BY assigned_at ASC");
            $s->execute($chunk);
            foreach ($s->fetchAll() as $r) {
                $roles[(string) $r['did']][] = (string) $r['role'];
            }
            $b = $pdo->prepare("SELECT did, badge FROM {$this->tBadges} WHERE did IN ({$ph}) ORDER BY earned_at ASC");
            $b->execute($chunk);
            foreach ($b->fetchAll() as $r) {
                $badges[(string) $r['did']][] = (string) $r['badge'];
            }
        }
        return [$roles, $badges];
    }

    /**
     * Attaches `roles` and `badges` to each item's `author` array.
     *
     * @param array<int|string, array<string, mixed>> $items
     */
    private function decorateAuthors(array &$items): void
    {
        if (!$this->belongingReady || $items === []) {
            return;
        }
        $dids = [];
        foreach ($items as $it) {
            if (is_string($it['author']['did'] ?? null)) {
                $dids[] = $it['author']['did'];
            }
        }
        [$roles, $badges] = $this->rolesAndBadgesFor($dids);
        foreach ($items as $i => $it) {
            $d = (string) ($it['author']['did'] ?? '');
            $items[$i]['author']['roles'] = $roles[$d] ?? [];
            $items[$i]['author']['badges'] = $badges[$d] ?? [];
        }
    }

    // =========================================================================
    // T3.1 Routes: People & Vouches
    // =========================================================================

    private function isVouchHiddenBySubject(string $subjectDid): bool
    {
        $stmt = $this->ctx->pdo()->prepare("SELECT hide_received FROM {$this->tVouchPrefs} WHERE did = ?");
        $stmt->execute([$subjectDid]);
        return (int) $stmt->fetchColumn() === 1;
    }

    /** @return array<string, mixed> */
    private function handlePeople(ExtensionRequest $req): array
    {
        $this->requireReader($req);
        $pdo = $this->ctx->pdo();
        $rows = $pdo->query(
            "SELECT did, display_name, handle, site_url, avatar_url, bio, created_at
             FROM entries WHERE status = 'active' ORDER BY display_name ASC, created_at DESC LIMIT 200"
        )->fetchAll();

        $dids = array_map(static fn (array $r): string => (string) $r['did'], $rows);
        [$roles, $badges] = $this->rolesAndBadgesFor($dids);

        // Vouch counts per subject (excluding hidden items and inactive vouchers, respecting hide_received).
        $hiddenSubjects = [];
        foreach ($pdo->query("SELECT did FROM {$this->tVouchPrefs} WHERE hide_received = 1")->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $hiddenSubjects[(string) $d] = true;
        }
        $vouchCounts = [];
        $vc = $pdo->query(
            "SELECT v.subject_did, COUNT(DISTINCT v.voucher_did) AS n
             FROM {$this->tVouches} v
             JOIN entries ve ON ve.did = v.voucher_did AND ve.status = 'active'
             WHERE NOT EXISTS (SELECT 1 FROM {$this->tHidden} h WHERE h.item_key = v.item_key)
             GROUP BY v.subject_did"
        )->fetchAll();
        foreach ($vc as $r) {
            $sd = (string) $r['subject_did'];
            if (!isset($hiddenSubjects[$sd])) {
                $vouchCounts[$sd] = (int) $r['n'];
            }
        }

        $gbEnabled = [];
        foreach ($pdo->query("SELECT owner_did FROM {$this->tGuestbooks} WHERE enabled = 1")->fetchAll(PDO::FETCH_COLUMN) as $d) {
            $gbEnabled[(string) $d] = true;
        }

        $cutoff = gmdate('Y-m-d\TH:i:s.000\Z', time() - $this->welcomeDays() * 86400);
        $members = [];
        foreach ($rows as $r) {
            $d = (string) $r['did'];
            $members[] = [
                'did' => $d,
                'displayName' => (string) $r['display_name'],
                'handle' => (string) $r['handle'],
                'siteUrl' => (string) $r['site_url'],
                'avatarUrl' => preg_match('#^https://#i', (string) $r['avatar_url']) ? (string) $r['avatar_url'] : '',
                'bio' => mb_substr((string) ($r['bio'] ?? ''), 0, 240),
                'createdAt' => (string) $r['created_at'],
                'isNewcomer' => (string) $r['created_at'] >= $cutoff,
                'roles' => $roles[$d] ?? [],
                'badges' => $badges[$d] ?? [],
                'vouchCount' => $vouchCounts[$d] ?? 0,
                'vouchesHidden' => isset($hiddenSubjects[$d]),
                'guestbookEnabled' => isset($gbEnabled[$d]),
            ];
        }
        return ['members' => $members];
    }

    /** @return array<string, mixed> */
    private function handleMemberVouches(ExtensionRequest $req): array
    {
        $this->requireReader($req);
        $did = is_string($req->query['did'] ?? null) ? trim($req->query['did']) : '';
        if ($did === '' || !$this->isActiveDid($did)) {
            throw new AppError(404, 'not_found', 'Member not found.');
        }
        $pdo = $this->ctx->pdo();
        $hiddenBySubject = $this->isVouchHiddenBySubject($did);
        $isSelf = $req->userDid === $did;

        $received = [];
        $grouped = ['met' => [], 'worked-with' => [], 'skill' => []];
        if (!$hiddenBySubject || $isSelf) {
            $stmt = $pdo->prepare(
                "SELECT v.*, e.display_name, e.handle, e.site_url, e.avatar_url
                 FROM {$this->tVouches} v
                 JOIN entries e ON e.did = v.voucher_did AND e.status = 'active'
                 WHERE v.subject_did = ?
                   AND NOT EXISTS (SELECT 1 FROM {$this->tHidden} h WHERE h.item_key = v.item_key)
                 ORDER BY v.published_at DESC LIMIT 200"
            );
            $stmt->execute([$did]);
            $rows = $stmt->fetchAll();
            $vDids = array_map(static fn (array $r): string => (string) $r['voucher_did'], $rows);
            $keyChanges = $this->keyChangeTimes($vDids);
            [$roles, $badges] = $this->rolesAndBadgesFor($vDids);
            foreach ($rows as $r) {
                $vd = (string) $r['voucher_did'];
                $entry = [
                    'itemId' => (string) $r['item_id'],
                    'kind' => (string) $r['kind'],
                    'skill' => (string) $r['skill'],
                    'publishedAt' => (string) $r['published_at'],
                    'keyChangedSince' => isset($keyChanges[$vd]) && $keyChanges[$vd] > (string) $r['published_at'],
                    'voucher' => [
                        'did' => $vd,
                        'displayName' => (string) $r['display_name'],
                        'handle' => (string) $r['handle'],
                        'siteUrl' => (string) $r['site_url'],
                        'avatarUrl' => preg_match('#^https://#i', (string) $r['avatar_url']) ? (string) $r['avatar_url'] : '',
                        'roles' => $roles[$vd] ?? [],
                        'badges' => $badges[$vd] ?? [],
                    ],
                ];
                $received[] = $entry;
                if ($entry['kind'] === 'skill') {
                    $grouped['skill'][$entry['skill']][] = $entry;
                } else {
                    $grouped[$entry['kind']][] = $entry;
                }
            }
        }

        $gStmt = $pdo->prepare(
            "SELECT v.*, e.display_name, e.handle, e.site_url, e.avatar_url
             FROM {$this->tVouches} v
             JOIN entries e ON e.did = v.subject_did AND e.status = 'active'
             WHERE v.voucher_did = ?
               AND NOT EXISTS (SELECT 1 FROM {$this->tHidden} h WHERE h.item_key = v.item_key)
               AND NOT EXISTS (SELECT 1 FROM {$this->tVouchPrefs} vp WHERE vp.did = v.subject_did AND vp.hide_received = 1)
             ORDER BY v.published_at DESC LIMIT 200"
        );
        $gStmt->execute([$did]);
        $givenRows = $gStmt->fetchAll();
        $voucherKeyChanges = $this->keyChangeTimes([$did]);
        $given = [];
        foreach ($givenRows as $r) {
            $given[] = [
                'itemId' => (string) $r['item_id'],
                'kind' => (string) $r['kind'],
                'skill' => (string) $r['skill'],
                'publishedAt' => (string) $r['published_at'],
                'keyChangedSince' => isset($voucherKeyChanges[$did]) && $voucherKeyChanges[$did] > (string) $r['published_at'],
                'subject' => [
                    'did' => (string) $r['subject_did'],
                    'displayName' => (string) $r['display_name'],
                    'handle' => (string) $r['handle'],
                    'siteUrl' => (string) $r['site_url'],
                    'avatarUrl' => preg_match('#^https://#i', (string) $r['avatar_url']) ? (string) $r['avatar_url'] : '',
                ],
            ];
        }

        return [
            'did' => $did,
            'hidden' => $hiddenBySubject,
            'received' => $received,
            'grouped' => [
                'met' => $grouped['met'],
                'worked-with' => $grouped['worked-with'],
                'skill' => (object) $grouped['skill'],
            ],
            'given' => $given,
            'totalReceived' => count($received),
            'totalGiven' => count($given),
        ];
    }

    /** @return array<string, mixed> */
    private function handleRecentVouches(ExtensionRequest $req): array
    {
        $this->requireReader($req);
        $limit = max(1, min(50, (int) ($req->query['limit'] ?? 20)));
        $stmt = $this->ctx->pdo()->query(
            "SELECT v.*,
                    ve.display_name AS v_name, ve.handle AS v_handle, ve.site_url AS v_url, ve.avatar_url AS v_avatar,
                    se.display_name AS s_name, se.handle AS s_handle, se.site_url AS s_url, se.avatar_url AS s_avatar
             FROM {$this->tVouches} v
             JOIN entries ve ON ve.did = v.voucher_did AND ve.status = 'active'
             JOIN entries se ON se.did = v.subject_did AND se.status = 'active'
             WHERE NOT EXISTS (SELECT 1 FROM {$this->tHidden} h WHERE h.item_key = v.item_key)
               AND NOT EXISTS (SELECT 1 FROM {$this->tVouchPrefs} vp WHERE vp.did = v.subject_did AND vp.hide_received = 1)
             ORDER BY v.published_at DESC LIMIT {$limit}"
        );
        $rows = $stmt->fetchAll();
        $vDids = array_map(static fn (array $r): string => (string) $r['voucher_did'], $rows);
        $keyChanges = $this->keyChangeTimes($vDids);
        $out = [];
        foreach ($rows as $r) {
            $vd = (string) $r['voucher_did'];
            $out[] = [
                'itemId' => (string) $r['item_id'],
                'kind' => (string) $r['kind'],
                'skill' => (string) $r['skill'],
                'publishedAt' => (string) $r['published_at'],
                'keyChangedSince' => isset($keyChanges[$vd]) && $keyChanges[$vd] > (string) $r['published_at'],
                'voucher' => [
                    'did' => $vd,
                    'displayName' => (string) $r['v_name'],
                    'handle' => (string) $r['v_handle'],
                    'siteUrl' => (string) $r['v_url'],
                    'avatarUrl' => preg_match('#^https://#i', (string) $r['v_avatar']) ? (string) $r['v_avatar'] : '',
                ],
                'subject' => [
                    'did' => (string) $r['subject_did'],
                    'displayName' => (string) $r['s_name'],
                    'handle' => (string) $r['s_handle'],
                    'siteUrl' => (string) $r['s_url'],
                    'avatarUrl' => preg_match('#^https://#i', (string) $r['s_avatar']) ? (string) $r['s_avatar'] : '',
                ],
            ];
        }
        return ['vouches' => $out];
    }

    /** @return array<string, mixed> */
    private function handleGetVouchPrefs(ExtensionRequest $req): array
    {
        $did = (string) $req->userDid;
        return ['hideReceived' => $this->isVouchHiddenBySubject($did)];
    }

    /** @return array<string, mixed> */
    private function handleSetVouchPrefs(ExtensionRequest $req): array
    {
        $did = (string) $req->userDid;
        $body = $req->json();
        $hide = !empty($body['hideReceived']) ? 1 : 0;
        $now = $this->ctx->isoNow();
        $sql = $this->ctx->driver() === 'sqlite'
            ? "INSERT INTO {$this->tVouchPrefs} (did, hide_received, updated_at) VALUES (?, ?, ?)
               ON CONFLICT(did) DO UPDATE SET hide_received = excluded.hide_received, updated_at = excluded.updated_at"
            : "INSERT INTO {$this->tVouchPrefs} (did, hide_received, updated_at) VALUES (?, ?, ?)
               ON DUPLICATE KEY UPDATE hide_received = VALUES(hide_received), updated_at = VALUES(updated_at)";
        $this->ctx->pdo()->prepare($sql)->execute([$did, $hide, $now]);
        return ['hideReceived' => $hide === 1];
    }

    // =========================================================================
    // T3.2 Routes: Welcome wall & member spotlight
    // =========================================================================

    /** @return array<string, mixed> */
    private function handleWelcome(ExtensionRequest $req): array
    {
        $this->requireReader($req);
        return $this->loadWelcome($req->userDid);
    }

    /** @return array<string, mixed> */
    private function loadWelcome(?string $viewerDid): array
    {
        $days = $this->welcomeDays();
        $introTag = $this->introTag();
        $cutoff = gmdate('Y-m-d\TH:i:s.000\Z', time() - $days * 86400);
        $pdo = $this->ctx->pdo();

        $stmt = $pdo->prepare(
            "SELECT did, display_name, handle, site_url, avatar_url, bio, created_at
             FROM entries WHERE status = 'active' AND created_at >= ?
             ORDER BY created_at DESC LIMIT 30"
        );
        $stmt->execute([$cutoff]);
        $rows = $stmt->fetchAll();
        if ($rows === []) {
            return ['welcomeDays' => $days, 'introTag' => $introTag, 'greetings' => self::GREETING_KINDS, 'members' => []];
        }

        $dids = array_map(static fn (array $r): string => (string) $r['did'], $rows);
        [$roles, $badges] = $this->rolesAndBadgesFor($dids);

        $ph = implode(',', array_fill(0, count($dids), '?'));
        $gCounts = [];
        $gStmt = $pdo->prepare(
            "SELECT g.subject_did, g.kind, COUNT(*) AS n
             FROM {$this->tGreetings} g
             JOIN entries ge ON ge.did = g.greeter_did AND ge.status = 'active'
             WHERE g.subject_did IN ({$ph})
             GROUP BY g.subject_did, g.kind"
        );
        $gStmt->execute($dids);
        foreach ($gStmt->fetchAll() as $r) {
            $gCounts[(string) $r['subject_did']][(string) $r['kind']] = (int) $r['n'];
        }

        $myGreetings = [];
        if ($viewerDid !== null) {
            $mStmt = $pdo->prepare("SELECT subject_did, kind FROM {$this->tGreetings} WHERE greeter_did = ? AND subject_did IN ({$ph})");
            $mStmt->execute(array_merge([$viewerDid], $dids));
            foreach ($mStmt->fetchAll() as $r) {
                $myGreetings[(string) $r['subject_did']][] = (string) $r['kind'];
            }
        }

        // Find each newcomer's latest #intro post (if any).
        $introPosts = [];
        $pStmt = $pdo->prepare(
            "SELECT i.did, i.item_id, i.canonical_url, i.published_at, i.raw_item_json
             FROM {$this->tItems} i
             WHERE i.content_type = 'post' AND i.did IN ({$ph})
               AND NOT EXISTS (SELECT 1 FROM {$this->tHidden} h WHERE h.item_did = i.did AND h.item_id = i.item_id)
             ORDER BY i.published_at DESC"
        );
        $pStmt->execute($dids);
        foreach ($pStmt->fetchAll() as $p) {
            $d = (string) $p['did'];
            if (isset($introPosts[$d])) {
                continue;
            }
            $raw = json_decode((string) $p['raw_item_json'], true);
            $raw = is_array($raw) ? $raw : [];
            $tags = self::normTags($raw['tags'] ?? []);
            if (in_array($introTag, $tags, true)) {
                $introPosts[$d] = [
                    'did' => $d,
                    'itemId' => (string) $p['item_id'],
                    'canonicalUrl' => (string) $p['canonical_url'],
                    'title' => is_string($raw['title'] ?? null) ? mb_substr(trim($raw['title']), 0, 200) : '',
                    'excerpt' => self::excerpt($raw),
                    'publishedAt' => (string) $p['published_at'],
                ];
            }
        }

        $members = [];
        foreach ($rows as $r) {
            $d = (string) $r['did'];
            $members[] = [
                'did' => $d,
                'displayName' => (string) $r['display_name'],
                'handle' => (string) $r['handle'],
                'siteUrl' => (string) $r['site_url'],
                'avatarUrl' => preg_match('#^https://#i', (string) $r['avatar_url']) ? (string) $r['avatar_url'] : '',
                'bio' => mb_substr((string) ($r['bio'] ?? ''), 0, 200),
                'createdAt' => (string) $r['created_at'],
                'roles' => $roles[$d] ?? [],
                'badges' => $badges[$d] ?? [],
                'greetingCounts' => (object) ($gCounts[$d] ?? []),
                'myGreetings' => $myGreetings[$d] ?? [],
                'introPost' => $introPosts[$d] ?? null,
            ];
        }
        return [
            'welcomeDays' => $days,
            'introTag' => $introTag,
            'greetings' => self::GREETING_KINDS,
            'members' => $members,
        ];
    }

    /** @return array<string, mixed> */
    private function handleGreeting(ExtensionRequest $req): array
    {
        $greeter = (string) $req->userDid;
        $body = $req->json();
        $subject = is_string($body['subjectDid'] ?? null) ? trim($body['subjectDid']) : '';
        $kind = is_string($body['kind'] ?? null) ? trim($body['kind']) : '';
        $on = !array_key_exists('on', $body) || !empty($body['on']);

        if ($subject === '' || !$this->isActiveDid($subject)) {
            throw new AppError(404, 'not_found', 'Member not found.');
        }
        if ($subject === $greeter) {
            throw new AppError(400, 'self_greeting', 'You cannot greet yourself.');
        }
        if (!in_array($kind, self::GREETING_KINDS, true)) {
            throw new AppError(400, 'invalid_greeting', 'Unknown greeting kind.');
        }
        if (!$this->checkLimit("greet:{$greeter}", 3600, self::GREETINGS_PER_HOUR, $greeter)) {
            throw new AppError(429, 'rate_limited', 'Too many greetings. Try again later.');
        }

        $pdo = $this->ctx->pdo();
        if ($on) {
            $sql = $this->ctx->driver() === 'sqlite'
                ? "INSERT OR IGNORE INTO {$this->tGreetings} (subject_did, greeter_did, kind, created_at) VALUES (?, ?, ?, ?)"
                : "INSERT IGNORE INTO {$this->tGreetings} (subject_did, greeter_did, kind, created_at) VALUES (?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([$subject, $greeter, $kind, $this->ctx->isoNow()]);
        } else {
            $pdo->prepare("DELETE FROM {$this->tGreetings} WHERE subject_did = ? AND greeter_did = ? AND kind = ?")
                ->execute([$subject, $greeter, $kind]);
        }

        $counts = [];
        $c = $pdo->prepare(
            "SELECT g.kind, COUNT(*) AS n FROM {$this->tGreetings} g
             JOIN entries ge ON ge.did = g.greeter_did AND ge.status = 'active'
             WHERE g.subject_did = ? GROUP BY g.kind"
        );
        $c->execute([$subject]);
        foreach ($c->fetchAll() as $r) {
            $counts[(string) $r['kind']] = (int) $r['n'];
        }
        $m = $pdo->prepare("SELECT kind FROM {$this->tGreetings} WHERE subject_did = ? AND greeter_did = ?");
        $m->execute([$subject, $greeter]);
        return [
            'subjectDid' => $subject,
            'counts' => (object) $counts,
            'myGreetings' => array_map('strval', $m->fetchAll(PDO::FETCH_COLUMN)),
        ];
    }

    /** @return array<string, mixed> */
    private function handleSpotlight(ExtensionRequest $req): array
    {
        $this->requireReader($req);
        return $this->loadSpotlight($req->userDid);
    }

    /** @return array<string, mixed> */
    private function loadSpotlight(?string $viewerDid): array
    {
        $today = gmdate('Y-m-d');
        if (!$this->spotlightEnabled()) {
            return ['enabled' => false, 'date' => $today, 'member' => null];
        }
        $pdo = $this->ctx->pdo();
        $cutoff = gmdate('Y-m-d\TH:i:s.000\Z', time() - 30 * 86400);
        $stmt = $pdo->prepare(
            "SELECT DISTINCT e.did, e.display_name, e.handle, e.site_url, e.avatar_url, e.bio
             FROM entries e
             JOIN {$this->tItems} i ON i.did = e.did
             WHERE e.status = 'active' AND i.content_type = 'post' AND i.published_at >= ?
               AND NOT EXISTS (SELECT 1 FROM {$this->tHidden} h WHERE h.item_did = i.did AND h.item_id = i.item_id)"
        );
        $stmt->execute([$cutoff]);
        $candidates = $stmt->fetchAll();
        if ($candidates === []) {
            return ['enabled' => true, 'date' => $today, 'member' => null];
        }

        // Deterministic daily pick: lowest sha256(date + "\n" + did).
        $picked = null;
        $bestHash = null;
        foreach ($candidates as $cand) {
            $h = hash('sha256', $today . "\n" . (string) $cand['did']);
            if ($bestHash === null || $h < $bestHash) {
                $bestHash = $h;
                $picked = $cand;
            }
        }
        $did = (string) $picked['did'];

        // Latest non-hidden post by the spotlighted member.
        $pStmt = $pdo->prepare(
            "SELECT i.*, e.site_url, e.handle, e.display_name, e.avatar_url
             FROM {$this->tItems} i JOIN entries e ON e.did = i.did
             WHERE i.did = ? AND i.content_type = 'post'
               AND NOT EXISTS (SELECT 1 FROM {$this->tHidden} h WHERE h.item_did = i.did AND h.item_id = i.item_id)
             ORDER BY i.published_at DESC LIMIT 1"
        );
        $pStmt->execute([$did]);
        $pRow = $pStmt->fetch();
        $latestPost = null;
        if (is_array($pRow)) {
            $items = [$this->shapeItem($pRow)];
            $this->decorate($items, $viewerDid, true);
            $latestPost = $items[0];
        }

        $vouchCount = 0;
        if (!$this->isVouchHiddenBySubject($did)) {
            $vc = $pdo->prepare(
                "SELECT COUNT(DISTINCT v.voucher_did) FROM {$this->tVouches} v
                 JOIN entries ve ON ve.did = v.voucher_did AND ve.status = 'active'
                 WHERE v.subject_did = ? AND NOT EXISTS (SELECT 1 FROM {$this->tHidden} h WHERE h.item_key = v.item_key)"
            );
            $vc->execute([$did]);
            $vouchCount = (int) $vc->fetchColumn();
        }
        [$roles, $badges] = $this->rolesAndBadgesFor([$did]);

        return [
            'enabled' => true,
            'date' => $today,
            'member' => [
                'did' => $did,
                'displayName' => (string) $picked['display_name'],
                'handle' => (string) $picked['handle'],
                'siteUrl' => (string) $picked['site_url'],
                'avatarUrl' => preg_match('#^https://#i', (string) $picked['avatar_url']) ? (string) $picked['avatar_url'] : '',
                'bio' => mb_substr((string) ($picked['bio'] ?? ''), 0, 240),
                'roles' => $roles[$did] ?? [],
                'badges' => $badges[$did] ?? [],
                'vouchCount' => $vouchCount,
                'latestPost' => $latestPost,
            ],
        ];
    }

    // =========================================================================
    // T3.3 Routes & computation: Roles & Badges
    // =========================================================================

    /** @return array<string, mixed> */
    private function handleAdminRoles(): array
    {
        $rows = $this->ctx->pdo()->query(
            "SELECT r.id, r.did, r.role, r.assigned_at, e.display_name
             FROM {$this->tRoles} r LEFT JOIN entries e ON e.did = r.did
             ORDER BY r.assigned_at DESC LIMIT 200"
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'did' => (string) $r['did'],
                'member' => trim((string) ($r['display_name'] ?? '')) !== '' ? (string) $r['display_name'] : (string) $r['did'],
                'role' => (string) $r['role'],
                'assignedAt' => (string) $r['assigned_at'],
            ];
        }
        return ['roles' => $out, 'allowedRoles' => $this->allowedRoles()];
    }

    /** @return array<string, mixed> */
    private function handleAdminAssignRole(ExtensionRequest $req): array
    {
        $body = $req->json();
        $did = is_string($body['did'] ?? null) ? trim($body['did']) : '';
        $role = is_string($body['role'] ?? null) ? mb_strtolower(trim($body['role'])) : '';
        if ($did === '' || !$this->isActiveDid($did)) {
            throw new AppError(404, 'not_found', 'Active member not found for that DID.');
        }
        $allowed = $this->allowedRoles();
        if (!in_array($role, $allowed, true)) {
            throw new AppError(400, 'invalid_role', 'Role must be one of: ' . implode(', ', $allowed) . '.');
        }
        $now = $this->ctx->isoNow();
        $sql = $this->ctx->driver() === 'sqlite'
            ? "INSERT OR IGNORE INTO {$this->tRoles} (did, role, assigned_at) VALUES (?, ?, ?)"
            : "INSERT IGNORE INTO {$this->tRoles} (did, role, assigned_at) VALUES (?, ?, ?)";
        $this->ctx->pdo()->prepare($sql)->execute([$did, $role, $now]);
        return [201, ['did' => $did, 'role' => $role, 'assignedAt' => $now]];
    }

    /** @return array<string, mixed> */
    private function handleAdminRemoveRole(string $id): array
    {
        if (!ctype_digit($id)) {
            throw new AppError(404, 'not_found', 'No such role assignment.');
        }
        $stmt = $this->ctx->pdo()->prepare("DELETE FROM {$this->tRoles} WHERE id = ?");
        $stmt->execute([(int) $id]);
        if ($stmt->rowCount() === 0) {
            throw new AppError(404, 'not_found', 'No such role assignment.');
        }
        return ['status' => 'removed'];
    }

    /** @return array<string, mixed> */
    private function handleAdminRecomputeBadges(): array
    {
        $awarded = $this->recomputeBadges();
        return ['status' => 'ok', 'badgesTotal' => $awarded];
    }

    /**
     * Recomputes earned badges for all active members (called on cron.refresh and migration 3).
     * Never revokes an already-earned badge while the member remains active; removal/block purges them.
     */
    private function recomputeBadges(): int
    {
        if (!$this->belongingReady) {
            return 0;
        }
        $pdo = $this->ctx->pdo();
        $now = $this->ctx->isoNow();
        $ins = $pdo->prepare(
            $this->ctx->driver() === 'sqlite'
                ? "INSERT OR IGNORE INTO {$this->tBadges} (did, badge, earned_at) VALUES (?, ?, ?)"
                : "INSERT IGNORE INTO {$this->tBadges} (did, badge, earned_at) VALUES (?, ?, ?)"
        );

        $award = static function (array $dids, string $badge) use ($ins, $now): void {
            foreach ($dids as $d) {
                if (is_string($d) && $d !== '') {
                    $ins->execute([$d, $badge, $now]);
                }
            }
        };

        // 1. host: hosted 3+ events
        if ($this->groupsReady) {
            $minHost = $this->badgeThreshold('host', 3);
            $s = $pdo->query(
                "SELECT ev.item_did FROM {$this->tEvents} ev
                 JOIN entries e ON e.did = ev.item_did AND e.status = 'active'
                 GROUP BY ev.item_did HAVING COUNT(*) >= {$minHost}"
            );
            $award($s->fetchAll(PDO::FETCH_COLUMN), 'host');

            // 2. helper: 3+ offers closed
            $minHelper = $this->badgeThreshold('helper', 3);
            $s = $pdo->query(
                "SELECT l.item_did FROM {$this->tListings} l
                 JOIN entries e ON e.did = l.item_did AND e.status = 'active'
                 WHERE l.kind = 'offer' AND l.status = 'closed'
                 GROUP BY l.item_did HAVING COUNT(*) >= {$minHelper}"
            );
            $award($s->fetchAll(PDO::FETCH_COLUMN), 'helper');
        }

        // 3. voice: 25+ replies
        $minVoice = $this->badgeThreshold('voice', 25);
        $s = $pdo->query(
            "SELECT c.did FROM {$this->tItems} c
             JOIN entries e ON e.did = c.did AND e.status = 'active'
             WHERE c.content_type = 'comment' AND c.target_url IS NOT NULL
               AND EXISTS (SELECT 1 FROM {$this->tItems} p WHERE p.canonical_url = c.target_url AND p.did <> c.did)
             GROUP BY c.did HAVING COUNT(*) >= {$minVoice}"
        );
        $award($s->fetchAll(PDO::FETCH_COLUMN), 'voice');

        // 4. welcomer: greeted 10+ distinct newcomers
        $minWelcomer = $this->badgeThreshold('welcomer', 10);
        $s = $pdo->query(
            "SELECT g.greeter_did FROM {$this->tGreetings} g
             JOIN entries e ON e.did = g.greeter_did AND e.status = 'active'
             GROUP BY g.greeter_did HAVING COUNT(DISTINCT g.subject_did) >= {$minWelcomer}"
        );
        $award($s->fetchAll(PDO::FETCH_COLUMN), 'welcomer');

        // 5. voucher: 5+ vouches given
        $minVoucher = $this->badgeThreshold('voucher', 5);
        $s = $pdo->query(
            "SELECT v.voucher_did FROM {$this->tVouches} v
             JOIN entries e ON e.did = v.voucher_did AND e.status = 'active'
             GROUP BY v.voucher_did HAVING COUNT(*) >= {$minVoucher}"
        );
        $award($s->fetchAll(PDO::FETCH_COLUMN), 'voucher');

        // 6. founding: listed in the first 30 days of the hub
        $earliest = $pdo->query("SELECT MIN(created_at) FROM entries")->fetchColumn();
        if (is_string($earliest) && $earliest !== '') {
            $ts = strtotime($earliest);
            if ($ts !== false) {
                $cutoff = gmdate('Y-m-d\TH:i:s.000\Z', $ts + $this->badgeThreshold('foundingDays', 30) * 86400);
                $s = $pdo->prepare("SELECT did FROM entries WHERE status = 'active' AND created_at <= ?");
                $s->execute([$cutoff]);
                $award($s->fetchAll(PDO::FETCH_COLUMN), 'founding');
            }
        }

        $this->recomputeChallengeBadges();
        return (int) $pdo->query("SELECT COUNT(*) FROM {$this->tBadges}")->fetchColumn();
    }

    // =========================================================================
    // T3.4 Routes: Guestbooks (owner opt-in)
    // =========================================================================

    private function isGuestbookEnabled(string $ownerDid): bool
    {
        $stmt = $this->ctx->pdo()->prepare("SELECT enabled FROM {$this->tGuestbooks} WHERE owner_did = ?");
        $stmt->execute([$ownerDid]);
        return (int) $stmt->fetchColumn() === 1;
    }

    /** @return array<string, mixed> */
    private function handleGetGuestbook(ExtensionRequest $req): array
    {
        $this->requireReader($req);
        $ownerDid = is_string($req->query['did'] ?? null) ? trim($req->query['did']) : '';
        if ($ownerDid === '') {
            throw new AppError(400, 'missing_did', 'Provide a member DID.');
        }
        $pdo = $this->ctx->pdo();
        $oStmt = $pdo->prepare("SELECT did, display_name, handle, site_url, avatar_url FROM entries WHERE did = ? AND status = 'active'");
        $oStmt->execute([$ownerDid]);
        $owner = $oStmt->fetch();
        if (!is_array($owner)) {
            throw new AppError(404, 'not_found', 'Member not found.');
        }

        $enabled = $this->isGuestbookEnabled($ownerDid);
        $isOwner = $req->userDid === $ownerDid;
        $entries = [];
        if ($enabled) {
            $stmt = $pdo->prepare(
                "SELECT g.id, g.owner_did, g.author_did, g.body, g.created_at,
                        e.display_name, e.handle, e.site_url, e.avatar_url
                 FROM {$this->tGuestbookEntries} g
                 JOIN entries e ON e.did = g.author_did AND e.status = 'active'
                 WHERE g.owner_did = ? AND g.hidden = 0
                 ORDER BY g.created_at DESC, g.id DESC LIMIT 100"
            );
            $stmt->execute([$ownerDid]);
            $rows = $stmt->fetchAll();
            $aDids = array_map(static fn (array $r): string => (string) $r['author_did'], $rows);
            [$roles, $badges] = $this->rolesAndBadgesFor($aDids);
            foreach ($rows as $r) {
                $ad = (string) $r['author_did'];
                $entries[] = [
                    'id' => (int) $r['id'],
                    'ownerDid' => (string) $r['owner_did'],
                    'body' => (string) $r['body'],
                    'createdAt' => (string) $r['created_at'],
                    'canDelete' => $req->userDid !== null && ($req->userDid === $ownerDid || $req->userDid === $ad),
                    'author' => [
                        'did' => $ad,
                        'displayName' => (string) $r['display_name'],
                        'handle' => (string) $r['handle'],
                        'siteUrl' => (string) $r['site_url'],
                        'avatarUrl' => preg_match('#^https://#i', (string) $r['avatar_url']) ? (string) $r['avatar_url'] : '',
                        'roles' => $roles[$ad] ?? [],
                        'badges' => $badges[$ad] ?? [],
                    ],
                ];
            }
        }
        return [
            'ownerDid' => $ownerDid,
            'ownerName' => (string) $owner['display_name'],
            'enabled' => $enabled,
            'isOwner' => $isOwner,
            'entries' => $entries,
        ];
    }

    /** @return array<string, mixed> */
    private function handleSetGuestbookEnabled(ExtensionRequest $req): array
    {
        $did = (string) $req->userDid;
        $body = $req->json();
        $enabled = !empty($body['enabled']) ? 1 : 0;
        $now = $this->ctx->isoNow();
        $sql = $this->ctx->driver() === 'sqlite'
            ? "INSERT INTO {$this->tGuestbooks} (owner_did, enabled, created_at) VALUES (?, ?, ?)
               ON CONFLICT(owner_did) DO UPDATE SET enabled = excluded.enabled"
            : "INSERT INTO {$this->tGuestbooks} (owner_did, enabled, created_at) VALUES (?, ?, ?)
               ON DUPLICATE KEY UPDATE enabled = VALUES(enabled)";
        $this->ctx->pdo()->prepare($sql)->execute([$did, $enabled, $now]);
        return ['enabled' => $enabled === 1];
    }

    /** @return array<string, mixed> */
    private function handleSignGuestbook(ExtensionRequest $req): array
    {
        $authorDid = (string) $req->userDid;
        $body = $req->json();
        $ownerDid = is_string($body['ownerDid'] ?? null) ? trim($body['ownerDid']) : '';
        $text = is_string($body['body'] ?? null) ? trim(preg_replace('/\s+/u', ' ', $body['body']) ?? '') : '';

        if ($ownerDid === '' || !$this->isActiveDid($ownerDid)) {
            throw new AppError(404, 'not_found', 'Member not found.');
        }
        if (!$this->isGuestbookEnabled($ownerDid)) {
            throw new AppError(403, 'guestbook_disabled', 'This member has not turned on their guestbook.');
        }
        if ($text === '' || mb_strlen($text) > self::GUESTBOOK_MAX_LEN) {
            throw new AppError(400, 'invalid_body', 'Guestbook messages must be between 1 and ' . self::GUESTBOOK_MAX_LEN . ' characters.');
        }

        // One entry per author per owner per UTC day.
        $todayPrefix = gmdate('Y-m-d');
        $pdo = $this->ctx->pdo();
        $dup = $pdo->prepare(
            "SELECT 1 FROM {$this->tGuestbookEntries}
             WHERE owner_did = ? AND author_did = ? AND created_at >= ? LIMIT 1"
        );
        $dup->execute([$ownerDid, $authorDid, $todayPrefix . 'T00:00:00.000Z']);
        if ($dup->fetchColumn()) {
            throw new AppError(429, 'already_signed_today', 'You can sign this guestbook once per day.');
        }

        if (!$this->checkLimit("gb:{$authorDid}", 86400, self::GUESTBOOK_PER_DAY, $authorDid)) {
            throw new AppError(429, 'rate_limited', 'You have reached the daily limit for signing guestbooks.');
        }

        $now = $this->ctx->isoNow();
        $pdo->prepare(
            "INSERT INTO {$this->tGuestbookEntries} (owner_did, author_did, body, created_at, hidden) VALUES (?, ?, ?, ?, 0)"
        )->execute([$ownerDid, $authorDid, $text, $now]);
        $id = (int) $pdo->lastInsertId();

        return [201, [
            'entry' => [
                'id' => $id,
                'ownerDid' => $ownerDid,
                'authorDid' => $authorDid,
                'body' => $text,
                'createdAt' => $now,
            ],
        ]];
    }

    /** @return array<string, mixed> */
    private function handleDeleteGuestbookEntry(string $id, ExtensionRequest $req): array
    {
        if (!ctype_digit($id)) {
            throw new AppError(404, 'not_found', 'No such guestbook entry.');
        }
        $pdo = $this->ctx->pdo();
        $stmt = $pdo->prepare("SELECT id, owner_did, author_did FROM {$this->tGuestbookEntries} WHERE id = ?");
        $stmt->execute([(int) $id]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new AppError(404, 'not_found', 'No such guestbook entry.');
        }
        $viewer = (string) $req->userDid;
        if ($viewer !== (string) $row['owner_did'] && $viewer !== (string) $row['author_did']) {
            throw new AppError(403, 'forbidden', 'Only the guestbook owner or the entry author can delete it.');
        }
        $pdo->prepare("DELETE FROM {$this->tGuestbookReports} WHERE entry_id = ?")->execute([(int) $id]);
        $pdo->prepare("DELETE FROM {$this->tGuestbookEntries} WHERE id = ?")->execute([(int) $id]);
        return ['status' => 'deleted'];
    }

    private function handleGuestbookCsv(ExtensionRequest $req): ExtensionRawResponse
    {
        $ownerDid = (string) $req->userDid;
        $stmt = $this->ctx->pdo()->prepare(
            "SELECT g.created_at, g.author_did, e.display_name, e.site_url, g.body
             FROM {$this->tGuestbookEntries} g
             LEFT JOIN entries e ON e.did = g.author_did
             WHERE g.owner_did = ? AND g.hidden = 0
             ORDER BY g.created_at DESC, g.id DESC LIMIT 2000"
        );
        $stmt->execute([$ownerDid]);
        $csvCell = static function (string $s): string {
            if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
                $s = "'" . $s;
            }
            return '"' . str_replace('"', '""', $s) . '"';
        };
        $lines = ["created_at,author_did,author_name,author_site_url,body"];
        foreach ($stmt->fetchAll() as $r) {
            $lines[] = implode(',', [
                $csvCell((string) $r['created_at']),
                $csvCell((string) $r['author_did']),
                $csvCell((string) ($r['display_name'] ?? '')),
                $csvCell((string) ($r['site_url'] ?? '')),
                $csvCell((string) $r['body']),
            ]);
        }
        return new ExtensionRawResponse(200, implode("\r\n", $lines) . "\r\n", 'text/csv', [
            'Content-Disposition' => 'attachment; filename="guestbook.csv"',
        ]);
    }

    /** @return array<string, mixed> */
    private function handleReportGuestbookEntry(string $id, ExtensionRequest $req): array
    {
        if (!ctype_digit($id)) {
            throw new AppError(404, 'not_found', 'No such guestbook entry.');
        }
        $pdo = $this->ctx->pdo();
        $stmt = $pdo->prepare("SELECT id, author_did FROM {$this->tGuestbookEntries} WHERE id = ?");
        $stmt->execute([(int) $id]);
        $entry = $stmt->fetch();
        if (!is_array($entry)) {
            throw new AppError(404, 'not_found', 'No such guestbook entry.');
        }
        $reporter = (string) $req->userDid;
        if ($reporter === (string) $entry['author_did']) {
            throw new AppError(400, 'self_report', 'You can delete your own guestbook entry directly.');
        }
        $body = $req->json();
        $reason = is_string($body['reason'] ?? null) ? trim($body['reason']) : '';
        if (!in_array($reason, self::REPORT_REASONS, true)) {
            throw new AppError(400, 'invalid_reason', 'Unknown report reason.');
        }
        $note = is_string($body['note'] ?? null) ? mb_substr(trim($body['note']), 0, 500) : '';
        if (!$this->checkLimit("report:{$reporter}", 86400, self::REPORTS_PER_DAY, $reporter)) {
            throw new AppError(429, 'rate_limited', 'Too many reports today.');
        }
        $now = $this->ctx->isoNow();
        $sql = $this->ctx->driver() === 'sqlite'
            ? "INSERT INTO {$this->tGuestbookReports} (entry_id, reporter_did, reason, note, status, created_at)
               VALUES (?, ?, ?, ?, 'open', ?)
               ON CONFLICT(entry_id, reporter_did) DO UPDATE SET reason = excluded.reason, note = excluded.note, status = 'open', resolved_at = NULL"
            : "INSERT INTO {$this->tGuestbookReports} (entry_id, reporter_did, reason, note, status, created_at)
               VALUES (?, ?, ?, ?, 'open', ?)
               ON DUPLICATE KEY UPDATE reason = VALUES(reason), note = VALUES(note), status = 'open', resolved_at = NULL";
        $pdo->prepare($sql)->execute([(int) $id, $reporter, $reason, $note, $now]);

        $autoHidden = false;
        $threshold = $this->autoHideThreshold();
        if ($threshold > 0) {
            $c = $pdo->prepare("SELECT COUNT(DISTINCT reporter_did) FROM {$this->tGuestbookReports} WHERE entry_id = ? AND status = 'open'");
            $c->execute([(int) $id]);
            if ((int) $c->fetchColumn() >= $threshold) {
                $pdo->prepare("UPDATE {$this->tGuestbookEntries} SET hidden = 1 WHERE id = ?")->execute([(int) $id]);
                $autoHidden = true;
            }
        }
        return [201, ['status' => 'reported', 'autoHidden' => $autoHidden]];
    }

    /** @return array<string, mixed> */
    private function handleAdminGuestbookReports(ExtensionRequest $req): array
    {
        $status = is_string($req->query['status'] ?? null) ? $req->query['status'] : 'open';
        if (!in_array($status, ['open', 'hidden', 'dismissed'], true)) {
            $status = 'open';
        }
        $stmt = $this->ctx->pdo()->prepare(
            "SELECT MIN(r.id) AS id, r.entry_id, COUNT(*) AS report_count,
                    MIN(r.created_at) AS first_reported, GROUP_CONCAT(r.reason) AS reasons,
                    g.body, g.hidden, g.author_did, e.display_name
             FROM {$this->tGuestbookReports} r
             JOIN {$this->tGuestbookEntries} g ON g.id = r.entry_id
             LEFT JOIN entries e ON e.did = g.author_did
             WHERE r.status = ?
             GROUP BY r.entry_id, g.body, g.hidden, g.author_did, e.display_name
             ORDER BY first_reported ASC LIMIT 100"
        );
        $stmt->execute([$status]);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $reasons = array_values(array_unique(explode(',', (string) $row['reasons'])));
            $out[] = [
                'id' => (int) $row['id'],
                'entryId' => (int) $row['entry_id'],
                'author' => trim((string) ($row['display_name'] ?? '')) !== '' ? (string) $row['display_name'] : (string) $row['author_did'],
                'excerpt' => mb_substr((string) $row['body'], 0, 140),
                'reasons' => implode(', ', $reasons),
                'reportCount' => (int) $row['report_count'],
                'visibility' => (int) $row['hidden'] === 1 ? 'hidden' : 'visible',
                'firstReported' => (string) $row['first_reported'],
            ];
        }
        return ['reports' => $out];
    }

    /** @return array<string, mixed> */
    private function handleAdminGuestbookHidden(): array
    {
        $rows = $this->ctx->pdo()->query(
            "SELECT g.id, g.author_did, g.owner_did, g.body, g.created_at, e.display_name
             FROM {$this->tGuestbookEntries} g LEFT JOIN entries e ON e.did = g.author_did
             WHERE g.hidden = 1 ORDER BY g.created_at DESC LIMIT 100"
        )->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id' => (int) $r['id'],
                'author' => trim((string) ($r['display_name'] ?? '')) !== '' ? (string) $r['display_name'] : (string) $r['author_did'],
                'excerpt' => mb_substr((string) $r['body'], 0, 140),
                'createdAt' => (string) $r['created_at'],
            ];
        }
        return ['hidden' => $out];
    }

    /** @return array<string, mixed> */
    private function handleAdminGuestbookHide(string $reportId): array
    {
        $rep = $this->guestbookReportById($reportId);
        $entryId = (int) $rep['entry_id'];
        $pdo = $this->ctx->pdo();
        $pdo->prepare("UPDATE {$this->tGuestbookEntries} SET hidden = 1 WHERE id = ?")->execute([$entryId]);
        $pdo->prepare("UPDATE {$this->tGuestbookReports} SET status = 'hidden', resolved_at = ? WHERE entry_id = ? AND status = 'open'")
            ->execute([$this->ctx->isoNow(), $entryId]);
        return ['status' => 'hidden'];
    }

    /** @return array<string, mixed> */
    private function handleAdminGuestbookDismiss(string $reportId): array
    {
        $rep = $this->guestbookReportById($reportId);
        $entryId = (int) $rep['entry_id'];
        $pdo = $this->ctx->pdo();
        $pdo->prepare("UPDATE {$this->tGuestbookEntries} SET hidden = 0 WHERE id = ?")->execute([$entryId]);
        $pdo->prepare("UPDATE {$this->tGuestbookReports} SET status = 'dismissed', resolved_at = ? WHERE entry_id = ? AND status = 'open'")
            ->execute([$this->ctx->isoNow(), $entryId]);
        return ['status' => 'dismissed'];
    }

    /** @return array<string, mixed> */
    private function handleAdminGuestbookRestore(string $entryId): array
    {
        if (!ctype_digit($entryId)) {
            throw new AppError(404, 'not_found', 'No such guestbook entry.');
        }
        $stmt = $this->ctx->pdo()->prepare("UPDATE {$this->tGuestbookEntries} SET hidden = 0 WHERE id = ? AND hidden = 1");
        $stmt->execute([(int) $entryId]);
        if ($stmt->rowCount() === 0) {
            throw new AppError(404, 'not_found', 'No such hidden guestbook entry.');
        }
        return ['status' => 'restored'];
    }

    /** @return array<string, mixed> */
    private function guestbookReportById(string $id): array
    {
        if (!ctype_digit($id)) {
            throw new AppError(404, 'not_found', 'No such guestbook report.');
        }
        $stmt = $this->ctx->pdo()->prepare("SELECT * FROM {$this->tGuestbookReports} WHERE id = ?");
        $stmt->execute([(int) $id]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new AppError(404, 'not_found', 'No such guestbook report.');
        }
        return $row;
    }

    // =========================================================================
    // Schema (migration 3)
    // =========================================================================

    private function createBelongingTables(PDO $pdo, string $driver): void
    {
        if ($driver === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS {$this->tVouches} (
                  item_key TEXT PRIMARY KEY,
                  item_did TEXT NOT NULL,
                  item_id TEXT NOT NULL,
                  voucher_did TEXT NOT NULL,
                  subject_did TEXT NOT NULL,
                  kind TEXT NOT NULL,
                  skill TEXT NOT NULL DEFAULT '',
                  published_at TEXT NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_{$this->tVouches}_subject ON {$this->tVouches}(subject_did);
                CREATE INDEX IF NOT EXISTS idx_{$this->tVouches}_voucher ON {$this->tVouches}(voucher_did);
                CREATE INDEX IF NOT EXISTS idx_{$this->tVouches}_item_did ON {$this->tVouches}(item_did);

                CREATE TABLE IF NOT EXISTS {$this->tVouchPrefs} (
                  did TEXT PRIMARY KEY,
                  hide_received INTEGER NOT NULL DEFAULT 0,
                  updated_at TEXT NOT NULL
                );

                CREATE TABLE IF NOT EXISTS {$this->tGreetings} (
                  subject_did TEXT NOT NULL,
                  greeter_did TEXT NOT NULL,
                  kind TEXT NOT NULL,
                  created_at TEXT NOT NULL,
                  PRIMARY KEY (subject_did, greeter_did, kind)
                );
                CREATE INDEX IF NOT EXISTS idx_{$this->tGreetings}_greeter ON {$this->tGreetings}(greeter_did);

                CREATE TABLE IF NOT EXISTS {$this->tRoles} (
                  id INTEGER PRIMARY KEY AUTOINCREMENT,
                  did TEXT NOT NULL,
                  role TEXT NOT NULL,
                  assigned_at TEXT NOT NULL,
                  UNIQUE (did, role)
                );
                CREATE INDEX IF NOT EXISTS idx_{$this->tRoles}_did ON {$this->tRoles}(did);

                CREATE TABLE IF NOT EXISTS {$this->tBadges} (
                  did TEXT NOT NULL,
                  badge TEXT NOT NULL,
                  earned_at TEXT NOT NULL,
                  PRIMARY KEY (did, badge)
                );

                CREATE TABLE IF NOT EXISTS {$this->tGuestbooks} (
                  owner_did TEXT PRIMARY KEY,
                  enabled INTEGER NOT NULL DEFAULT 1,
                  created_at TEXT NOT NULL
                );

                CREATE TABLE IF NOT EXISTS {$this->tGuestbookEntries} (
                  id INTEGER PRIMARY KEY AUTOINCREMENT,
                  owner_did TEXT NOT NULL,
                  author_did TEXT NOT NULL,
                  body TEXT NOT NULL,
                  created_at TEXT NOT NULL,
                  hidden INTEGER NOT NULL DEFAULT 0
                );
                CREATE INDEX IF NOT EXISTS idx_{$this->tGuestbookEntries}_owner ON {$this->tGuestbookEntries}(owner_did, created_at);
                CREATE INDEX IF NOT EXISTS idx_{$this->tGuestbookEntries}_author ON {$this->tGuestbookEntries}(author_did);

                CREATE TABLE IF NOT EXISTS {$this->tGuestbookReports} (
                  id INTEGER PRIMARY KEY AUTOINCREMENT,
                  entry_id INTEGER NOT NULL,
                  reporter_did TEXT NOT NULL,
                  reason TEXT NOT NULL,
                  note TEXT NOT NULL DEFAULT '',
                  status TEXT NOT NULL DEFAULT 'open',
                  created_at TEXT NOT NULL,
                  resolved_at TEXT DEFAULT NULL,
                  UNIQUE (entry_id, reporter_did)
                );
                CREATE INDEX IF NOT EXISTS idx_{$this->tGuestbookReports}_status ON {$this->tGuestbookReports}(status, created_at);
            ");
            return;
        }

        $did = 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL';
        $key = 'CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL';
        $tail = 'ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tVouches} (
              item_key {$key},
              item_did {$did},
              item_id {$did},
              voucher_did {$did},
              subject_did {$did},
              kind VARCHAR(16) NOT NULL,
              skill VARCHAR(60) NOT NULL DEFAULT '',
              published_at VARCHAR(40) NOT NULL,
              PRIMARY KEY (item_key),
              KEY idx_vouches_subject (subject_did),
              KEY idx_vouches_voucher (voucher_did),
              KEY idx_vouches_item_did (item_did)
            ) {$tail}
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tVouchPrefs} (
              did {$did},
              hide_received TINYINT(1) NOT NULL DEFAULT 0,
              updated_at VARCHAR(40) NOT NULL,
              PRIMARY KEY (did)
            ) {$tail}
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tGreetings} (
              subject_did {$did},
              greeter_did {$did},
              kind VARCHAR(16) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              created_at VARCHAR(40) NOT NULL,
              PRIMARY KEY (subject_did, greeter_did, kind),
              KEY idx_greetings_greeter (greeter_did)
            ) {$tail}
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tRoles} (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              did {$did},
              role VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              assigned_at VARCHAR(40) NOT NULL,
              PRIMARY KEY (id),
              UNIQUE KEY uq_roles_did_role (did, role),
              KEY idx_roles_did (did)
            ) {$tail}
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tBadges} (
              did {$did},
              badge VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              earned_at VARCHAR(40) NOT NULL,
              PRIMARY KEY (did, badge)
            ) {$tail}
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tGuestbooks} (
              owner_did {$did},
              enabled TINYINT(1) NOT NULL DEFAULT 1,
              created_at VARCHAR(40) NOT NULL,
              PRIMARY KEY (owner_did)
            ) {$tail}
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tGuestbookEntries} (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              owner_did {$did},
              author_did {$did},
              body VARCHAR(280) NOT NULL,
              created_at VARCHAR(40) NOT NULL,
              hidden TINYINT(1) NOT NULL DEFAULT 0,
              PRIMARY KEY (id),
              KEY idx_gb_entries_owner (owner_did, created_at),
              KEY idx_gb_entries_author (author_did)
            ) {$tail}
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tGuestbookReports} (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              entry_id BIGINT UNSIGNED NOT NULL,
              reporter_did {$did},
              reason VARCHAR(20) NOT NULL,
              note VARCHAR(500) NOT NULL DEFAULT '',
              status VARCHAR(20) NOT NULL DEFAULT 'open',
              created_at VARCHAR(40) NOT NULL,
              resolved_at VARCHAR(40) DEFAULT NULL,
              PRIMARY KEY (id),
              UNIQUE KEY uq_gb_reports_entry_reporter (entry_id, reporter_did),
              KEY idx_gb_reports_status (status, created_at)
            ) {$tail}
        ");
    }
}
