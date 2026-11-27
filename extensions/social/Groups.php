<?php
declare(strict_types=1);

namespace WebringHub\Ext\Social;

use PDO;
use WebringHub\AppError;
use WebringHub\ExtensionContext;
use WebringHub\ExtensionRawResponse;
use WebringHub\ExtensionRequest;

/**
 * Tier 2 group features for `social`: events with RSVPs, polls, the asks & offers board and the
 * community digest.
 *
 * - **Events** and **asks/offers** are members' own signed posts: a `post` item with an `event`
 *   object (`startsAt`, `endsAt`, `allDay`, `location`, `url`) or a `listing` object
 *   (`kind`: ask|offer, `status`: open|closed). Older readers simply see a post.
 * - **RSVPs** and **poll votes** are small member actions stored here under opt-in.
 * - **Polls** belong to the group, not to one member's site, so they are created on the hub (by
 *   the admin, or by members when `pollCreators` is `members`).
 */
trait Groups
{
    // Constants live on SocialExtension (constants in traits need PHP 8.2).
    private string $tEvents = '';
    private string $tRsvps = '';
    private string $tListings = '';
    private string $tPolls = '';
    private string $tPollOptions = '';
    private string $tPollVotes = '';
    private bool $groupsReady = false;

    /** @var array<string, list<array{did: string, name: string}>>|null tag => members listing it as an interest */
    private ?array $interestMap = null;

    private function registerGroups(ExtensionContext $ctx): void
    {
        $this->tEvents = $ctx->table('events');
        $this->tRsvps = $ctx->table('rsvps');
        $this->tListings = $ctx->table('listings');
        $this->tPolls = $ctx->table('polls');
        $this->tPollOptions = $ctx->table('poll_options');
        $this->tPollVotes = $ctx->table('poll_votes');

        $ctx->migrate(2, function (PDO $pdo, string $driver): void {
            $this->createGroupTables($pdo, $driver);
            $this->groupsReady = true;
            $this->reindexGroups();
        });
        $this->groupsReady = $ctx->schemaVersion() >= 2;

        $ctx->get('v1/events', fn (array $p, ExtensionRequest $req) => $this->handleEvents($req));
        $ctx->get('v1/events.ics', fn (array $p, ExtensionRequest $req) => $this->handleEventsIcs($req));
        $ctx->post('v1/rsvps', fn (array $p, ExtensionRequest $req) => $this->handleRsvp($req), ['requireOptIn' => true]);

        $ctx->get('v1/listings', fn (array $p, ExtensionRequest $req) => $this->handleListings($req));

        $ctx->get('v1/polls', fn (array $p, ExtensionRequest $req) => $this->handlePolls($req));
        $ctx->post('v1/polls', fn (array $p, ExtensionRequest $req) => $this->handleCreatePoll($req), ['requireOptIn' => true, 'rateLimited' => true]);
        $ctx->post('v1/polls/{id}/vote', fn (array $p, ExtensionRequest $req) => $this->handleVote((string) $p['id'], $req), ['requireOptIn' => true]);
        $ctx->post('v1/polls/{id}/close', fn (array $p, ExtensionRequest $req) => $this->handleMemberClosePoll((string) $p['id'], $req), ['requireOptIn' => true]);

        $ctx->get('v1/digest', fn (array $p, ExtensionRequest $req) => $this->handleDigest($req));
        $ctx->get('v1/digest.atom', fn (array $p, ExtensionRequest $req) => $this->handleDigestAtom($req));

        $ctx->get('v1/admin/polls', fn () => $this->handleAdminPolls(), ['admin' => true]);
        $ctx->post('v1/admin/polls', fn (array $p, ExtensionRequest $req) => $this->handleAdminCreatePoll($req), ['admin' => true]);
        $ctx->post('v1/admin/polls/{id}/close', fn (array $p) => $this->closePoll((string) $p['id']), ['admin' => true]);
        $ctx->delete('v1/admin/polls/{id}', fn (array $p) => $this->deletePoll((string) $p['id']), ['admin' => true]);
    }

    // =========================================================================
    // Settings
    // =========================================================================

    private function membersCreatePolls(): bool
    {
        return ($this->ctx->config()['pollCreators'] ?? 'members') !== 'admin';
    }

    private function pollResultsAlways(): bool
    {
        return ($this->ctx->config()['pollResults'] ?? 'after_vote') === 'always';
    }

    // =========================================================================
    // Parsing members' items
    // =========================================================================

    /**
     * The `event` object of a post, validated, or null.
     *
     * @param array<string, mixed> $item
     * @return array{startsAt: string, endsAt: ?string, allDay: bool, location: string, url: string}|null
     */
    public static function parseEvent(array $item, string $type): ?array
    {
        $ev = $item['event'] ?? null;
        if ($type !== 'post' || !is_array($ev)) {
            return null;
        }
        $start = self::isoUtc($ev['startsAt'] ?? null);
        if ($start === null) {
            return null;
        }
        $end = self::isoUtc($ev['endsAt'] ?? null);
        if ($end !== null && $end < $start) {
            $end = null;
        }
        $location = is_string($ev['location'] ?? null) ? mb_substr(trim(preg_replace('/\s+/u', ' ', $ev['location']) ?? ''), 0, 200) : '';
        $url = is_string($ev['url'] ?? null) ? trim($ev['url']) : '';
        if (strlen($url) > 500 || !preg_match('#^https?://[^\s]+$#i', $url)) {
            $url = '';
        }
        return ['startsAt' => $start, 'endsAt' => $end, 'allDay' => !empty($ev['allDay']), 'location' => $location, 'url' => $url];
    }

    /**
     * The `listing` object (ask or offer) of a post, validated, or null.
     *
     * @param array<string, mixed> $item
     * @return array{kind: string, status: string}|null
     */
    public static function parseListing(array $item, string $type): ?array
    {
        $l = $item['listing'] ?? null;
        if ($type !== 'post' || !is_array($l) || !in_array($l['kind'] ?? null, ['ask', 'offer'], true)) {
            return null;
        }
        return ['kind' => (string) $l['kind'], 'status' => ($l['status'] ?? 'open') === 'closed' ? 'closed' : 'open'];
    }

    /** ISO-8601 with a time zone, normalised to UTC `YYYY-MM-DDTHH:MM:SS.000Z`, or null. */
    private static function isoUtc(mixed $raw): ?string
    {
        if (!is_string($raw) || strlen($raw) > 64
            || !preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}(:\d{2}(\.\d{1,9})?)?(Z|[+-]\d{2}:?\d{2})$/i', trim($raw))) {
            return null;
        }
        try {
            return (new \DateTimeImmutable(trim($raw)))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z');
        } catch (\Throwable) {
            return null;
        }
    }

    /** @return list<string> lowercased tags without a leading # */
    private static function normTags(mixed $raw): array
    {
        $out = [];
        foreach (is_array($raw) ? $raw : [] as $t) {
            if (is_string($t)) {
                $t = mb_strtolower(trim(ltrim(trim($t), '#')));
                if ($t !== '' && !in_array($t, $out, true) && count($out) < 20) {
                    $out[] = mb_substr($t, 0, 60);
                }
            }
        }
        return $out;
    }

    // =========================================================================
    // Indexing (from webhook-sync items)
    // =========================================================================

    /**
     * @param array<string, mixed> $row a webhook-sync items row (did, item_id, content_type, published_at, raw_item_json)
     */
    private function indexGroupItem(array $row): void
    {
        if (!$this->groupsReady) {
            return;
        }
        $did = (string) $row['did'];
        $itemId = (string) $row['item_id'];
        $key = self::itemKey($did, $itemId);
        $item = json_decode((string) $row['raw_item_json'], true);
        $item = is_array($item) ? $item : [];
        $type = (string) ($row['content_type'] ?? ($item['type'] ?? 'post'));
        $pdo = $this->ctx->pdo();
        $sqlite = $this->ctx->driver() === 'sqlite';

        $event = self::parseEvent($item, $type);
        if ($event === null) {
            $pdo->prepare("DELETE FROM {$this->tEvents} WHERE item_key = ?")->execute([$key]);
            $pdo->prepare("DELETE FROM {$this->tRsvps} WHERE item_key = ?")->execute([$key]);
        } else {
            $title = is_string($item['title'] ?? null) && trim($item['title']) !== '' ? trim($item['title']) : self::excerpt($item);
            $sql = $sqlite
                ? "INSERT INTO {$this->tEvents} (item_key, item_did, item_id, title, starts_at, ends_at, all_day, location, url, published_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                   ON CONFLICT(item_key) DO UPDATE SET title = excluded.title, starts_at = excluded.starts_at, ends_at = excluded.ends_at,
                     all_day = excluded.all_day, location = excluded.location, url = excluded.url, published_at = excluded.published_at"
                : "INSERT INTO {$this->tEvents} (item_key, item_did, item_id, title, starts_at, ends_at, all_day, location, url, published_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE title = VALUES(title), starts_at = VALUES(starts_at), ends_at = VALUES(ends_at),
                     all_day = VALUES(all_day), location = VALUES(location), url = VALUES(url), published_at = VALUES(published_at)";
            $pdo->prepare($sql)->execute([
                $key, $did, $itemId, mb_substr($title, 0, 300), $event['startsAt'], $event['endsAt'], $event['allDay'] ? 1 : 0,
                $event['location'], $event['url'], (string) $row['published_at'],
            ]);
        }

        $listing = self::parseListing($item, $type);
        if ($listing === null) {
            $pdo->prepare("DELETE FROM {$this->tListings} WHERE item_key = ?")->execute([$key]);
        } else {
            $tags = implode(' ', self::normTags($item['tags'] ?? []));
            $sql = $sqlite
                ? "INSERT INTO {$this->tListings} (item_key, item_did, item_id, kind, status, tags_text, published_at) VALUES (?, ?, ?, ?, ?, ?, ?)
                   ON CONFLICT(item_key) DO UPDATE SET kind = excluded.kind, status = excluded.status, tags_text = excluded.tags_text, published_at = excluded.published_at"
                : "INSERT INTO {$this->tListings} (item_key, item_did, item_id, kind, status, tags_text, published_at) VALUES (?, ?, ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE kind = VALUES(kind), status = VALUES(status), tags_text = VALUES(tags_text), published_at = VALUES(published_at)";
            $pdo->prepare($sql)->execute([$key, $did, $itemId, $listing['kind'], $listing['status'], mb_substr($tags, 0, 1000), (string) $row['published_at']]);
        }
    }

    private function reindexGroups(): void
    {
        $offset = 0;
        do {
            $stmt = $this->ctx->pdo()->query(
                "SELECT did, item_id, content_type, published_at, raw_item_json FROM {$this->tItems}
                 WHERE content_type = 'post' ORDER BY did, item_id LIMIT 500 OFFSET {$offset}"
            );
            $rows = $stmt->fetchAll();
            foreach ($rows as $row) {
                $this->indexGroupItem($row);
            }
            $offset += 500;
        } while (count($rows) === 500);
    }

    /** @return list<string> */
    private function groupItemTables(): array
    {
        return $this->groupsReady ? [$this->tEvents, $this->tRsvps, $this->tListings] : [];
    }

    /** RSVPs, votes and the polls a member created (opt-out, removal, block). */
    private function purgeGroupUserData(string $did): void
    {
        if (!$this->groupsReady || $did === '') {
            return;
        }
        $pdo = $this->ctx->pdo();
        $pdo->prepare("DELETE FROM {$this->tRsvps} WHERE did = ?")->execute([$did]);
        $pdo->prepare("DELETE FROM {$this->tPollVotes} WHERE did = ?")->execute([$did]);
        $ids = $pdo->prepare("SELECT id FROM {$this->tPolls} WHERE created_by = ?");
        $ids->execute([$did]);
        foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $this->deletePollRows((int) $id);
        }
        $this->interestMap = null;
    }

    // =========================================================================
    // Events
    // =========================================================================

    /** @return array<string, mixed> */
    private function handleEvents(ExtensionRequest $req): array
    {
        $this->requireReader($req);
        $when = ($req->query['when'] ?? 'upcoming') === 'past' ? 'past' : 'upcoming';
        $limit = max(1, min(100, (int) ($req->query['limit'] ?? 50)));
        return ['events' => $this->loadEvents($when, $limit, $req->userDid, $this->ctx->isoNow(), null)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadEvents(string $when, int $limit, ?string $viewer, string $now, ?string $until): array
    {
        $sql = "SELECT i.*, e.site_url, e.handle, e.display_name, e.avatar_url,
                       ev.starts_at AS ev_starts_at, ev.ends_at AS ev_ends_at, ev.all_day AS ev_all_day,
                       ev.location AS ev_location, ev.url AS ev_url
                FROM {$this->tEvents} ev
                JOIN {$this->tItems} i ON i.did = ev.item_did AND i.item_id = ev.item_id
                JOIN entries e ON e.did = i.did
                WHERE e.status = 'active'
                  AND NOT EXISTS (SELECT 1 FROM {$this->tHidden} h WHERE h.item_key = ev.item_key)";
        $args = [];
        if ($when === 'upcoming') {
            $sql .= ' AND COALESCE(ev.ends_at, ev.starts_at) >= ?';
            $args[] = $now;
            if ($until !== null) {
                $sql .= ' AND ev.starts_at <= ?';
                $args[] = $until;
            }
            $sql .= ' ORDER BY ev.starts_at ASC';
        } elseif ($when === 'past') {
            $sql .= ' AND COALESCE(ev.ends_at, ev.starts_at) < ? ORDER BY ev.starts_at DESC';
            $args[] = $now;
        } else { // 'calendar': recent and upcoming
            $sql .= ' AND ev.starts_at >= ? ORDER BY ev.starts_at ASC';
            $args[] = $now;
        }
        $sql .= ' LIMIT ' . $limit;
        $stmt = $this->ctx->pdo()->prepare($sql);
        $stmt->execute($args);
        $items = array_map(fn (array $r) => $this->shapeItem($r), $stmt->fetchAll());
        $this->decorate($items, $viewer, true);
        $this->decorateRsvps($items, $viewer);
        return $items;
    }

    /**
     * @param list<array<string, mixed>> $items
     */
    private function decorateRsvps(array &$items, ?string $viewer): void
    {
        if ($items === []) {
            return;
        }
        $keys = array_map(static fn (array $it): string => self::itemKey($it['did'], $it['itemId']), $items);
        $ph = implode(',', array_fill(0, count($keys), '?'));
        $pdo = $this->ctx->pdo();

        $counts = [];
        $stmt = $pdo->prepare(
            "SELECT r.item_key, r.status, COUNT(*) AS n FROM {$this->tRsvps} r JOIN entries e ON e.did = r.did AND e.status = 'active'
             WHERE r.item_key IN ({$ph}) GROUP BY r.item_key, r.status"
        );
        $stmt->execute($keys);
        foreach ($stmt->fetchAll() as $row) {
            $counts[(string) $row['item_key']][(string) $row['status']] = (int) $row['n'];
        }

        $mine = [];
        $going = [];
        if ($viewer !== null) {
            $stmt = $pdo->prepare("SELECT item_key, status FROM {$this->tRsvps} WHERE did = ? AND item_key IN ({$ph})");
            $stmt->execute(array_merge([$viewer], $keys));
            foreach ($stmt->fetchAll() as $row) {
                $mine[(string) $row['item_key']] = (string) $row['status'];
            }
            // Who is going is shown to signed-in members only.
            $stmt = $pdo->prepare(
                "SELECT r.item_key, r.did, e.display_name FROM {$this->tRsvps} r JOIN entries e ON e.did = r.did AND e.status = 'active'
                 WHERE r.status = 'going' AND r.item_key IN ({$ph}) ORDER BY r.updated_at ASC"
            );
            $stmt->execute($keys);
            foreach ($stmt->fetchAll() as $row) {
                $k = (string) $row['item_key'];
                if (count($going[$k] ?? []) < 50) {
                    $going[$k][] = ['did' => (string) $row['did'], 'displayName' => (string) $row['display_name']];
                }
            }
        }

        foreach ($items as $i => $it) {
            $k = $keys[$i];
            $items[$i]['rsvps'] = [
                'going' => $counts[$k]['going'] ?? 0,
                'maybe' => $counts[$k]['maybe'] ?? 0,
            ];
            $items[$i]['myRsvp'] = $mine[$k] ?? null;
            $items[$i]['attendees'] = $viewer !== null ? ($going[$k] ?? []) : null;
        }
    }

    /** @return array<string, mixed> */
    private function handleRsvp(ExtensionRequest $req): array
    {
        $did = (string) $req->userDid;
        $body = $req->json();
        $itemDid = is_string($body['did'] ?? null) ? trim($body['did']) : '';
        $itemId = is_string($body['itemId'] ?? null) ? trim($body['itemId']) : '';
        $status = $body['status'] ?? null;
        if ($status !== null && !in_array($status, self::RSVP_STATUSES, true)) {
            throw new AppError(400, 'invalid_status', 'RSVP with going, maybe or declined (or null to clear).');
        }
        $key = self::itemKey($itemDid, $itemId);
        $ev = $this->ctx->pdo()->prepare(
            "SELECT ev.starts_at, ev.ends_at FROM {$this->tEvents} ev JOIN entries e ON e.did = ev.item_did AND e.status = 'active'
             WHERE ev.item_key = ? AND NOT EXISTS (SELECT 1 FROM {$this->tHidden} h WHERE h.item_key = ev.item_key)"
        );
        $ev->execute([$key]);
        $event = $ev->fetch();
        if (!is_array($event)) {
            throw new AppError(404, 'not_found', 'That event is not on this hub.');
        }
        // An event without an end time stays open for RSVPs until 6 hours after it starts.
        $over = $event['ends_at'] !== null
            ? (string) $event['ends_at']
            : gmdate('Y-m-d\TH:i:s.000\Z', (int) strtotime((string) $event['starts_at']) + 6 * 3600);
        if ($over < $this->ctx->isoNow()) {
            throw new AppError(409, 'event_over', 'This event has already happened.');
        }
        if (!$this->checkLimit("rsvp:{$did}", 3600, self::RSVPS_PER_HOUR, $did)) {
            throw new AppError(429, 'rate_limited', 'Too many RSVP changes. Try again later.');
        }
        $pdo = $this->ctx->pdo();
        if ($status === null) {
            $pdo->prepare("DELETE FROM {$this->tRsvps} WHERE item_key = ? AND did = ?")->execute([$key, $did]);
        } else {
            $sql = $this->ctx->driver() === 'sqlite'
                ? "INSERT INTO {$this->tRsvps} (item_key, item_did, item_id, did, status, updated_at) VALUES (?, ?, ?, ?, ?, ?)
                   ON CONFLICT(item_key, did) DO UPDATE SET status = excluded.status, updated_at = excluded.updated_at"
                : "INSERT INTO {$this->tRsvps} (item_key, item_did, item_id, did, status, updated_at) VALUES (?, ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE status = VALUES(status), updated_at = VALUES(updated_at)";
            $pdo->prepare($sql)->execute([$key, $itemDid, $itemId, $did, $status, $this->ctx->isoNow()]);
        }
        $item = $this->loadItem($itemDid, $itemId);
        $shaped = [$this->shapeItem($item ?? [])];
        $this->decorateRsvps($shaped, $did);
        return ['did' => $itemDid, 'itemId' => $itemId, 'rsvps' => $shaped[0]['rsvps'], 'myRsvp' => $shaped[0]['myRsvp'], 'attendees' => $shaped[0]['attendees']];
    }

    private function handleEventsIcs(ExtensionRequest $req): ExtensionRawResponse
    {
        if ($this->membersOnly()) {
            throw new AppError(404, 'not_available', 'The calendar feed is off because this community is members-only.');
        }
        $since = gmdate('Y-m-d\TH:i:s.000\Z', time() - 30 * 86400);
        $events = $this->loadEvents('calendar', 200, null, $since, null);
        $host = (string) (parse_url($this->ctx->hubUrl(), PHP_URL_HOST) ?: 'webring-hub');
        $page = $this->ctx->hubUrl() . '/ext/social/';
        $lines = ['BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Webring Hub//Conversations//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'X-WR-CALNAME:' . self::icsText('Community events · ' . $host)];
        $stamp = gmdate('Ymd\THis\Z');
        foreach ($events as $it) {
            $ev = $it['event'];
            $lines[] = 'BEGIN:VEVENT';
            $lines[] = 'UID:' . self::itemKey($it['did'], $it['itemId']) . '@' . $host;
            $lines[] = 'DTSTAMP:' . $stamp;
            if ($ev['allDay']) {
                $startDay = substr($ev['startsAt'], 0, 10);
                $endDay = substr($ev['endsAt'] ?? $ev['startsAt'], 0, 10);
                $lines[] = 'DTSTART;VALUE=DATE:' . str_replace('-', '', $startDay);
                $lines[] = 'DTEND;VALUE=DATE:' . gmdate('Ymd', (int) strtotime($endDay . 'T00:00:00Z') + 86400);
            } else {
                $lines[] = 'DTSTART:' . gmdate('Ymd\THis\Z', (int) strtotime($ev['startsAt']));
                $lines[] = 'DTEND:' . gmdate('Ymd\THis\Z', (int) strtotime($ev['endsAt'] ?? $ev['startsAt']) + ($ev['endsAt'] === null ? 3600 : 0));
            }
            $lines[] = 'SUMMARY:' . self::icsText($it['title'] !== '' ? $it['title'] : 'Community event');
            if ($ev['location'] !== '') {
                $lines[] = 'LOCATION:' . self::icsText($ev['location']);
            }
            $desc = trim($it['commentary'] . "\n\nBy " . ($it['author']['displayName'] ?: $it['did']) . ' · ' . $page . '#thread?did=' . rawurlencode($it['did']) . '&id=' . rawurlencode($it['itemId']));
            $lines[] = 'DESCRIPTION:' . self::icsText(mb_substr($desc, 0, 2000));
            $lines[] = 'URL:' . self::icsText($ev['url'] !== '' ? $ev['url'] : $it['canonicalUrl']);
            $lines[] = 'END:VEVENT';
        }
        $lines[] = 'END:VCALENDAR';
        $body = implode("\r\n", array_map([self::class, 'icsFold'], $lines)) . "\r\n";
        return new ExtensionRawResponse(200, $body, 'text/calendar', ['Content-Disposition' => 'inline; filename="community-events.ics"']);
    }

    private static function icsText(string $s): string
    {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
        return str_replace(["\\", ';', ',', "\r\n", "\n", "\r"], ["\\\\", '\\;', '\\,', '\\n', '\\n', '\\n'], $s);
    }

    /** Folds an iCalendar content line at 75 octets without splitting a UTF-8 character. */
    private static function icsFold(string $line): string
    {
        if (strlen($line) <= 75) {
            return $line;
        }
        $out = '';
        $cur = '';
        foreach (mb_str_split($line) as $ch) {
            if (strlen($cur) + strlen($ch) > ($out === '' ? 75 : 74)) {
                $out .= ($out === '' ? '' : "\r\n ") . $cur;
                $cur = '';
            }
            $cur .= $ch;
        }
        return $out . "\r\n " . $cur;
    }

    // =========================================================================
    // Asks & offers
    // =========================================================================

    /** @return array<string, mixed> */
    private function handleListings(ExtensionRequest $req): array
    {
        $this->requireReader($req);
        $kind = $req->query['kind'] ?? '';
        $status = ($req->query['status'] ?? 'open') === 'all' ? 'all' : (($req->query['status'] ?? 'open') === 'closed' ? 'closed' : 'open');
        $limit = max(1, min(100, (int) ($req->query['limit'] ?? 50)));
        return ['listings' => $this->loadListings(is_string($kind) ? $kind : '', $status, $limit, $req->userDid, null)];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadListings(string $kind, string $status, int $limit, ?string $viewer, ?string $since): array
    {
        $sql = "SELECT i.*, e.site_url, e.handle, e.display_name, e.avatar_url
                FROM {$this->tListings} l
                JOIN {$this->tItems} i ON i.did = l.item_did AND i.item_id = l.item_id
                JOIN entries e ON e.did = i.did
                WHERE e.status = 'active' AND NOT EXISTS (SELECT 1 FROM {$this->tHidden} h WHERE h.item_key = l.item_key)";
        $args = [];
        if (in_array($kind, ['ask', 'offer'], true)) {
            $sql .= ' AND l.kind = ?';
            $args[] = $kind;
        }
        if ($status !== 'all') {
            $sql .= ' AND l.status = ?';
            $args[] = $status;
        }
        if ($since !== null) {
            $sql .= ' AND l.published_at >= ?';
            $args[] = $since;
        }
        $sql .= ' ORDER BY l.published_at DESC LIMIT ' . $limit;
        $stmt = $this->ctx->pdo()->prepare($sql);
        $stmt->execute($args);
        $items = array_map(fn (array $r) => $this->shapeItem($r), $stmt->fetchAll());
        $this->decorate($items, $viewer, true);

        $map = $this->interestMap();
        $viewerTags = [];
        if ($viewer !== null) {
            foreach ($map as $tag => $members) {
                foreach ($members as $m) {
                    if ($m['did'] === $viewer) {
                        $viewerTags[$tag] = true;
                    }
                }
            }
        }
        foreach ($items as $i => $it) {
            $matched = [];
            $matchesMe = false;
            foreach (self::normTags($it['tags']) as $tag) {
                if (isset($viewerTags[$tag]) && $it['did'] !== $viewer) {
                    $matchesMe = true;
                }
                foreach ($map[$tag] ?? [] as $m) {
                    if ($m['did'] !== $it['did']) {
                        $matched[$m['did']] = $m['name'];
                    }
                }
            }
            $members = [];
            if ($viewer !== null) {
                foreach (array_slice($matched, 0, 10, true) as $mdid => $name) {
                    $members[] = ['did' => $mdid, 'displayName' => $name];
                }
            }
            $items[$i]['matches'] = ['count' => count($matched), 'members' => $viewer !== null ? $members : null, 'matchesMe' => $matchesMe];
        }
        return $items;
    }

    /** @return array<string, list<array{did: string, name: string}>> */
    private function interestMap(): array
    {
        if ($this->interestMap === null) {
            $this->interestMap = [];
            foreach ($this->ctx->pdo()->query("SELECT did, display_name, interests_json FROM entries WHERE status = 'active'")->fetchAll() as $row) {
                foreach (self::normTags(json_decode((string) $row['interests_json'], true)) as $tag) {
                    $this->interestMap[$tag][] = ['did' => (string) $row['did'], 'name' => (string) $row['display_name']];
                }
            }
        }
        return $this->interestMap;
    }

    // =========================================================================
    // Polls
    // =========================================================================

    /** @return array<string, mixed> */
    private function handlePolls(ExtensionRequest $req): array
    {
        $this->requireReader($req);
        $status = ($req->query['status'] ?? 'open') === 'closed' ? 'closed' : 'open';
        return [
            'polls' => $this->loadPolls($status, 50, $req->userDid),
            'canCreate' => $req->userDid !== null && $this->membersCreatePolls(),
            'membersCreatePolls' => $this->membersCreatePolls(),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function loadPolls(string $status, int $limit, ?string $viewer): array
    {
        $now = $this->ctx->isoNow();
        $sql = "SELECT * FROM {$this->tPolls} WHERE "
            . ($status === 'open'
                ? '(closed_at IS NULL AND (closes_at IS NULL OR closes_at > ?)) ORDER BY created_at DESC'
                : '(closed_at IS NOT NULL OR (closes_at IS NOT NULL AND closes_at <= ?)) ORDER BY COALESCE(closed_at, closes_at) DESC')
            . ' LIMIT ' . $limit;
        $stmt = $this->ctx->pdo()->prepare($sql);
        $stmt->execute([$now]);
        return array_map(fn (array $row) => $this->shapePoll($row, $viewer), $stmt->fetchAll());
    }

    /**
     * @param array<string, mixed> $poll
     * @return array<string, mixed>
     */
    private function shapePoll(array $poll, ?string $viewer): array
    {
        $pdo = $this->ctx->pdo();
        $id = (int) $poll['id'];
        $now = $this->ctx->isoNow();
        $closed = $poll['closed_at'] !== null || ($poll['closes_at'] !== null && (string) $poll['closes_at'] <= $now);

        $opts = $pdo->prepare("SELECT id, label FROM {$this->tPollOptions} WHERE poll_id = ? ORDER BY position ASC");
        $opts->execute([$id]);
        $options = $opts->fetchAll();

        $counts = [];
        $c = $pdo->prepare(
            "SELECT v.option_id, COUNT(*) AS n FROM {$this->tPollVotes} v JOIN entries e ON e.did = v.did AND e.status = 'active'
             WHERE v.poll_id = ? GROUP BY v.option_id"
        );
        $c->execute([$id]);
        foreach ($c->fetchAll() as $row) {
            $counts[(int) $row['option_id']] = (int) $row['n'];
        }
        $t = $pdo->prepare(
            "SELECT COUNT(DISTINCT v.did) FROM {$this->tPollVotes} v JOIN entries e ON e.did = v.did AND e.status = 'active' WHERE v.poll_id = ?"
        );
        $t->execute([$id]);
        $voters = (int) $t->fetchColumn();

        $mine = [];
        if ($viewer !== null) {
            $m = $pdo->prepare("SELECT option_id FROM {$this->tPollVotes} WHERE poll_id = ? AND did = ?");
            $m->execute([$id, $viewer]);
            $mine = array_map('intval', $m->fetchAll(PDO::FETCH_COLUMN));
        }
        $resultsVisible = $closed || $this->pollResultsAlways() || $mine !== [];

        $creator = (string) $poll['created_by'];
        $creatorName = 'Hub admin';
        if ($creator !== 'admin') {
            $e = $this->ctx->entryByDid($creator);
            $creatorName = is_array($e) ? (string) ($e['displayName'] ?? $e['display_name'] ?? $creator) : $creator;
        }

        return [
            'id' => $id,
            'question' => (string) $poll['question'],
            'description' => (string) $poll['description'],
            'multi' => (bool) $poll['multi'],
            'createdBy' => ['kind' => $creator === 'admin' ? 'admin' : 'member', 'did' => $creator === 'admin' ? null : $creator, 'displayName' => $creatorName],
            'createdAt' => (string) $poll['created_at'],
            'closesAt' => $poll['closes_at'] !== null ? (string) $poll['closes_at'] : null,
            'closed' => $closed,
            'options' => array_map(static fn (array $o): array => [
                'id' => (int) $o['id'],
                'label' => (string) $o['label'],
                'votes' => $resultsVisible ? ($counts[(int) $o['id']] ?? 0) : null,
            ], $options),
            'voters' => $resultsVisible ? $voters : null,
            'resultsVisible' => $resultsVisible,
            'myVotes' => $mine,
            'canClose' => !$closed && $viewer !== null && $viewer === $creator,
        ];
    }

    /**
     * Validates poll input and stores it.
     *
     * @param array<string, mixed> $body
     */
    private function createPoll(array $body, string $createdBy): int
    {
        $question = is_string($body['question'] ?? null) ? trim(preg_replace('/\s+/u', ' ', $body['question']) ?? '') : '';
        $description = is_string($body['description'] ?? null) ? trim($body['description']) : '';
        $rawOptions = $body['options'] ?? [];
        if (is_string($rawOptions)) {
            $rawOptions = preg_split('/\s*(?:\||\r?\n)\s*/', $rawOptions) ?: [];
        }
        $options = [];
        foreach (is_array($rawOptions) ? $rawOptions : [] as $o) {
            $o = is_string($o) ? trim(preg_replace('/\s+/u', ' ', $o) ?? '') : '';
            if ($o !== '' && !in_array(mb_strtolower($o), array_map('mb_strtolower', $options), true)) {
                $options[] = mb_substr($o, 0, 120);
            }
        }
        if (mb_strlen($question) < 3 || mb_strlen($question) > 300) {
            throw new AppError(400, 'invalid_question', 'Ask a question of 3 to 300 characters.');
        }
        if (count($options) < 2 || count($options) > self::MAX_POLL_OPTIONS) {
            throw new AppError(400, 'invalid_options', 'Give 2 to ' . self::MAX_POLL_OPTIONS . ' different options (separate them with | when typing them on one line).');
        }
        $closesAt = null;
        if (isset($body['closesInDays']) && $body['closesInDays'] !== '' && $body['closesInDays'] !== null) {
            $days = (int) $body['closesInDays'];
            if ($days < 1 || $days > 90) {
                throw new AppError(400, 'invalid_close', 'A poll can stay open for 1 to 90 days.');
            }
            $closesAt = gmdate('Y-m-d\TH:i:s.000\Z', time() + $days * 86400);
        } elseif (isset($body['closesAt']) && $body['closesAt'] !== '' && $body['closesAt'] !== null) {
            $closesAt = self::isoUtc($body['closesAt']);
            if ($closesAt === null || $closesAt <= $this->ctx->isoNow() || $closesAt > gmdate('Y-m-d\TH:i:s.000\Z', time() + 90 * 86400)) {
                throw new AppError(400, 'invalid_close', 'The closing time must be in the next 90 days.');
            }
        }
        $multi = !empty($body['multi']) && $body['multi'] !== 'false' && $body['multi'] !== '0';

        $pdo = $this->ctx->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare(
                "INSERT INTO {$this->tPolls} (question, description, multi, created_by, created_at, closes_at, closed_at) VALUES (?, ?, ?, ?, ?, ?, NULL)"
            )->execute([$question, mb_substr($description, 0, 1000), $multi ? 1 : 0, $createdBy, $this->ctx->isoNow(), $closesAt]);
            $id = (int) $pdo->lastInsertId();
            $ins = $pdo->prepare("INSERT INTO {$this->tPollOptions} (poll_id, position, label) VALUES (?, ?, ?)");
            foreach ($options as $i => $label) {
                $ins->execute([$id, $i, $label]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $id;
    }

    /** @return array<string, mixed> */
    private function handleCreatePoll(ExtensionRequest $req): array
    {
        if (!$this->membersCreatePolls()) {
            throw new AppError(403, 'admin_only', 'On this hub only the admin creates polls.');
        }
        $did = (string) $req->userDid;
        if (!$this->checkLimit("poll:{$did}", 86400, self::POLLS_PER_DAY, $did)) {
            throw new AppError(429, 'rate_limited', 'You have created enough polls for today.');
        }
        $id = $this->createPoll($req->json(), $did);
        return ['poll' => $this->shapePoll($this->pollRow((string) $id), $did)];
    }

    /** @return array<string, mixed> */
    private function handleAdminCreatePoll(ExtensionRequest $req): array
    {
        $id = $this->createPoll($req->json(), 'admin');
        return ['status' => 'created', 'id' => $id];
    }

    /** @return array<string, mixed> */
    private function handleVote(string $pollId, ExtensionRequest $req): array
    {
        $did = (string) $req->userDid;
        $poll = $this->pollRow($pollId);
        $shaped = $this->shapePoll($poll, $did);
        if ($shaped['closed']) {
            throw new AppError(409, 'poll_closed', 'This poll is closed.');
        }
        $body = $req->json();
        $choice = array_values(array_unique(array_map('intval', is_array($body['optionIds'] ?? null) ? $body['optionIds'] : [])));
        $valid = array_map(static fn (array $o): int => $o['id'], $shaped['options']);
        foreach ($choice as $c) {
            if (!in_array($c, $valid, true)) {
                throw new AppError(400, 'invalid_option', 'That option is not part of this poll.');
            }
        }
        if (!$shaped['multi'] && count($choice) > 1) {
            throw new AppError(400, 'single_choice', 'Pick one option in this poll.');
        }
        if (!$this->checkLimit("vote:{$did}", 3600, self::VOTES_PER_HOUR, $did)) {
            throw new AppError(429, 'rate_limited', 'Too many votes. Try again later.');
        }
        $pdo = $this->ctx->pdo();
        $pdo->beginTransaction();
        try {
            $pdo->prepare("DELETE FROM {$this->tPollVotes} WHERE poll_id = ? AND did = ?")->execute([(int) $poll['id'], $did]);
            $ins = $pdo->prepare("INSERT INTO {$this->tPollVotes} (poll_id, option_id, did, created_at) VALUES (?, ?, ?, ?)");
            foreach ($choice as $c) {
                $ins->execute([(int) $poll['id'], $c, $did, $this->ctx->isoNow()]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return ['poll' => $this->shapePoll($poll, $did)];
    }

    /** @return array<string, mixed> */
    private function handleMemberClosePoll(string $pollId, ExtensionRequest $req): array
    {
        $poll = $this->pollRow($pollId);
        if ((string) $poll['created_by'] !== (string) $req->userDid) {
            throw new AppError(403, 'not_creator', 'Only the member who created this poll (or the hub admin) can close it.');
        }
        $this->closePoll($pollId);
        return ['poll' => $this->shapePoll($this->pollRow($pollId), $req->userDid)];
    }

    /** @return array<string, mixed> */
    private function closePoll(string $pollId): array
    {
        $poll = $this->pollRow($pollId);
        $this->ctx->pdo()->prepare("UPDATE {$this->tPolls} SET closed_at = ? WHERE id = ? AND closed_at IS NULL")
            ->execute([$this->ctx->isoNow(), (int) $poll['id']]);
        return ['status' => 'closed'];
    }

    /** @return array<string, mixed> */
    private function deletePoll(string $pollId): array
    {
        $poll = $this->pollRow($pollId);
        $this->deletePollRows((int) $poll['id']);
        return ['status' => 'deleted'];
    }

    private function deletePollRows(int $id): void
    {
        $pdo = $this->ctx->pdo();
        $pdo->prepare("DELETE FROM {$this->tPollVotes} WHERE poll_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM {$this->tPollOptions} WHERE poll_id = ?")->execute([$id]);
        $pdo->prepare("DELETE FROM {$this->tPolls} WHERE id = ?")->execute([$id]);
    }

    /** @return array<string, mixed> */
    private function pollRow(string $id): array
    {
        if (!ctype_digit($id)) {
            throw new AppError(404, 'not_found', 'No such poll.');
        }
        $stmt = $this->ctx->pdo()->prepare("SELECT * FROM {$this->tPolls} WHERE id = ?");
        $stmt->execute([(int) $id]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            throw new AppError(404, 'not_found', 'No such poll.');
        }
        return $row;
    }

    /** @return array<string, mixed> */
    private function handleAdminPolls(): array
    {
        $rows = $this->ctx->pdo()->query("SELECT * FROM {$this->tPolls} ORDER BY created_at DESC LIMIT 100")->fetchAll();
        $out = [];
        foreach ($rows as $row) {
            $p = $this->shapePoll($row, null);
            $t = $this->ctx->pdo()->prepare("SELECT COUNT(DISTINCT did) FROM {$this->tPollVotes} WHERE poll_id = ?");
            $t->execute([$p['id']]);
            $out[] = [
                'id' => $p['id'],
                'question' => $p['question'],
                'createdBy' => $p['createdBy']['displayName'],
                'status' => $p['closed'] ? 'closed' : 'open',
                'voters' => (int) $t->fetchColumn(),
                'closesAt' => $p['closesAt'],
            ];
        }
        return ['polls' => $out];
    }

    // =========================================================================
    // Digest
    // =========================================================================

    /** @return array<string, mixed> */
    private function handleDigest(ExtensionRequest $req): array
    {
        $this->requireReader($req);
        $days = max(1, min(31, (int) ($req->query['days'] ?? 7)));
        $to = time();
        return $this->buildDigest($to - $days * 86400, $to, $req->userDid);
    }

    /**
     * A summary of the community between two times: busiest conversations, upcoming events,
     * new members, open polls and new asks/offers.
     *
     * @return array<string, mixed>
     */
    private function buildDigest(int $fromTs, int $toTs, ?string $viewer): array
    {
        $from = gmdate('Y-m-d\TH:i:s.000\Z', $fromTs);
        $to = gmdate('Y-m-d\TH:i:s.000\Z', $toTs);
        $pdo = $this->ctx->pdo();

        // Busiest top-level items: recent posts plus items with recent replies.
        $owned = $this->ownedSql('p', 'pe');
        $cand = $pdo->prepare(
            "SELECT i.*, e.site_url, e.handle, e.display_name, e.avatar_url FROM {$this->tItems} i JOIN entries e ON e.did = i.did
             WHERE e.status = 'active' AND i.content_type <> 'like'
               AND NOT EXISTS (SELECT 1 FROM {$this->tItems} p JOIN entries pe ON pe.did = p.did
                               WHERE i.target_url IS NOT NULL AND p.canonical_url = i.target_url AND pe.status = 'active' AND {$owned})
               AND NOT EXISTS (SELECT 1 FROM {$this->tHidden} h WHERE h.item_did = i.did AND h.item_id = i.item_id)
               AND (
                 (i.published_at >= ? AND i.published_at <= ?)
                 OR EXISTS (SELECT 1 FROM {$this->tItems} c WHERE c.target_url = i.canonical_url AND c.published_at >= ? AND c.published_at <= ?)
               )
             ORDER BY i.published_at DESC LIMIT 60"
        );
        $cand->execute([$from, $to, $from, $to]);
        $items = array_map(fn (array $r) => $this->shapeItem($r), $cand->fetchAll());
        $this->decorate($items, $viewer, true);
        foreach ($items as $i => $it) {
            $items[$i]['score'] = $it['replyCount'] * 2 + array_sum((array) $it['reactions']);
        }
        usort($items, static fn (array $a, array $b): int => [$b['score'], $b['publishedAt']] <=> [$a['score'], $a['publishedAt']]);

        $members = $pdo->prepare(
            "SELECT did, display_name, site_url, avatar_url FROM entries WHERE status = 'active' AND created_at >= ? AND created_at <= ? ORDER BY created_at DESC LIMIT 12"
        );
        $members->execute([$from, $to]);

        $count = static function (string $sql, array $args) use ($pdo): int {
            $s = $pdo->prepare($sql);
            $s->execute($args);
            return (int) $s->fetchColumn();
        };

        return [
            'period' => ['from' => $from, 'to' => $to],
            'stats' => [
                'posts' => $count("SELECT COUNT(*) FROM {$this->tItems} i JOIN entries e ON e.did = i.did AND e.status = 'active' WHERE i.content_type <> 'comment' AND i.content_type <> 'like' AND i.published_at >= ? AND i.published_at <= ?", [$from, $to]),
                'comments' => $count("SELECT COUNT(*) FROM {$this->tItems} i JOIN entries e ON e.did = i.did AND e.status = 'active' WHERE i.content_type = 'comment' AND i.published_at >= ? AND i.published_at <= ?", [$from, $to]),
                'reactions' => $count("SELECT COUNT(*) FROM {$this->tReactions} WHERE created_at >= ? AND created_at <= ?", [$from, $to]),
            ],
            'topConversations' => array_slice(array_values(array_filter($items, static fn (array $it): bool => $it['score'] > 0 || $it['publishedAt'] >= $from)), 0, 5),
            'upcomingEvents' => $this->loadEvents('upcoming', 5, $viewer, $to, gmdate('Y-m-d\TH:i:s.000\Z', $toTs + 14 * 86400)),
            'newMembers' => array_map(static fn (array $r): array => [
                'did' => (string) $r['did'],
                'displayName' => (string) $r['display_name'],
                'siteUrl' => (string) $r['site_url'],
                'avatarUrl' => preg_match('#^https://#i', (string) $r['avatar_url']) ? (string) $r['avatar_url'] : '',
            ], $members->fetchAll()),
            'openPolls' => array_slice($this->loadPolls('open', 5, $viewer), 0, 5),
            'newListings' => $this->loadListings('', 'open', 5, $viewer, $from),
            'spotlight' => $this->belongingReady ? ($this->loadSpotlight($viewer)['member'] ?? null) : null,
            'welcome' => $this->belongingReady ? array_slice($this->loadWelcome($viewer)['members'] ?? [], 0, 6) : [],
            'readingList' => $this->structureReady ? $this->loadLibrary(7, 10, $viewer) : [],
            'nowUpdates' => $this->structureReady ? $this->loadNowWall(8) : [],
            'activeChallenges' => $this->structureReady ? ($this->handleChallenges(new ExtensionRequest('GET', '', [], static fn () => [], '', $viewer))['active'] ?? []) : [],
        ];
    }

    private function handleDigestAtom(ExtensionRequest $req): ExtensionRawResponse
    {
        if ($this->membersOnly()) {
            throw new AppError(404, 'not_available', 'The digest feed is off because this community is members-only.');
        }
        $hub = $this->ctx->hubUrl();
        $page = $hub . '/ext/social/';
        $host = (string) (parse_url($hub, PHP_URL_HOST) ?: 'webring-hub');
        // One entry per completed week (Monday 00:00 UTC to Monday), newest first.
        $monday = strtotime('monday this week 00:00 UTC');
        $x = static fn (string $s): string => htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $entries = '';
        for ($w = 0; $w < 4; $w++) {
            $end = $monday - $w * 7 * 86400;
            $start = $end - 7 * 86400;
            $d = $this->buildDigest($start, $end, null);
            $html = '<p>' . $d['stats']['posts'] . ' posts, ' . $d['stats']['comments'] . ' comments and ' . $d['stats']['reactions'] . ' reactions.</p>';
            $section = static function (string $title, array $lines) use (&$html): void {
                if ($lines !== []) {
                    $html .= '<h3>' . $title . '</h3><ul><li>' . implode('</li><li>', $lines) . '</li></ul>';
                }
            };
            $link = static fn (array $it): string => htmlspecialchars($page . '#thread?did=' . rawurlencode($it['did']) . '&id=' . rawurlencode($it['itemId']), ENT_QUOTES, 'UTF-8');
            $label = static fn (array $it): string => htmlspecialchars(($it['author']['displayName'] ?: $it['did']) . ': ' . ($it['title'] !== '' ? $it['title'] : self::excerpt(['commentary' => $it['commentary']])), ENT_QUOTES, 'UTF-8');
            $section('Busiest conversations', array_map(static fn ($it) => '<a href="' . $link($it) . '">' . $label($it) . '</a> (' . $it['replyCount'] . ' replies)', $d['topConversations']));
            $section('Coming up', array_map(static fn ($it) => '<a href="' . $link($it) . '">' . $label($it) . '</a> · ' . htmlspecialchars(gmdate('D j M, H:i', (int) strtotime($it['event']['startsAt'])) . ' UTC', ENT_QUOTES, 'UTF-8'), $d['upcomingEvents']));
            $section('New members', array_map(static fn ($m) => htmlspecialchars($m['displayName'], ENT_QUOTES, 'UTF-8'), $d['newMembers']));
            $section('Open polls', array_map(static fn ($p) => htmlspecialchars($p['question'], ENT_QUOTES, 'UTF-8'), $d['openPolls']));
            $section('Asks & offers', array_map(static fn ($it) => ucfirst($it['listing']['kind']) . ': <a href="' . $link($it) . '">' . $label($it) . '</a>', $d['newListings']));

            $title = 'Week of ' . gmdate('j M Y', $start);
            $entries .= '<entry><id>tag:' . $x($host) . ',' . gmdate('Y-m-d', $start) . ':digest</id>'
                . '<title>' . $x($title) . '</title>'
                . '<updated>' . gmdate('Y-m-d\TH:i:s\Z', $end) . '</updated>'
                . '<link rel="alternate" href="' . $x($page . '#digest') . '"/>'
                . '<content type="html">' . $x($html) . '</content></entry>';
        }
        $body = '<?xml version="1.0" encoding="utf-8"?>'
            . '<feed xmlns="http://www.w3.org/2005/Atom">'
            . '<id>tag:' . $x($host) . ',2026:social-digest</id>'
            . '<title>' . $x('Community digest · ' . $host) . '</title>'
            . '<updated>' . gmdate('Y-m-d\TH:i:s\Z', $monday) . '</updated>'
            . '<link rel="alternate" href="' . $x($page) . '"/>'
            . '<link rel="self" href="' . $x($hub . '/api/ext/social/v1/digest.atom') . '"/>'
            . '<author><name>' . $x($host) . '</name></author>'
            . $entries . '</feed>';
        return new ExtensionRawResponse(200, $body, 'application/atom+xml');
    }

    // =========================================================================
    // Schema
    // =========================================================================

    private function createGroupTables(PDO $pdo, string $driver): void
    {
        if ($driver === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS {$this->tEvents} (
                  item_key TEXT PRIMARY KEY,
                  item_did TEXT NOT NULL,
                  item_id TEXT NOT NULL,
                  title TEXT NOT NULL,
                  starts_at TEXT NOT NULL,
                  ends_at TEXT DEFAULT NULL,
                  all_day INTEGER NOT NULL DEFAULT 0,
                  location TEXT NOT NULL DEFAULT '',
                  url TEXT NOT NULL DEFAULT '',
                  published_at TEXT NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_{$this->tEvents}_starts ON {$this->tEvents}(starts_at);
                CREATE INDEX IF NOT EXISTS idx_{$this->tEvents}_item_did ON {$this->tEvents}(item_did);

                CREATE TABLE IF NOT EXISTS {$this->tRsvps} (
                  item_key TEXT NOT NULL,
                  item_did TEXT NOT NULL,
                  item_id TEXT NOT NULL,
                  did TEXT NOT NULL,
                  status TEXT NOT NULL,
                  updated_at TEXT NOT NULL,
                  PRIMARY KEY (item_key, did)
                );
                CREATE INDEX IF NOT EXISTS idx_{$this->tRsvps}_did ON {$this->tRsvps}(did);
                CREATE INDEX IF NOT EXISTS idx_{$this->tRsvps}_item_did ON {$this->tRsvps}(item_did);

                CREATE TABLE IF NOT EXISTS {$this->tListings} (
                  item_key TEXT PRIMARY KEY,
                  item_did TEXT NOT NULL,
                  item_id TEXT NOT NULL,
                  kind TEXT NOT NULL,
                  status TEXT NOT NULL,
                  tags_text TEXT NOT NULL DEFAULT '',
                  published_at TEXT NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_{$this->tListings}_status ON {$this->tListings}(status, kind, published_at);
                CREATE INDEX IF NOT EXISTS idx_{$this->tListings}_item_did ON {$this->tListings}(item_did);

                CREATE TABLE IF NOT EXISTS {$this->tPolls} (
                  id INTEGER PRIMARY KEY AUTOINCREMENT,
                  question TEXT NOT NULL,
                  description TEXT NOT NULL DEFAULT '',
                  multi INTEGER NOT NULL DEFAULT 0,
                  created_by TEXT NOT NULL,
                  created_at TEXT NOT NULL,
                  closes_at TEXT DEFAULT NULL,
                  closed_at TEXT DEFAULT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_{$this->tPolls}_creator ON {$this->tPolls}(created_by);

                CREATE TABLE IF NOT EXISTS {$this->tPollOptions} (
                  id INTEGER PRIMARY KEY AUTOINCREMENT,
                  poll_id INTEGER NOT NULL,
                  position INTEGER NOT NULL,
                  label TEXT NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_{$this->tPollOptions}_poll ON {$this->tPollOptions}(poll_id);

                CREATE TABLE IF NOT EXISTS {$this->tPollVotes} (
                  poll_id INTEGER NOT NULL,
                  option_id INTEGER NOT NULL,
                  did TEXT NOT NULL,
                  created_at TEXT NOT NULL,
                  PRIMARY KEY (poll_id, option_id, did)
                );
                CREATE INDEX IF NOT EXISTS idx_{$this->tPollVotes}_did ON {$this->tPollVotes}(did);
            ");
            return;
        }

        $did = 'VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL';
        $key = 'CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL';
        $tail = 'ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci';
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tEvents} (
              item_key {$key},
              item_did {$did},
              item_id {$did},
              title VARCHAR(300) NOT NULL,
              starts_at VARCHAR(40) NOT NULL,
              ends_at VARCHAR(40) DEFAULT NULL,
              all_day TINYINT(1) NOT NULL DEFAULT 0,
              location VARCHAR(200) NOT NULL DEFAULT '',
              url VARCHAR(500) NOT NULL DEFAULT '',
              published_at VARCHAR(40) NOT NULL,
              PRIMARY KEY (item_key),
              KEY idx_events_starts (starts_at),
              KEY idx_events_item_did (item_did)
            ) {$tail}
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tRsvps} (
              item_key {$key},
              item_did {$did},
              item_id {$did},
              did {$did},
              status VARCHAR(16) NOT NULL,
              updated_at VARCHAR(40) NOT NULL,
              PRIMARY KEY (item_key, did),
              KEY idx_rsvps_did (did),
              KEY idx_rsvps_item_did (item_did)
            ) {$tail}
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tListings} (
              item_key {$key},
              item_did {$did},
              item_id {$did},
              kind VARCHAR(8) NOT NULL,
              status VARCHAR(8) NOT NULL,
              tags_text VARCHAR(1000) NOT NULL DEFAULT '',
              published_at VARCHAR(40) NOT NULL,
              PRIMARY KEY (item_key),
              KEY idx_listings_status (status, kind, published_at),
              KEY idx_listings_item_did (item_did)
            ) {$tail}
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tPolls} (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              question VARCHAR(300) NOT NULL,
              description VARCHAR(1000) NOT NULL DEFAULT '',
              multi TINYINT(1) NOT NULL DEFAULT 0,
              created_by {$did},
              created_at VARCHAR(40) NOT NULL,
              closes_at VARCHAR(40) DEFAULT NULL,
              closed_at VARCHAR(40) DEFAULT NULL,
              PRIMARY KEY (id),
              KEY idx_polls_creator (created_by)
            ) {$tail}
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tPollOptions} (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              poll_id BIGINT UNSIGNED NOT NULL,
              position INT NOT NULL,
              label VARCHAR(120) NOT NULL,
              PRIMARY KEY (id),
              KEY idx_poll_options_poll (poll_id)
            ) {$tail}
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->tPollVotes} (
              poll_id BIGINT UNSIGNED NOT NULL,
              option_id BIGINT UNSIGNED NOT NULL,
              did {$did},
              created_at VARCHAR(40) NOT NULL,
              PRIMARY KEY (poll_id, option_id, did),
              KEY idx_poll_votes_did (did)
            ) {$tail}
        ");
    }
}
