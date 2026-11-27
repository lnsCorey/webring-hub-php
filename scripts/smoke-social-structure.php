<?php
declare(strict_types=1);

/**
 * Smoke test for Tier 4:
 * - T4.1 Topic channels (item_tags index, curated + open channels, follows, feed ?tag= & __following)
 * - T4.3 Community picks / shared library (de-duplicated share/recommendation URLs, pick_votes)
 * - T4.4 Now status wall (entries.now_text / now_updated_at, 30-day expiry)
 * - T4.5 Group challenges (admin create, tag matching, close with poll voting, challenge_closed event, badges)
 * - T4.2 Working groups (`extensions/groups/`: open & request groups, #wg-<slug> scoping, organiser handoff, group polls)
 *
 * Run with: php scripts/smoke-social-structure.php
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

/** @return array{0: int, 1: array<string, mixed>} */
function call(Extensions $ext, string $method, string $path, array $query = [], array $body = [], ?string $userDid = null, bool $optedIn = false): array
{
    $match = $ext->match($method, $path);
    if ($match === null) {
        return [404, ['error' => 'no_route']];
    }
    try {
        $res = Extensions::run($match, new ExtensionRequest($method, $path, $query, static fn (): array => $body, 'smoke', $userDid, $optedIn));
        return [$res[0], json_decode((string) json_encode($res[1]), true)];
    } catch (AppError $e) {
        return [$e->status, ['error' => $e->errorCode, 'message' => $e->getMessage()]];
    }
}

$tmp = sys_get_temp_dir() . '/webring-tier4-smoke-' . bin2hex(random_bytes(4));
@mkdir($tmp);
$dbPath = "{$tmp}/hub.sqlite";
$cfgPath = "{$tmp}/config.php";
file_put_contents($cfgPath, "<?php\nreturn [];\n");
putenv("WEBRING_HUB_CONFIG={$cfgPath}");

$config = Config::load([
    'db_driver' => 'sqlite', 'sqlite_path' => $dbPath, 'auto_migrate' => true, 'hub_url' => 'https://hub.example',
    'extensions' => ['webhook-sync', 'social', 'groups'],
    'extension_settings' => [
        'social' => [
            'channels' => [
                ['tag' => 'woodworking', 'title' => 'Woodworking', 'description' => 'Shop notes'],
            ],
            'openChannels' => true,
            'badgeThresholds' => ['participant' => 1],
        ],
    ],
]);
$pdo = Database::open($config);
$ext = Extensions::load($config, $pdo);

check($ext->isLoaded('social') && ($ext->context('social')?->schemaVersion() ?? 0) === 4, 'social migrates to schema 4');
check($ext->isLoaded('groups') && ($ext->context('groups')?->schemaVersion() ?? 0) === 1, 'groups extension loads with reads=[webhook-sync, social]');
$byId = [];
foreach ($ext->adminList() as $row) {
    $byId[$row['id']] = $row;
}
check(($byId['social']['kind'] ?? '') === 'both' && ($byId['social']['uiError'] ?? null) === null, 'social UI validates cleanly (uiError=' . ($byId['social']['uiError'] ?? 'null') . ')');
check(($byId['groups']['kind'] ?? '') === 'both' && ($byId['groups']['uiError'] ?? null) === null, 'groups UI validates cleanly (uiError=' . ($byId['groups']['uiError'] ?? 'null') . ')');

$now = Database::isoNow();
$staleNow = gmdate('Y-m-d\TH:i:s.000\Z', time() - 40 * 86400);
$insEntry = $pdo->prepare(
    "INSERT INTO entries (id, site_url, did, handle, display_name, bio, now_text, now_updated_at, interests_json, public_key_pem, profile_updated_at, verified_at, last_checked_at, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, '', ?, ?, '[]', 'pem', ?, ?, ?, ?, ?)"
);
foreach ([
    'alice' => ['Alice', 'Building a desk', $now],
    'bob' => ['Bob', 'Old status', $staleNow],
    'carol' => ['Carol', '', null],
] as $n => [$name, $nowTxt, $nowAt]) {
    $insEntry->execute([$n, "https://{$n}.example", "did:web:{$n}.example", $n, $name, $nowTxt, $nowAt, $now, $now, $now, $now, $now]);
}
$did = static fn (string $n): string => "did:web:{$n}.example";
$url = static fn (string $n, string $id): string => "https://{$n}.example/api/items.json#{$id}";

$insItem = $pdo->prepare(
    'INSERT INTO ext_webhook_sync_items (did, item_id, canonical_url, content_type, published_at, updated_at, raw_item_json, target_url) VALUES (?, ?, ?, ?, ?, NULL, ?, ?)
     ON CONFLICT(did, item_id) DO UPDATE SET raw_item_json = excluded.raw_item_json, content_type = excluded.content_type'
);
$put = static function (string $n, string $id, string $type, array $extra, string $at = '') use ($insItem, $did, $url): void {
    $at = $at ?: Database::isoNow();
    $raw = array_merge(['id' => $id, 'canonicalUrl' => $url($n, $id), 'type' => $type, 'title' => '', 'commentary' => '', 'createdAt' => $at, 'tags' => []], $extra);
    $insItem->execute([$did($n), $id, $url($n, $id), $type, $at, json_encode($raw), $extra['targetUrl'] ?? null]);
};

$ws = $ext->context('webhook-sync');

echo "T4.1 channels & follows\n";
$put('alice', 'p1', 'post', ['title' => 'Dovetails', 'tags' => ['#Woodworking', 'indieweb', 'photo-walk']], $now);
$put('bob', 'p2', 'post', ['title' => 'Web notes', 'tags' => ['indieweb', 'photo-walk']], $now);
$put('carol', 'p3', 'post', ['title' => 'My site', 'tags' => ['indieweb']], $now);
$ws->emit('items_changed', ['did' => $did('alice'), 'upserted' => ['p1'], 'removed' => [], 'removedAll' => false]);
$ws->emit('items_changed', ['did' => $did('bob'), 'upserted' => ['p2'], 'removed' => [], 'removedAll' => false]);
$ws->emit('items_changed', ['did' => $did('carol'), 'upserted' => ['p3'], 'removed' => [], 'removedAll' => false]);

[$s, $ch] = call($ext, 'GET', '/api/ext/social/v1/channels');
$chTags = array_map(static fn ($c) => $c['tag'], $ch['channels'] ?? []);
check($s === 200 && in_array('woodworking', $chTags, true) && in_array('indieweb', $chTags, true), 'channels include curated (#woodworking) and open channel used by 3+ members (#indieweb)');

[$s, $fTag] = call($ext, 'GET', '/api/ext/social/v1/feed', ['tag' => 'woodworking']);
check($s === 200 && count($fTag['items'] ?? []) === 1 && ($fTag['items'][0]['itemId'] ?? '') === 'p1', 'feed ?tag= filters via item_tags');

[$s, $fDaily] = call($ext, 'GET', '/api/ext/social/v1/feed', [
    'from' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600),
    'to' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600),
    'q' => 'dovetails',
]);
check($s === 200 && count($fDaily['items'] ?? []) === 1 && ($fDaily['items'][0]['itemId'] ?? '') === 'p1', 'Daily Feed ?from=&to=&q= filters by time range and search query');
[$s, $fPastDay] = call($ext, 'GET', '/api/ext/social/v1/feed', [
    'from' => '2025-01-01T00:00:00Z',
    'to' => '2025-01-01T23:59:59Z',
]);
check($s === 200 && count($fPastDay['items'] ?? []) === 0, 'Daily Feed excludes posts outside selected day window');

[$s, $fl] = call($ext, 'POST', '/api/ext/social/v1/follows', [], ['tag' => 'woodworking', 'on' => true], $did('bob'), true);
check($s === 200 && in_array('woodworking', $fl['follows'] ?? [], true), 'member can follow a channel');
[$s, $fFol] = call($ext, 'GET', '/api/ext/social/v1/feed', ['tag' => '__following'], [], $did('bob'), true);
check($s === 200 && count($fFol['items'] ?? []) === 1, 'Following pseudo-channel returns items from followed tags');

echo "T4.3 community picks / shared library\n";
$put('alice', 's1', 'share', ['title' => 'Great Essay', 'targetUrl' => 'https://Blog.Example/essay/'], $now);
$put('bob', 's2', 'recommendation', ['title' => 'Great Essay', 'targetUrl' => 'https://blog.example/essay/'], $now);
$ws->emit('items_changed', ['did' => $did('alice'), 'upserted' => ['s1'], 'removed' => [], 'removedAll' => false]);
$ws->emit('items_changed', ['did' => $did('bob'), 'upserted' => ['s2'], 'removed' => [], 'removedAll' => false]);

[$s, $lib] = call($ext, 'GET', '/api/ext/social/v1/library');
check($s === 200 && count($lib['picks'] ?? []) === 1 && ($lib['picks'][0]['shareCount'] ?? 0) === 2, 'library de-duplicates shares/recommendations by normalised URL');
[$s, $vt] = call($ext, 'POST', '/api/ext/social/v1/library/vote', [], ['url' => 'https://blog.example/essay/', 'on' => true], $did('carol'), true);
check($s === 200 && ($vt['votes'] ?? 0) === 1, 'library pick vote recorded');

echo "T4.4 Now status wall\n";
[$s, $nw] = call($ext, 'GET', '/api/ext/social/v1/now');
check($s === 200 && count($nw['entries'] ?? []) === 1 && ($nw['entries'][0]['did'] ?? '') === $did('alice'), 'Now wall returns recent status and hides statuses older than 30 days');

echo "T4.5 group challenges\n";
[$s, $cc] = call($ext, 'POST', '/api/ext/social/v1/admin/challenges', [], [
    'title' => 'Photo Walk', 'tag' => 'photo-walk', 'prompt' => 'Share a photo',
    'startsAt' => gmdate('Y-m-d\TH:i:s\Z', time() - 3600), 'endsAt' => gmdate('Y-m-d\TH:i:s\Z', time() + 3600), 'voting' => 'poll',
]);
$chId = (int) ($cc['challenge']['id'] ?? 0);
check($s === 201 && $chId > 0, 'admin creates challenge');
[$s, $cd] = call($ext, 'GET', "/api/ext/social/v1/challenges/{$chId}");
check($s === 200 && count($cd['entries'] ?? []) === 2, 'challenge collects tagged posts published within window');

$closedEvents = 0;
$ext->context('social')->on('ext.social.challenge_closed', static function () use (&$closedEvents): void { $closedEvents++; });
[$s, $cl] = call($ext, 'POST', "/api/ext/social/v1/admin/challenges/{$chId}/close");
check($s === 200 && ($cl['pollId'] ?? 0) > 0 && ($cl['winnerDid'] ?? '') !== '', 'closing poll-voting challenge creates Tier 2 poll and records winner');
$ext->emit('cron.refresh', []);
$bStmt = $pdo->prepare("SELECT badge FROM ext_social_badges WHERE did = ?");
$bStmt->execute([$did('alice')]);
$aliceBadges = $bStmt->fetchAll(PDO::FETCH_COLUMN);
check(in_array('participant', $aliceBadges, true) && in_array('champion', $aliceBadges, true), 'participant and champion badges awarded');

echo "T4.2 working groups\n";
[$s] = call($ext, 'GET', '/api/ext/groups/v1/groups');
check($s === 401, 'working groups default to members-only');
[$s, $gc] = call($ext, 'POST', '/api/ext/groups/v1/admin/groups', [], [
    'slug' => 'hardware', 'title' => 'Hardware', 'organizerDid' => $did('alice'), 'visibility' => 'request',
]);
check($s === 201, 'admin creates request-only working group');

// Non-member post tagged #wg-hardware must NOT appear in group feed
$put('bob', 'wg1', 'post', ['title' => 'Outsider post', 'tags' => ['wg-hardware']], $now);
$put('alice', 'wg2', 'post', ['title' => 'Member post', 'tags' => ['wg-hardware']], $now);
$ws->emit('items_changed', ['did' => $did('bob'), 'upserted' => ['wg1'], 'removed' => [], 'removedAll' => false]);
$ws->emit('items_changed', ['did' => $did('alice'), 'upserted' => ['wg2'], 'removed' => [], 'removedAll' => false]);

[$s, $gd] = call($ext, 'GET', '/api/ext/groups/v1/groups/hardware', [], [], $did('alice'), true);
check($s === 200 && count($gd['feed'] ?? []) === 1 && ($gd['feed'][0]['itemId'] ?? '') === 'wg2', 'group feed includes #wg-hardware posts by members only');

[$s] = call($ext, 'POST', '/api/ext/groups/v1/groups/hardware/join', [], [], $did('bob'), true);
check($s === 202, 'joining request-only group creates pending request');
[$s] = call($ext, 'POST', '/api/ext/groups/v1/groups/hardware/requests/' . rawurlencode($did('bob')) . '/approve', [], [], $did('alice'), true);
check($s === 200, 'organiser approves join request');

[$s, $gd2] = call($ext, 'GET', '/api/ext/groups/v1/groups/hardware', [], [], $did('bob'), true);
check($s === 200 && count($gd2['feed'] ?? []) === 2, 'once approved, member posts tagged #wg-hardware appear in group feed');

// Group-scoped poll: non-member carol cannot vote, member bob can
[$s, $gp] = call($ext, 'POST', '/api/ext/groups/v1/groups/hardware/polls', [], ['question' => 'Meet Tuesday?', 'options' => ['Yes', 'No']], $did('alice'), true);
$gPollId = (int) ($gp['pollId'] ?? 0);
check($s === 201 && $gPollId > 0, 'group member creates group-scoped poll');
$optId = (int) $pdo->query("SELECT id FROM ext_groups_poll_options WHERE poll_id = {$gPollId} LIMIT 1")->fetchColumn();
[$s] = call($ext, 'POST', "/api/ext/groups/v1/groups/hardware/polls/{$gPollId}/vote", [], ['optionId' => $optId], $did('carol'), true);
check($s === 403, 'non-member cannot vote in group poll');
[$s] = call($ext, 'POST', "/api/ext/groups/v1/groups/hardware/polls/{$gPollId}/vote", [], ['optionId' => $optId], $did('bob'), true);
check($s === 200, 'group member votes in group poll');

// Organiser leaves -> handoff to longest-standing member (bob)
call($ext, 'POST', '/api/ext/groups/v1/groups/hardware/leave', [], [], $did('alice'), true);
$newOrg = (string) $pdo->query("SELECT organizer_did FROM ext_groups_groups WHERE slug = 'hardware'")->fetchColumn();
check($newOrg === $did('bob'), 'when organiser leaves, group passes to longest-standing member');

// Opt-out purges follows, pick_votes, and group memberships (archiving empty group)
$ext->emit('user.extension_opt_out', ['did' => $did('bob'), 'extensionId' => 'social']);
$ext->emit('user.extension_opt_out', ['did' => $did('carol'), 'extensionId' => 'social']);
$ext->emit('user.extension_opt_out', ['did' => $did('bob'), 'extensionId' => 'groups']);
check((int) $pdo->query("SELECT COUNT(*) FROM ext_social_follows WHERE did = 'did:web:bob.example'")->fetchColumn() === 0, 'social opt-out deletes channel follows');
check((int) $pdo->query("SELECT COUNT(*) FROM ext_social_pick_votes WHERE voter_did = 'did:web:carol.example'")->fetchColumn() === 0, 'social opt-out deletes library pick votes');
check((int) $pdo->query("SELECT archived FROM ext_groups_groups WHERE slug = 'hardware'")->fetchColumn() === 1, 'groups opt-out removes last member and archives group');

@unlink($dbPath);
@unlink($cfgPath);
@rmdir($tmp);

echo "\n" . ($failures === 0 ? 'All Tier 4 smoke checks passed.' : "{$failures} check(s) failed.") . "\n";
exit($failures === 0 ? 0 : 1);
