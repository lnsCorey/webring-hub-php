<?php
declare(strict_types=1);

namespace WebringHub\Ext\Groups;

use PDO;
use WebringHub\AppError;
use WebringHub\Extension;
use WebringHub\ExtensionContext;
use WebringHub\ExtensionRequest;

/**
 * Working Groups (`groups`, Tier 4.2): sub-groups with opt-in membership, organiser approvals,
 * `#wg-<slug>` scoped feeds/events (read-only from `webhook-sync` and `social`), and group-scoped
 * polls votable only by members.
 */
final class GroupsExtension implements Extension
{
    public const MAX_POLL_OPTIONS = 8;

    private ExtensionContext $ctx;
    private string $tItems = '';
    private string $tItemTags = '';
    private string $tHidden = '';
    private string $tGroups = '';
    private string $tMembers = '';
    private string $tRequests = '';
    private string $tPolls = '';
    private string $tPollOptions = '';
    private string $tPollVotes = '';

    public function register(ExtensionContext $ctx): void
    {
        $this->ctx = $ctx;
        $this->tItems = $ctx->readTable('webhook-sync', 'items');
        $this->tItemTags = $ctx->readTable('social', 'item_tags');
        $this->tHidden = $ctx->readTable('social', 'hidden');
        $this->tGroups = $ctx->table('groups');
        $this->tMembers = $ctx->table('members');
        $this->tRequests = $ctx->table('requests');
        $this->tPolls = $ctx->table('polls');
        $this->tPollOptions = $ctx->table('poll_options');
        $this->tPollVotes = $ctx->table('poll_votes');

        $ctx->migrate(1, fn (PDO $pdo, string $driver) => $this->createTables($pdo, $driver));

        $ctx->serveFiles('', 'public', [
            'csp' => "default-src 'self'; img-src 'self' https: data:; style-src 'self'; script-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'",
            'frameable' => false,
        ]);

        $ctx->on('entry.removed', fn (array $e) => $this->purgeDid((string) ($e['did'] ?? '')));
        $ctx->on('entry.status_changed', function (array $e): void {
            if (($e['status'] ?? '') === 'blocked') {
                $this->purgeDid((string) ($e['did'] ?? ''));
            }
        });
        $ctx->on('user.extension_opt_out', function (array $e): void {
            if (($e['extensionId'] ?? '') === 'groups') {
                $this->purgeDid((string) ($e['did'] ?? ''));
            }
        });

        $ctx->get('v1/groups', fn (array $p, ExtensionRequest $req) => $this->handleList($req));
        $ctx->post('v1/groups', fn (array $p, ExtensionRequest $req) => $this->handleMemberCreate($req), ['requireOptIn' => true, 'rateLimited' => true]);
        $ctx->get('v1/groups/{slug}', fn (array $p, ExtensionRequest $req) => $this->handleDetail((string) $p['slug'], $req));
        $ctx->post('v1/groups/{slug}/join', fn (array $p, ExtensionRequest $req) => $this->handleJoin((string) $p['slug'], $req), ['requireOptIn' => true]);
        $ctx->post('v1/groups/{slug}/leave', fn (array $p, ExtensionRequest $req) => $this->handleLeave((string) $p['slug'], $req), ['userAuth' => true]);
        $ctx->post('v1/groups/{slug}/requests/{did}/approve', fn (array $p, ExtensionRequest $req) => $this->handleApprove((string) $p['slug'], (string) $p['did'], $req), ['userAuth' => true]);
        $ctx->post('v1/groups/{slug}/polls', fn (array $p, ExtensionRequest $req) => $this->handleCreatePoll((string) $p['slug'], $req), ['requireOptIn' => true]);
        $ctx->post('v1/groups/{slug}/polls/{id}/vote', fn (array $p, ExtensionRequest $req) => $this->handleVote((string) $p['slug'], (string) $p['id'], $req), ['requireOptIn' => true]);

        $ctx->get('v1/admin/groups', fn () => $this->handleAdminList(), ['admin' => true]);
        $ctx->post('v1/admin/groups', fn (array $p, ExtensionRequest $req) => $this->handleAdminCreate($req), ['admin' => true]);
        $ctx->post('v1/admin/groups/{id}/archive', fn (array $p) => $this->archiveGroup((string) $p['id']), ['admin' => true]);
        $ctx->delete('v1/admin/groups/{id}', fn (array $p) => $this->deleteGroup((string) $p['id']), ['admin' => true]);
    }

    // =========================================================================
    // Settings & helpers
    // =========================================================================

    private function membersOnly(): bool
    {
        $v = $this->ctx->config()['membersOnly'] ?? true;
        return $v !== false && $v !== 0 && $v !== '0';
    }

    private function membersCreateGroups(): bool
    {
        return ($this->ctx->config()['groupCreators'] ?? 'admin') === 'members';
    }

    private function requireReader(ExtensionRequest $req): void
    {
        if ($this->membersOnly() && $req->userDid === null) {
            throw new AppError(401, 'auth_required', 'Working groups are visible to signed-in members only.');
        }
    }

    private function isActiveDid(string $did): bool
    {
        $s = $this->ctx->pdo()->prepare("SELECT 1 FROM entries WHERE did = ? AND status = 'active'");
        $s->execute([$did]);
        return (bool) $s->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function groupBySlug(string $slug): array
    {
        $s = $this->ctx->pdo()->prepare("SELECT * FROM {$this->tGroups} WHERE slug = ?");
        $s->execute([strtolower(trim($slug))]);
        $row = $s->fetch();
        if (!is_array($row)) {
            throw new AppError(404, 'not_found', 'Working group not found.');
        }
        return $row;
    }

    private function memberRole(int $groupId, ?string $did): ?string
    {
        if ($did === null || $did === '') {
            return null;
        }
        $s = $this->ctx->pdo()->prepare("SELECT role FROM {$this->tMembers} WHERE group_id = ? AND did = ?");
        $s->execute([$groupId, $did]);
        $r = $s->fetchColumn();
        return is_string($r) ? $r : null;
    }

    private function hasPendingRequest(int $groupId, ?string $did): bool
    {
        if ($did === null || $did === '') {
            return false;
        }
        $s = $this->ctx->pdo()->prepare("SELECT 1 FROM {$this->tRequests} WHERE group_id = ? AND did = ?");
        $s->execute([$groupId, $did]);
        return (bool) $s->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function createGroupRecord(string $slug, string $title, string $description, string $organizerDid, string $visibility): array
    {
        $slug = strtolower(trim($slug));
        if (!preg_match('/^[a-z0-9][a-z0-9-]{1,24}$/', $slug)) {
            throw new AppError(400, 'invalid_slug', 'Slug must be 2–25 lowercase letters, digits or hyphens.');
        }
        $title = mb_substr(trim($title), 0, 100);
        if ($title === '') {
            throw new AppError(400, 'invalid_title', 'Group title is required.');
        }
        $description = mb_substr(trim($description), 0, 500);
        $visibility = $visibility === 'request' ? 'request' : 'open';
        if ($organizerDid === '' || !$this->isActiveDid($organizerDid)) {
            throw new AppError(404, 'not_found', 'Organiser DID must be an active member.');
        }
        $pdo = $this->ctx->pdo();
        $dup = $pdo->prepare("SELECT 1 FROM {$this->tGroups} WHERE slug = ?");
        $dup->execute([$slug]);
        if ($dup->fetchColumn()) {
            throw new AppError(409, 'slug_taken', 'A working group with that slug already exists.');
        }
        $now = $this->ctx->isoNow();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "INSERT INTO {$this->tGroups} (slug, title, description, organizer_did, visibility, archived, created_at)
                 VALUES (?, ?, ?, ?, ?, 0, ?)"
            )->execute([$slug, $title, $description, $organizerDid, $visibility, $now]);
            $gid = (int) $pdo->lastInsertId();
            $pdo->prepare(
                "INSERT INTO {$this->tMembers} (group_id, did, role, joined_at) VALUES (?, ?, 'organizer', ?)"
            )->execute([$gid, $organizerDid, $now]);
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return [
            'id' => $gid,
            'slug' => $slug,
            'tag' => 'wg-' . $slug,
            'title' => $title,
            'description' => $description,
            'organizerDid' => $organizerDid,
            'visibility' => $visibility,
            'archived' => false,
        ];
    }

    // =========================================================================
    // Member routes
    // =========================================================================

    /** @return array<string, mixed> */
    private function handleList(ExtensionRequest $req): array
    {
        $this->requireReader($req);
        $rows = $this->ctx->pdo()->query("SELECT * FROM {$this->tGroups} WHERE archived = 0 ORDER BY title ASC LIMIT 100")->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $gid = (int) $r['id'];
            $mc = $this->ctx->pdo()->prepare("SELECT COUNT(*) FROM {$this->tMembers} m JOIN entries e ON e.did = m.did AND e.status = 'active' WHERE m.group_id = ?");
            $mc->execute([$gid]);
            $out[] = [
                'id' => $gid,
                'slug' => (string) $r['slug'],
                'tag' => 'wg-' . (string) $r['slug'],
                'title' => (string) $r['title'],
                'description' => (string) $r['description'],
                'visibility' => (string) $r['visibility'],
                'memberCount' => (int) $mc->fetchColumn(),
                'myRole' => $this->memberRole($gid, $req->userDid),
                'myRequestPending' => $this->hasPendingRequest($gid, $req->userDid),
            ];
        }
        return ['groups' => $out, 'membersCreateGroups' => $this->membersCreateGroups()];
    }

    /** @return array<string, mixed> */
    private function handleMemberCreate(ExtensionRequest $req): array
    {
        if (!$this->membersCreateGroups()) {
            throw new AppError(403, 'admin_only', 'Only the hub admin can create working groups.');
        }
        $body = $req->json();
        $g = $this->createGroupRecord(
            (string) ($body['slug'] ?? ''),
            (string) ($body['title'] ?? ''),
            (string) ($body['description'] ?? ''),
            (string) $req->userDid,
            (string) ($body['visibility'] ?? 'open')
        );
        return [201, ['group' => $g]];
    }

    /** @return array<string, mixed> */
    private function handleDetail(string $slug, ExtensionRequest $req): array
    {
        $this->requireReader($req);
        $g = $this->groupBySlug($slug);
        $gid = (int) $g['id'];
        $tag = 'wg-' . (string) $g['slug'];
        $pdo = $this->ctx->pdo();
        $myRole = $this->memberRole($gid, $req->userDid);

        // Scoped feed: only items tagged #wg-<slug> authored by active group members.
        $fStmt = $pdo->prepare(
            "SELECT i.did, i.item_id, i.content_type, i.canonical_url, i.published_at, i.raw_item_json,
                    e.display_name, e.handle, e.site_url
             FROM {$this->tItemTags} t
             JOIN {$this->tMembers} m ON m.group_id = ? AND m.did = t.item_did
             JOIN {$this->tItems} i ON i.did = t.item_did AND i.item_id = t.item_id
             JOIN entries e ON e.did = i.did AND e.status = 'active'
             WHERE t.tag = ?
               AND NOT EXISTS (SELECT 1 FROM {$this->tHidden} h WHERE h.item_key = t.item_key)
             ORDER BY i.published_at DESC LIMIT 50"
        );
        $fStmt->execute([$gid, $tag]);
        $feed = [];
        $events = [];
        foreach ($fStmt->fetchAll() as $row) {
            $raw = json_decode((string) $row['raw_item_json'], true);
            $raw = is_array($raw) ? $raw : [];
            $shaped = [
                'did' => (string) $row['did'],
                'itemId' => (string) $row['item_id'],
                'type' => (string) $row['content_type'],
                'canonicalUrl' => (string) $row['canonical_url'],
                'publishedAt' => (string) $row['published_at'],
                'title' => is_string($raw['title'] ?? null) ? mb_substr(trim($raw['title']), 0, 200) : '',
                'commentary' => is_string($raw['commentary'] ?? null) ? mb_substr(trim($raw['commentary']), 0, 1000) : '',
                'event' => is_array($raw['event'] ?? null) ? $raw['event'] : null,
                'author' => [
                    'did' => (string) $row['did'],
                    'displayName' => (string) $row['display_name'],
                    'handle' => (string) $row['handle'],
                    'siteUrl' => (string) $row['site_url'],
                ],
            ];
            $feed[] = $shaped;
            if ($shaped['type'] === 'post' && is_array($shaped['event']) && !empty($shaped['event']['startsAt'])) {
                $events[] = $shaped;
            }
        }

        // Member list is visible to signed-in members only.
        $members = [];
        if ($req->userDid !== null) {
            $mStmt = $pdo->prepare(
                "SELECT m.did, m.role, m.joined_at, e.display_name, e.handle, e.site_url
                 FROM {$this->tMembers} m JOIN entries e ON e.did = m.did AND e.status = 'active'
                 WHERE m.group_id = ? ORDER BY m.joined_at ASC"
            );
            $mStmt->execute([$gid]);
            foreach ($mStmt->fetchAll() as $m) {
                $members[] = [
                    'did' => (string) $m['did'],
                    'role' => (string) $m['role'],
                    'joinedAt' => (string) $m['joined_at'],
                    'displayName' => (string) $m['display_name'],
                    'handle' => (string) $m['handle'],
                    'siteUrl' => (string) $m['site_url'],
                ];
            }
        }

        // Pending requests (organiser only)
        $requests = [];
        if ($myRole === 'organizer') {
            $rStmt = $pdo->prepare(
                "SELECT r.did, r.created_at, e.display_name, e.site_url
                 FROM {$this->tRequests} r JOIN entries e ON e.did = r.did AND e.status = 'active'
                 WHERE r.group_id = ? ORDER BY r.created_at ASC"
            );
            $rStmt->execute([$gid]);
            foreach ($rStmt->fetchAll() as $rq) {
                $requests[] = [
                    'did' => (string) $rq['did'],
                    'displayName' => (string) $rq['display_name'],
                    'siteUrl' => (string) $rq['site_url'],
                    'createdAt' => (string) $rq['created_at'],
                ];
            }
        }

        // Group polls
        $pStmt = $pdo->prepare("SELECT * FROM {$this->tPolls} WHERE group_id = ? ORDER BY created_at DESC LIMIT 20");
        $pStmt->execute([$gid]);
        $polls = [];
        foreach ($pStmt->fetchAll() as $p) {
            $pid = (int) $p['id'];
            $oStmt = $pdo->prepare("SELECT id, label FROM {$this->tPollOptions} WHERE poll_id = ? ORDER BY position ASC");
            $oStmt->execute([$pid]);
            $vCounts = [];
            $vc = $pdo->prepare("SELECT option_id, COUNT(*) AS n FROM {$this->tPollVotes} WHERE poll_id = ? GROUP BY option_id");
            $vc->execute([$pid]);
            foreach ($vc->fetchAll() as $vr) {
                $vCounts[(int) $vr['option_id']] = (int) $vr['n'];
            }
            $myVotes = [];
            if ($req->userDid !== null) {
                $mv = $pdo->prepare("SELECT option_id FROM {$this->tPollVotes} WHERE poll_id = ? AND did = ?");
                $mv->execute([$pid, $req->userDid]);
                $myVotes = array_map('intval', $mv->fetchAll(PDO::FETCH_COLUMN));
            }
            $opts = [];
            foreach ($oStmt->fetchAll() as $o) {
                $oid = (int) $o['id'];
                $opts[] = ['id' => $oid, 'label' => (string) $o['label'], 'votes' => $vCounts[$oid] ?? 0];
            }
            $polls[] = [
                'id' => $pid,
                'question' => (string) $p['question'],
                'createdAt' => (string) $p['created_at'],
                'options' => $opts,
                'myVotes' => $myVotes,
                'canVote' => $myRole !== null,
            ];
        }

        return [
            'hubTitle' => $this->ctx->hubTitle(),
            'group' => [
                'id' => $gid,
                'slug' => (string) $g['slug'],
                'tag' => $tag,
                'title' => (string) $g['title'],
                'description' => (string) $g['description'],
                'visibility' => (string) $g['visibility'],
                'archived' => (int) $g['archived'] === 1,
                'myRole' => $myRole,
                'myRequestPending' => $this->hasPendingRequest($gid, $req->userDid),
            ],
            'feed' => $feed,
            'events' => $events,
            'polls' => $polls,
            'members' => $members,
            'requests' => $requests,
        ];
    }

    /** @return array<string, mixed> */
    private function handleJoin(string $slug, ExtensionRequest $req): array
    {
        $did = (string) $req->userDid;
        $g = $this->groupBySlug($slug);
        if ((int) $g['archived'] === 1) {
            throw new AppError(409, 'archived', 'This working group is archived.');
        }
        $gid = (int) $g['id'];
        if ($this->memberRole($gid, $did) !== null) {
            return ['status' => 'member'];
        }
        $pdo = $this->ctx->pdo();
        $now = $this->ctx->isoNow();
        if ((string) $g['visibility'] === 'request') {
            $sql = $this->ctx->driver() === 'sqlite'
                ? "INSERT OR IGNORE INTO {$this->tRequests} (group_id, did, created_at) VALUES (?, ?, ?)"
                : "INSERT IGNORE INTO {$this->tRequests} (group_id, did, created_at) VALUES (?, ?, ?)";
            $pdo->prepare($sql)->execute([$gid, $did, $now]);
            return [202, ['status' => 'requested']];
        }
        $sql = $this->ctx->driver() === 'sqlite'
            ? "INSERT OR IGNORE INTO {$this->tMembers} (group_id, did, role, joined_at) VALUES (?, ?, 'member', ?)"
            : "INSERT IGNORE INTO {$this->tMembers} (group_id, did, role, joined_at) VALUES (?, ?, 'member', ?)";
        $pdo->prepare($sql)->execute([$gid, $did, $now]);
        return ['status' => 'joined'];
    }

    /** @return array<string, mixed> */
    private function handleLeave(string $slug, ExtensionRequest $req): array
    {
        $did = (string) $req->userDid;
        $g = $this->groupBySlug($slug);
        $this->removeMemberFromGroup((int) $g['id'], $did);
        return ['status' => 'left'];
    }

    /** @return array<string, mixed> */
    private function handleApprove(string $slug, string $targetDid, ExtensionRequest $req): array
    {
        $g = $this->groupBySlug($slug);
        $gid = (int) $g['id'];
        if ($this->memberRole($gid, $req->userDid) !== 'organizer') {
            throw new AppError(403, 'not_organizer', 'Only the group organiser can approve join requests.');
        }
        $pdo = $this->ctx->pdo();
        $del = $pdo->prepare("DELETE FROM {$this->tRequests} WHERE group_id = ? AND did = ?");
        $del->execute([$gid, $targetDid]);
        if ($del->rowCount() === 0) {
            throw new AppError(404, 'not_found', 'No pending join request for that member.');
        }
        $sql = $this->ctx->driver() === 'sqlite'
            ? "INSERT OR IGNORE INTO {$this->tMembers} (group_id, did, role, joined_at) VALUES (?, ?, 'member', ?)"
            : "INSERT IGNORE INTO {$this->tMembers} (group_id, did, role, joined_at) VALUES (?, ?, 'member', ?)";
        $pdo->prepare($sql)->execute([$gid, $targetDid, $this->ctx->isoNow()]);
        return ['status' => 'approved'];
    }

    /** @return array<string, mixed> */
    private function handleCreatePoll(string $slug, ExtensionRequest $req): array
    {
        $g = $this->groupBySlug($slug);
        $gid = (int) $g['id'];
        if ($this->memberRole($gid, $req->userDid) === null) {
            throw new AppError(403, 'not_member', 'Only group members can create polls in this group.');
        }
        $body = $req->json();
        $question = is_string($body['question'] ?? null) ? mb_substr(trim($body['question']), 0, 300) : '';
        $opts = array_values(array_unique(array_filter(array_map(
            static fn ($o): string => is_string($o) ? mb_substr(trim($o), 0, 120) : '',
            is_array($body['options'] ?? null) ? $body['options'] : []
        ), static fn (string $s): bool => $s !== '')));
        if ($question === '' || count($opts) < 2 || count($opts) > self::MAX_POLL_OPTIONS) {
            throw new AppError(400, 'invalid_poll', 'Provide a question and 2–' . self::MAX_POLL_OPTIONS . ' options.');
        }
        $pdo = $this->ctx->pdo();
        $now = $this->ctx->isoNow();
        $pdo->prepare("INSERT INTO {$this->tPolls} (group_id, question, created_by, created_at) VALUES (?, ?, ?, ?)")
            ->execute([$gid, $question, (string) $req->userDid, $now]);
        $pid = (int) $pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT INTO {$this->tPollOptions} (poll_id, position, label) VALUES (?, ?, ?)");
        foreach ($opts as $i => $label) {
            $ins->execute([$pid, $i, $label]);
        }
        return [201, ['pollId' => $pid]];
    }

    /** @return array<string, mixed> */
    private function handleVote(string $slug, string $pollId, ExtensionRequest $req): array
    {
        $g = $this->groupBySlug($slug);
        $gid = (int) $g['id'];
        if ($this->memberRole($gid, $req->userDid) === null) {
            throw new AppError(403, 'not_member', 'Only group members can vote in this group poll.');
        }
        if (!ctype_digit($pollId)) {
            throw new AppError(404, 'not_found', 'Poll not found.');
        }
        $pdo = $this->ctx->pdo();
        $p = $pdo->prepare("SELECT id FROM {$this->tPolls} WHERE id = ? AND group_id = ?");
        $p->execute([(int) $pollId, $gid]);
        if (!$p->fetchColumn()) {
            throw new AppError(404, 'not_found', 'Poll not found in this group.');
        }
        $body = $req->json();
        $optionId = (int) ($body['optionId'] ?? 0);
        $o = $pdo->prepare("SELECT 1 FROM {$this->tPollOptions} WHERE id = ? AND poll_id = ?");
        $o->execute([$optionId, (int) $pollId]);
        if (!$o->fetchColumn()) {
            throw new AppError(400, 'invalid_option', 'Invalid poll option.');
        }
        $did = (string) $req->userDid;
        $pdo->prepare("DELETE FROM {$this->tPollVotes} WHERE poll_id = ? AND did = ?")->execute([(int) $pollId, $did]);
        $pdo->prepare("INSERT INTO {$this->tPollVotes} (poll_id, option_id, did, created_at) VALUES (?, ?, ?, ?)")
            ->execute([(int) $pollId, $optionId, $did, $this->ctx->isoNow()]);
        return ['status' => 'voted'];
    }

    // =========================================================================
    // Admin routes
    // =========================================================================

    /** @return array<string, mixed> */
    private function handleAdminList(): array
    {
        $rows = $this->ctx->pdo()->query("SELECT * FROM {$this->tGroups} ORDER BY created_at DESC LIMIT 100")->fetchAll();
        $out = [];
        foreach ($rows as $r) {
            $mc = $this->ctx->pdo()->prepare("SELECT COUNT(*) FROM {$this->tMembers} WHERE group_id = ?");
            $mc->execute([(int) $r['id']]);
            $out[] = [
                'id' => (int) $r['id'],
                'title' => (string) $r['title'],
                'slug' => '#wg-' . (string) $r['slug'],
                'visibility' => (string) $r['visibility'],
                'members' => (int) $mc->fetchColumn(),
                'status' => (int) $r['archived'] === 1 ? 'archived' : 'active',
            ];
        }
        return ['groups' => $out];
    }

    /** @return array<string, mixed> */
    private function handleAdminCreate(ExtensionRequest $req): array
    {
        $body = $req->json();
        $g = $this->createGroupRecord(
            (string) ($body['slug'] ?? ''),
            (string) ($body['title'] ?? ''),
            (string) ($body['description'] ?? ''),
            (string) ($body['organizerDid'] ?? ''),
            (string) ($body['visibility'] ?? 'open')
        );
        return [201, ['group' => $g]];
    }

    /** @return array<string, mixed> */
    private function archiveGroup(string $id): array
    {
        if (!ctype_digit($id)) {
            throw new AppError(404, 'not_found', 'No such group.');
        }
        $this->ctx->pdo()->prepare("UPDATE {$this->tGroups} SET archived = 1 WHERE id = ?")->execute([(int) $id]);
        return ['status' => 'archived'];
    }

    /** @return array<string, mixed> */
    private function deleteGroup(string $id): array
    {
        if (!ctype_digit($id)) {
            throw new AppError(404, 'not_found', 'No such group.');
        }
        $gid = (int) $id;
        $pdo = $this->ctx->pdo();
        $pdo->prepare("DELETE FROM {$this->tPollVotes} WHERE poll_id IN (SELECT id FROM {$this->tPolls} WHERE group_id = ?)")->execute([$gid]);
        $pdo->prepare("DELETE FROM {$this->tPollOptions} WHERE poll_id IN (SELECT id FROM {$this->tPolls} WHERE group_id = ?)")->execute([$gid]);
        $pdo->prepare("DELETE FROM {$this->tPolls} WHERE group_id = ?")->execute([$gid]);
        $pdo->prepare("DELETE FROM {$this->tRequests} WHERE group_id = ?")->execute([$gid]);
        $pdo->prepare("DELETE FROM {$this->tMembers} WHERE group_id = ?")->execute([$gid]);
        $pdo->prepare("DELETE FROM {$this->tGroups} WHERE id = ?")->execute([$gid]);
        return ['status' => 'deleted'];
    }

    // =========================================================================
    // Purge & organiser handoff
    // =========================================================================

    private function removeMemberFromGroup(int $gid, string $did): void
    {
        $pdo = $this->ctx->pdo();
        $wasOrg = $this->memberRole($gid, $did) === 'organizer';
        $pdo->prepare("DELETE FROM {$this->tMembers} WHERE group_id = ? AND did = ?")->execute([$gid, $did]);
        $pdo->prepare("DELETE FROM {$this->tRequests} WHERE group_id = ? AND did = ?")->execute([$gid, $did]);
        if ($wasOrg) {
            // Pass organiser role to the longest-standing active member, or archive if empty.
            $next = $pdo->prepare(
                "SELECT m.did FROM {$this->tMembers} m JOIN entries e ON e.did = m.did AND e.status = 'active'
                 WHERE m.group_id = ? ORDER BY m.joined_at ASC LIMIT 1"
            );
            $next->execute([$gid]);
            $successor = $next->fetchColumn();
            if (is_string($successor) && $successor !== '') {
                $pdo->prepare("UPDATE {$this->tMembers} SET role = 'organizer' WHERE group_id = ? AND did = ?")->execute([$gid, $successor]);
                $pdo->prepare("UPDATE {$this->tGroups} SET organizer_did = ? WHERE id = ?")->execute([$successor, $gid]);
            } else {
                $pdo->prepare("UPDATE {$this->tGroups} SET archived = 1 WHERE id = ?")->execute([$gid]);
            }
        }
    }

    private function purgeDid(string $did): void
    {
        if ($did === '') {
            return;
        }
        $pdo = $this->ctx->pdo();
        $gids = $pdo->prepare("SELECT group_id FROM {$this->tMembers} WHERE did = ?");
        $gids->execute([$did]);
        foreach ($gids->fetchAll(PDO::FETCH_COLUMN) as $gid) {
            $this->removeMemberFromGroup((int) $gid, $did);
        }
        $pdo->prepare("DELETE FROM {$this->tRequests} WHERE did = ?")->execute([$did]);
        $pdo->prepare("DELETE FROM {$this->tPollVotes} WHERE did = ?")->execute([$did]);
    }

    // =========================================================================
    // Schema
    // =========================================================================

    private function createTables(PDO $pdo, string $driver): void
    {
        if ($driver === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS {$this->tGroups} (
                  id INTEGER PRIMARY KEY AUTOINCREMENT,
                  slug TEXT NOT NULL UNIQUE,
                  title TEXT NOT NULL,
                  description TEXT NOT NULL DEFAULT '',
                  organizer_did TEXT NOT NULL,
                  visibility TEXT NOT NULL DEFAULT 'open',
                  archived INTEGER NOT NULL DEFAULT 0,
                  created_at TEXT NOT NULL
                );
                CREATE TABLE IF NOT EXISTS {$this->tMembers} (
                  group_id INTEGER NOT NULL,
                  did TEXT NOT NULL,
                  role TEXT NOT NULL DEFAULT 'member',
                  joined_at TEXT NOT NULL,
                  PRIMARY KEY (group_id, did)
                );
                CREATE INDEX IF NOT EXISTS idx_{$this->tMembers}_did ON {$this->tMembers}(did);
                CREATE TABLE IF NOT EXISTS {$this->tRequests} (
                  group_id INTEGER NOT NULL,
                  did TEXT NOT NULL,
                  created_at TEXT NOT NULL,
                  PRIMARY KEY (group_id, did)
                );
                CREATE TABLE IF NOT EXISTS {$this->tPolls} (
                  id INTEGER PRIMARY KEY AUTOINCREMENT,
                  group_id INTEGER NOT NULL,
                  question TEXT NOT NULL,
                  created_by TEXT NOT NULL,
                  created_at TEXT NOT NULL
                );
                CREATE TABLE IF NOT EXISTS {$this->tPollOptions} (
                  id INTEGER PRIMARY KEY AUTOINCREMENT,
                  poll_id INTEGER NOT NULL,
                  position INTEGER NOT NULL,
                  label TEXT NOT NULL
                );
                CREATE TABLE IF NOT EXISTS {$this->tPollVotes} (
                  poll_id INTEGER NOT NULL,
                  option_id INTEGER NOT NULL,
                  did TEXT NOT NULL,
                  created_at TEXT NOT NULL,
                  PRIMARY KEY (poll_id, did)
                );
            ");
            return;
        }

        $did = 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL';
        $tail = 'ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tGroups} (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              slug VARCHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
              title VARCHAR(100) NOT NULL,
              description VARCHAR(500) NOT NULL DEFAULT '',
              organizer_did {$did},
              visibility VARCHAR(16) NOT NULL DEFAULT 'open',
              archived TINYINT(1) NOT NULL DEFAULT 0,
              created_at VARCHAR(40) NOT NULL,
              PRIMARY KEY (id),
              UNIQUE KEY uq_groups_slug (slug)
            ) {$tail}
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tMembers} (
              group_id BIGINT UNSIGNED NOT NULL,
              did {$did},
              role VARCHAR(16) NOT NULL DEFAULT 'member',
              joined_at VARCHAR(40) NOT NULL,
              PRIMARY KEY (group_id, did),
              KEY idx_gmembers_did (did)
            ) {$tail}
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tRequests} (
              group_id BIGINT UNSIGNED NOT NULL,
              did {$did},
              created_at VARCHAR(40) NOT NULL,
              PRIMARY KEY (group_id, did)
            ) {$tail}
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tPolls} (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              group_id BIGINT UNSIGNED NOT NULL,
              question VARCHAR(300) NOT NULL,
              created_by {$did},
              created_at VARCHAR(40) NOT NULL,
              PRIMARY KEY (id),
              KEY idx_gpolls_group (group_id)
            ) {$tail}
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tPollOptions} (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              poll_id BIGINT UNSIGNED NOT NULL,
              position INT NOT NULL,
              label VARCHAR(120) NOT NULL,
              PRIMARY KEY (id),
              KEY idx_gpoll_opts_poll (poll_id)
            ) {$tail}
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tPollVotes} (
              poll_id BIGINT UNSIGNED NOT NULL,
              option_id BIGINT UNSIGNED NOT NULL,
              did {$did},
              created_at VARCHAR(40) NOT NULL,
              PRIMARY KEY (poll_id, did)
            ) {$tail}
        ");
    }
}
