<?php
declare(strict_types=1);

/**
 * Smoke test for the `social` extension's group features (Tier 2): events and RSVPs, the
 * calendar feed, asks & offers with interest matching, polls, and the digest and its Atom feed.
 *
 * Run with: php scripts/smoke-social-groups.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

require_once __DIR__ . '/../src/AppError.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Verify.php';
require_once __DIR__ . '/../src/Net.php';
require_once __DIR__ . '/../src/Site.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Extensions.php';
require_once __DIR__ . '/../src/Entries.php';

use WebringHub\AppError;
use WebringHub\Config;
use WebringHub\Database;
use WebringHub\ExtensionRawResponse;
use WebringHub\ExtensionRequest;
use WebringHub\Extensions;

$failures = 0;

function check(bool $ok, string $label): void
{
    global $failures;
    echo ($ok ? '  ok   ' : '  FAIL ') . $label . "\n";
    if (!$ok) {
        $failures++;
    }
}

/**
 * @return array{0: int, 1: array<string, mixed>, 2: ?ExtensionRawResponse}
 */
function call(Extensions $ext, string $method, string $path, array $query = [], array $body = [], ?string $userDid = null, bool $optedIn = false): array
{
    $match = $ext->match($method, $path);
    if ($match === null) {
        return [404, ['error' => 'no_route'], null];
    }
    try {
        $res = Extensions::run($match, new ExtensionRequest($method, $path, $query, static fn (): array => $body, 'smoke', $userDid, $optedIn));
        return [$res[0], json_decode((string) json_encode($res[1]), true), $res[3] ?? null];
    } catch (AppError $e) {
        return [$e->status, ['error' => $e->errorCode, 'message' => $e->getMessage()], null];
    }
}

$tmp = sys_get_temp_dir() . '/webring-groups-smoke-' . bin2hex(random_bytes(4));
@mkdir($tmp);
$dbPath = "{$tmp}/hub.sqlite";
$cfgPath = "{$tmp}/config.php";
file_put_contents($cfgPath, "<?php\nreturn [];\n");
putenv("WEBRING_HUB_CONFIG={$cfgPath}");
$load = static function (array $settings = []) use ($dbPath): array {
    $config = Config::load([
        'db_driver' => 'sqlite', 'sqlite_path' => $dbPath, 'auto_migrate' => true, 'hub_url' => 'https://hub.example',
        'extensions' => ['webhook-sync', 'social'], 'extension_settings' => ['social' => $settings],
    ]);
    $pdo = Database::open($config);
    return [$pdo, Extensions::load($config, $pdo)];
};
[$pdo, $ext] = $load();
check($ext->isLoaded('social') && ($ext->context('social')?->schemaVersion() ?? 0) >= 2, 'social migrates to schema 2+');

$now = Database::isoNow();
$insEntry = $pdo->prepare(
    "INSERT INTO entries (id, site_url, did, handle, display_name, interests_json, public_key_pem, profile_updated_at, verified_at, last_checked_at, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, 'pem', ?, ?, ?, ?, ?)"
);
$old = '2026-01-01T00:00:00.000Z';
foreach ([
    'alice' => ['Alice', ['Woodworking', 'music'], $now],
    'bob' => ['Bob', ['#Music'], $now],
    'carol' => ['Carol', ['gardening'], $old],
] as $n => [$name, $interests, $created]) {
    $insEntry->execute([$n, "https://{$n}.example", "did:web:{$n}.example", $n, $name, json_encode($interests), $now, $now, $now, $created, $now]);
}
$did = static fn (string $n): string => "did:web:{$n}.example";
$url = static fn (string $n, string $id): string => "https://{$n}.example/api/items.json#{$id}";
$in = static fn (int $hours): string => gmdate('Y-m-d\TH:i:s\Z', time() + $hours * 3600);

$insItem = $pdo->prepare(
    'INSERT INTO ext_webhook_sync_items (did, item_id, canonical_url, content_type, published_at, updated_at, raw_item_json, target_url) VALUES (?, ?, ?, ?, ?, NULL, ?, ?)
     ON CONFLICT(did, item_id) DO UPDATE SET raw_item_json = excluded.raw_item_json, content_type = excluded.content_type'
);
$put = static function (string $n, string $id, string $type, array $extra, string $at = '') use ($insItem, $did, $url): void {
    $at = $at ?: Database::isoNow();
    $raw = array_merge(['id' => $id, 'canonicalUrl' => $url($n, $id), 'type' => $type, 'title' => '', 'commentary' => '', 'createdAt' => $at, 'tags' => []], $extra);
    $insItem->execute([$did($n), $id, $url($n, $id), $type, $at, json_encode($raw), $extra['targetUrl'] ?? null]);
};
$put('alice', 'e1', 'post', ['title' => 'Repair café, bring tools', 'commentary' => "Fix things together; coffee provided.\nBring a mug.", 'event' => ['startsAt' => $in(72), 'endsAt' => $in(75), 'location' => 'Library, Room 2', 'url' => 'https://meet.example/repair']]);
$put('alice', 'e0', 'post', ['title' => 'Old meetup', 'event' => ['startsAt' => $in(-240)]]);
$put('alice', 'e2', 'post', ['title' => 'Picnic', 'event' => ['startsAt' => gmdate('Y-m-d', time() + 86400 * 5) . 'T00:00:00Z', 'allDay' => true]]);
$put('bob', 'x1', 'post', ['title' => 'Not an event', 'event' => ['location' => 'nowhere']]);
$put('bob', 'x2', 'comment', ['commentary' => 'comments cannot be events', 'event' => ['startsAt' => $in(10)], 'targetUrl' => $url('alice', 'e1')]);
$put('bob', 'a1', 'post', ['title' => 'Need help fixing a chair', 'listing' => ['kind' => 'ask'], 'tags' => ['#Woodworking', 'furniture']]);
$put('carol', 'o1', 'post', ['title' => 'Spare guitar lessons', 'listing' => ['kind' => 'offer', 'status' => 'closed'], 'tags' => ['music']]);
$put('carol', 'o2', 'post', ['title' => 'Seedlings to give away', 'listing' => ['kind' => 'offer'], 'tags' => ['gardening']]);
$ws = $ext->context('webhook-sync');
foreach (['alice' => ['e1', 'e0', 'e2'], 'bob' => ['x1', 'x2', 'a1'], 'carol' => ['o1', 'o2']] as $n => $ids) {
    $ws->emit('items_changed', ['did' => $did($n), 'upserted' => $ids, 'removed' => [], 'removedAll' => false]);
}

echo "events\n";
[$s, $up] = call($ext, 'GET', '/api/ext/social/v1/events');
$ids = array_map(static fn ($e) => $e['itemId'], $up['events'] ?? []);
check($s === 200 && $ids === ['e1', 'e2'], 'upcoming events, soonest first; posts without startsAt and non-posts are not events (' . implode(',', $ids) . ')');
$e1 = $up['events'][0] ?? [];
check(($e1['event']['location'] ?? '') === 'Library, Room 2' && ($e1['event']['url'] ?? '') === 'https://meet.example/repair' && ($e1['event']['endsAt'] ?? null) !== null, 'event details are parsed and normalised');
check(($up['events'][1]['event']['allDay'] ?? false) === true, 'all-day events are flagged');
[$s, $past] = call($ext, 'GET', '/api/ext/social/v1/events', ['when' => 'past']);
check(array_map(static fn ($e) => $e['itemId'], $past['events']) === ['e0'], 'past events are listed separately');
[$s, $feed] = call($ext, 'GET', '/api/ext/social/v1/feed');
$fe1 = array_values(array_filter($feed['items'], static fn ($i) => $i['itemId'] === 'e1'))[0] ?? [];
check(isset($fe1['event']['startsAt']) && array_key_exists('listing', $fe1) && $fe1['listing'] === null, 'feed items carry their event (or null)');

$rsvp = static fn (string $who, string $n, string $id, ?string $status) => call($ext, 'POST', '/api/ext/social/v1/rsvps', [], ['did' => $did($n), 'itemId' => $id, 'status' => $status], $did($who), true);
[$s, $r] = $rsvp('bob', 'alice', 'e1', 'going');
check($s === 200 && $r['rsvps']['going'] === 1 && $r['myRsvp'] === 'going', 'RSVP going is recorded');
$rsvp('carol', 'alice', 'e1', 'maybe');
[$s, $up2] = call($ext, 'GET', '/api/ext/social/v1/events', [], [], $did('carol'));
check(($up2['events'][0]['rsvps'] ?? []) === ['going' => 1, 'maybe' => 1] && ($up2['events'][0]['myRsvp'] ?? '') === 'maybe', 'counts and my RSVP are shown');
check(($up2['events'][0]['attendees'][0]['displayName'] ?? '') === 'Bob', 'signed-in members see who is going');
check(array_key_exists('attendees', $up['events'][0]) && $up['events'][0]['attendees'] === null, 'visitors who are not signed in do not see attendee names');
[$s, $r] = $rsvp('bob', 'alice', 'e1', null);
check($r['rsvps']['going'] === 0 && $r['myRsvp'] === null, 'an RSVP can be cleared');
[$s] = $rsvp('bob', 'alice', 'e1', 'yes');
check($s === 400, 'unknown RSVP status is refused');
[$s, $b] = $rsvp('bob', 'alice', 'e0', 'going');
check($s === 409 && ($b['error'] ?? '') === 'event_over', 'RSVPs to past events are refused');
[$s] = $rsvp('bob', 'bob', 'x1', 'going');
check($s === 404, 'RSVPs to items that are not events are 404');

[$s, , $raw] = call($ext, 'GET', '/api/ext/social/v1/events.ics');
$ics = $raw?->body ?? '';
check($s === 200 && $raw !== null && str_starts_with($raw->contentType, 'text/calendar'), 'events.ics is served as text/calendar');
check(str_contains($ics, "BEGIN:VEVENT") && str_contains($ics, 'SUMMARY:Repair café\, bring tools') && str_contains($ics, 'DTSTART;VALUE=DATE:'), 'calendar has escaped summaries and all-day dates');
$long = array_filter(explode("\r\n", $ics), static fn ($l) => strlen($l) > 75);
check($long === [] && str_contains($ics, "\r\n "), 'calendar lines are folded at 75 octets');
check(str_contains($ics, 'SUMMARY:Old meetup') && str_contains($ics, 'LOCATION:Library\, Room 2'), 'calendar covers events from the last 30 days and upcoming ones');

echo "asks & offers\n";
[$s, $open] = call($ext, 'GET', '/api/ext/social/v1/listings');
$ids = array_map(static fn ($l) => $l['itemId'], $open['listings']);
sort($ids);
check($s === 200 && $ids === ['a1', 'o2'], 'open asks and offers are listed; closed ones are not');
[$s, $all] = call($ext, 'GET', '/api/ext/social/v1/listings', ['status' => 'all', 'kind' => 'offer']);
check(count($all['listings']) === 2 && $all['listings'][0]['listing']['kind'] === 'offer', 'filter by kind, including closed');
$a1 = array_values(array_filter($open['listings'], static fn ($l) => $l['itemId'] === 'a1'))[0];
check($a1['matches']['count'] === 1 && $a1['matches']['members'] === null, "matches count members whose interests fit the tags; names hidden from visitors");
[$s, $mine] = call($ext, 'GET', '/api/ext/social/v1/listings', [], [], $did('alice'));
$a1a = array_values(array_filter($mine['listings'], static fn ($l) => $l['itemId'] === 'a1'))[0];
check($a1a['matches']['matchesMe'] === true && ($a1a['matches']['members'][0]['displayName'] ?? '') === 'Alice', 'a member sees that an ask matches their interests');
$o2 = array_values(array_filter($mine['listings'], static fn ($l) => $l['itemId'] === 'o2'))[0];
check($o2['matches']['count'] === 0, "the author's own interests don't count as a match");

echo "polls\n";
[$s, $b] = call($ext, 'POST', '/api/ext/social/v1/admin/polls', [], ['question' => 'When should we meet?', 'options' => 'Saturday | Sunday | saturday', 'closesInDays' => '7']);
check($s === 200 && is_int($b['id'] ?? null), 'admin creates a poll from the form (duplicate options merged)');
$pollId = (int) $b['id'];
[$s, $b] = call($ext, 'POST', '/api/ext/social/v1/polls', [], ['question' => 'Pick snacks', 'options' => ['Chips', 'Fruit', 'Cookies'], 'multi' => true], $did('bob'), true);
check($s === 200 && $b['poll']['multi'] === true && count($b['poll']['options']) === 3, 'a member creates a multiple-choice poll');
$bobPoll = (int) $b['poll']['id'];
[$s] = call($ext, 'POST', '/api/ext/social/v1/polls', [], ['question' => 'Only one?', 'options' => ['Yes']], $did('bob'), true);
check($s === 400, 'a poll needs at least two options');
[$s, $list] = call($ext, 'GET', '/api/ext/social/v1/polls', [], [], $did('carol'));
$p = array_values(array_filter($list['polls'], static fn ($x) => $x['id'] === $pollId))[0];
check($list['canCreate'] === true && $p['resultsVisible'] === false && $p['options'][0]['votes'] === null, 'results stay hidden until you vote');
$sat = $p['options'][0]['id'];
$sun = $p['options'][1]['id'];
[$s, $v] = call($ext, 'POST', "/api/ext/social/v1/polls/{$pollId}/vote", [], ['optionIds' => [$sat]], $did('carol'), true);
check($s === 200 && $v['poll']['resultsVisible'] === true && $v['poll']['options'][0]['votes'] === 1 && $v['poll']['myVotes'] === [$sat], 'voting records the choice and shows results');
[$s] = call($ext, 'POST', "/api/ext/social/v1/polls/{$pollId}/vote", [], ['optionIds' => [$sat, $sun]], $did('carol'), true);
check($s === 400, 'single-choice polls take one option');
[$s, $v] = call($ext, 'POST', "/api/ext/social/v1/polls/{$pollId}/vote", [], ['optionIds' => [$sun]], $did('carol'), true);
check($v['poll']['options'][0]['votes'] === 0 && $v['poll']['options'][1]['votes'] === 1 && $v['poll']['voters'] === 1, 'voting again replaces your vote');
[$s] = call($ext, 'POST', "/api/ext/social/v1/polls/{$pollId}/vote", [], ['optionIds' => [999999]], $did('alice'), true);
check($s === 400, "an option from another poll is refused");
[$s] = call($ext, 'POST', "/api/ext/social/v1/polls/{$bobPoll}/close", [], [], $did('carol'), true);
check($s === 403, "only the poll's creator can close it");
[$s, $c] = call($ext, 'POST', "/api/ext/social/v1/polls/{$bobPoll}/close", [], [], $did('bob'), true);
check($s === 200 && $c['poll']['closed'] === true && $c['poll']['resultsVisible'] === true, 'the creator closes their poll; results become visible');
[$s, $b] = call($ext, 'POST', "/api/ext/social/v1/polls/{$bobPoll}/vote", [], ['optionIds' => []], $did('alice'), true);
check($s === 409 && ($b['error'] ?? '') === 'poll_closed', 'closed polls take no votes');
[$s, $closed] = call($ext, 'GET', '/api/ext/social/v1/polls', ['status' => 'closed']);
check(count($closed['polls']) === 1 && $closed['polls'][0]['id'] === $bobPoll, 'closed polls are listed separately');
[$s, $ap] = call($ext, 'GET', '/api/ext/social/v1/admin/polls');
check(count($ap['polls']) === 2 && in_array('open', array_column($ap['polls'], 'status'), true), 'admin sees all polls with status');

echo "digest\n";
call($ext, 'POST', '/api/ext/social/v1/reactions', [], ['did' => $did('alice'), 'itemId' => 'e1', 'reaction' => 'heart'], $did('bob'), true);
[$s, $d] = call($ext, 'GET', '/api/ext/social/v1/digest');
check($s === 200 && ($d['topConversations'][0]['itemId'] ?? '') === 'e1', 'the busiest conversation leads the digest');
check(array_map(static fn ($e) => $e['itemId'], $d['upcomingEvents']) === ['e1', 'e2'], 'digest lists events in the next two weeks');
$names = array_column($d['newMembers'], 'displayName');
sort($names);
check($names === ['Alice', 'Bob'], 'digest lists members who joined in the period');
check(count($d['openPolls']) === 1 && count($d['newListings']) === 2 && $d['stats']['reactions'] === 1, 'digest includes open polls, new asks/offers and activity counts');
[$s, , $atom] = call($ext, 'GET', '/api/ext/social/v1/digest.atom');
$xml = $atom !== null ? @simplexml_load_string($atom->body) : false;
check($s === 200 && $atom !== null && str_starts_with($atom->contentType, 'application/atom+xml') && $xml !== false && count($xml->entry) === 4, 'digest.atom is a valid Atom feed with four weekly entries');

echo "updates & purging\n";
$put('alice', 'e1', 'post', ['title' => 'Repair café (cancelled)']);
$ws->emit('items_changed', ['did' => $did('alice'), 'upserted' => ['e1'], 'removed' => [], 'removedAll' => false]);
$rsvp('carol', 'alice', 'e2', 'going');
[$s, $up3] = call($ext, 'GET', '/api/ext/social/v1/events');
check(array_map(static fn ($e) => $e['itemId'], $up3['events']) === ['e2'], 'an event republished without its event details leaves the calendar');
check((int) $pdo->query("SELECT COUNT(*) FROM ext_social_rsvps WHERE item_id = 'e1'")->fetchColumn() === 0, "its RSVPs are deleted");
$ext->emit('user.extension_opt_out', ['did' => $did('carol'), 'extensionId' => 'social']);
check((int) $pdo->query("SELECT COUNT(*) FROM ext_social_rsvps WHERE did = 'did:web:carol.example'")->fetchColumn() === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM ext_social_poll_votes WHERE did = 'did:web:carol.example'")->fetchColumn() === 0, "opting out deletes the member's RSVPs and votes");
$ext->emit('user.extension_opt_out', ['did' => $did('bob'), 'extensionId' => 'social']);
check((int) $pdo->query("SELECT COUNT(*) FROM ext_social_polls WHERE created_by = 'did:web:bob.example'")->fetchColumn() === 0, 'opting out deletes the polls the member created');
$ext->emit('entry.removed', ['did' => $did('carol'), 'siteUrl' => 'https://carol.example', 'reason' => 'self']);
check((int) $pdo->query("SELECT COUNT(*) FROM ext_social_listings WHERE item_did = 'did:web:carol.example'")->fetchColumn() === 0, "removing a member deletes their listings");
[$s] = call($ext, 'DELETE', "/api/ext/social/v1/admin/polls/{$pollId}");
[$s2, $ap2] = call($ext, 'GET', '/api/ext/social/v1/admin/polls');
check($s === 200 && count($ap2['polls']) === 0, 'admin deletes a poll');

echo "settings\n";
[$pdo, $ext] = $load(['pollCreators' => 'admin', 'pollResults' => 'always']);
[$s, $b] = call($ext, 'POST', '/api/ext/social/v1/polls', [], ['question' => 'Member poll?', 'options' => ['a', 'b']], $did('alice'), true);
check($s === 403 && ($b['error'] ?? '') === 'admin_only', "pollCreators=admin stops members creating polls");
call($ext, 'POST', '/api/ext/social/v1/admin/polls', [], ['question' => 'Visible results?', 'options' => 'yes | no']);
[$s, $l] = call($ext, 'GET', '/api/ext/social/v1/polls');
check(($l['polls'][0]['resultsVisible'] ?? false) === true && $l['canCreate'] === false, 'pollResults=always shows results before voting');
[$pdo, $ext] = $load(['membersOnly' => true]);
[$s] = call($ext, 'GET', '/api/ext/social/v1/events.ics');
[$s2] = call($ext, 'GET', '/api/ext/social/v1/digest.atom');
[$s3] = call($ext, 'GET', '/api/ext/social/v1/events');
check($s === 404 && $s2 === 404 && $s3 === 401, 'members-only hubs turn off the public calendar and digest feeds');

$pdo = null;
$ext = null;
foreach ((array) glob("{$tmp}/*") as $f) {
    @unlink((string) $f);
}
@rmdir($tmp);
echo $failures === 0 ? "all social group checks passed\n" : "{$failures} check(s) failed\n";
exit($failures === 0 ? 0 : 1);
