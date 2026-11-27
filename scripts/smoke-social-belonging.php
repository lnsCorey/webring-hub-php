<?php
declare(strict_types=1);

/**
 * Smoke test for the `social` extension's Tier 3 belonging & identity features:
 * vouches (including deduplication, self-vouch rejection, site_url match, subject hide toggle,
 * revocation and key-witness check), welcome wall & preset greetings, daily spotlight,
 * admin roles & computed badges, and owner-opt-in guestbooks (with CSV export, reports & moderation).
 *
 * Run with: php scripts/smoke-social-belonging.php
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

$tmp = sys_get_temp_dir() . '/webring-belonging-smoke-' . bin2hex(random_bytes(4));
@mkdir($tmp);
$dbPath = "{$tmp}/hub.sqlite";
$cfgPath = "{$tmp}/config.php";
file_put_contents($cfgPath, "<?php\nreturn [];\n");
putenv("WEBRING_HUB_CONFIG={$cfgPath}");

$load = static function (array $settings = []) use ($dbPath): array {
    $config = Config::load([
        'db_driver' => 'sqlite', 'sqlite_path' => $dbPath, 'auto_migrate' => true, 'hub_url' => 'https://hub.example',
        'extensions' => ['key-witness', 'webhook-sync', 'social'],
        'extension_settings' => ['social' => array_merge(['roles' => ['steward'], 'badgeThresholds' => ['voucher' => 1, 'welcomer' => 1, 'host' => 1, 'helper' => 1, 'voice' => 1]], $settings)],
    ]);
    $pdo = Database::open($config);
    return [$pdo, Extensions::load($config, $pdo)];
};
[$pdo, $ext] = $load();
check($ext->isLoaded('social') && ($ext->context('social')?->schemaVersion() ?? 0) >= 3, 'social migrates to schema 3+');

$now = Database::isoNow();
$old = '2026-01-01T00:00:00.000Z';
$insEntry = $pdo->prepare(
    "INSERT INTO entries (id, site_url, did, handle, display_name, bio, interests_json, public_key_pem, profile_updated_at, verified_at, last_checked_at, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, '[]', 'pem', ?, ?, ?, ?, ?)"
);
foreach ([
    'alice' => ['Alice', 'Builder', $old],
    'bob' => ['Bob', 'Woodworker', $now],
    'carol' => ['Carol', 'Newcomer', $now],
    'dave' => ['Dave', 'Guest', $now],
] as $n => [$name, $bio, $created]) {
    $insEntry->execute([$n, "https://{$n}.example", "did:web:{$n}.example", $n, $name, $bio, $now, $now, $now, $created, $now]);
}
$did = static fn (string $n): string => "did:web:{$n}.example";
$url = static fn (string $n, string $id): string => "https://{$n}.example/api/items.json#{$id}";

$insItem = $pdo->prepare(
    'INSERT INTO ext_webhook_sync_items (did, item_id, canonical_url, content_type, published_at, updated_at, raw_item_json, target_url) VALUES (?, ?, ?, ?, ?, NULL, ?, ?)
     ON CONFLICT(did, item_id) DO UPDATE SET raw_item_json = excluded.raw_item_json, content_type = excluded.content_type, published_at = excluded.published_at'
);
$put = static function (string $n, string $id, string $type, array $extra, string $at = '') use ($insItem, $did, $url): void {
    $at = $at ?: Database::isoNow();
    $raw = array_merge(['id' => $id, 'canonicalUrl' => $url($n, $id), 'type' => $type, 'title' => '', 'commentary' => '', 'createdAt' => $at, 'tags' => []], $extra);
    $insItem->execute([$did($n), $id, $url($n, $id), $type, $at, json_encode($raw), $extra['targetUrl'] ?? null]);
};

$ws = $ext->context('webhook-sync');

echo "vouches\n";
$t1 = gmdate('Y-m-d\TH:i:s.000\Z', time() - 3600);
$t2 = gmdate('Y-m-d\TH:i:s.000\Z', time() - 60);
$put('alice', 'v1', 'post', ['title' => 'Met Bob', 'vouch' => ['subjectDid' => $did('bob'), 'subjectSiteUrl' => 'https://bob.example', 'kind' => 'met']], $t1);
$put('alice', 'v2', 'post', ['title' => 'Met Bob again', 'vouch' => ['subjectDid' => $did('bob'), 'subjectSiteUrl' => 'https://bob.example', 'kind' => 'met']], $t2);
$put('alice', 'v3', 'post', ['title' => 'Bob woodworks', 'vouch' => ['subjectDid' => $did('bob'), 'subjectSiteUrl' => 'https://bob.example', 'kind' => 'skill', 'skill' => 'Woodworking']], $t2);
$put('alice', 'vself', 'post', ['title' => 'Self vouch', 'vouch' => ['subjectDid' => $did('alice'), 'subjectSiteUrl' => 'https://alice.example', 'kind' => 'met']], $t2);
$put('alice', 'vbadurl', 'post', ['title' => 'Wrong site', 'vouch' => ['subjectDid' => $did('bob'), 'subjectSiteUrl' => 'https://evil.example', 'kind' => 'met']], $t2);
$ws->emit('items_changed', ['did' => $did('alice'), 'upserted' => ['v1', 'v2', 'v3', 'vself', 'vbadurl'], 'removed' => [], 'removedAll' => false]);

[$s, $mv] = call($ext, 'GET', '/api/ext/social/v1/members/vouches', ['did' => $did('bob')]);
check($s === 200 && ($mv['totalReceived'] ?? 0) === 2, 'deduplicates (voucher, subject, kind, skill) and ignores self-vouch + wrong site_url');
check(($mv['grouped']['met'][0]['itemId'] ?? '') === 'v2', 'newest vouch wins deduplication');
check(count($mv['grouped']['skill']['Woodworking'] ?? []) === 1, 'skill vouch grouped under skill name');

// Optional key-witness check: record a key rotation after v2's published_at
$pdo->prepare(
    "INSERT INTO ext_key_witness_observations (did, site_url, fingerprint, public_key_pem, outcome, first_seen_at, last_seen_at)
     VALUES (?, 'https://alice.example', 'fp1', 'pem', 'rotated', ?, ?)"
)->execute([$did('alice'), Database::isoNow(), Database::isoNow()]);
[$s, $mvKw] = call($ext, 'GET', '/api/ext/social/v1/members/vouches', ['did' => $did('bob')]);
check($s === 200 && ($mvKw['received'][0]['keyChangedSince'] ?? false) === true, 'key-witness marks vouch keyChangedSince when voucher key rotated after published_at');

// Subject hides vouches about themselves
[$s, $vp] = call($ext, 'POST', '/api/ext/social/v1/me/vouch-prefs', [], ['hideReceived' => true], $did('bob'), true);
check($s === 200 && ($vp['hideReceived'] ?? false) === true, 'subject can hide vouches about themselves');
[$s, $mvHidden] = call($ext, 'GET', '/api/ext/social/v1/members/vouches', ['did' => $did('bob')]);
check($s === 200 && ($mvHidden['hidden'] ?? false) === true && ($mvHidden['received'] ?? []) === [], 'other viewers see no received vouches when hidden');
[$s, $recent] = call($ext, 'GET', '/api/ext/social/v1/vouches/recent');
check($s === 200 && ($recent['vouches'] ?? []) === [], 'recent vouches excludes subjects who hid received vouches');
call($ext, 'POST', '/api/ext/social/v1/me/vouch-prefs', [], ['hideReceived' => false], $did('bob'), true);

// Revocation: removing v3 drops the skill vouch
$pdo->prepare("DELETE FROM ext_webhook_sync_items WHERE did = ? AND item_id = 'v3'")->execute([$did('alice')]);
$ws->emit('items_changed', ['did' => $did('alice'), 'upserted' => [], 'removed' => ['v3'], 'removedAll' => false]);
[$s, $mvAfter] = call($ext, 'GET', '/api/ext/social/v1/members/vouches', ['did' => $did('bob')]);
check($s === 200 && ($mvAfter['totalReceived'] ?? 0) === 1, 'removing vouch post revokes vouch');

echo "welcome & spotlight\n";
$put('carol', 'intro1', 'post', ['title' => 'Hello everyone!', 'commentary' => 'Glad to be here', 'tags' => ['Intro', 'woodworking']], $now);
$ws->emit('items_changed', ['did' => $did('carol'), 'upserted' => ['intro1'], 'removed' => [], 'removedAll' => false]);
[$s, $wel] = call($ext, 'GET', '/api/ext/social/v1/welcome');
$wDids = array_map(static fn ($m) => $m['did'], $wel['members'] ?? []);
check($s === 200 && in_array($did('carol'), $wDids, true) && !in_array($did('alice'), $wDids, true), 'welcome wall lists newcomers within welcomeDays only');
$carolWel = array_values(array_filter($wel['members'] ?? [], static fn ($m) => $m['did'] === $did('carol')))[0] ?? [];
check(($carolWel['introPost']['itemId'] ?? '') === 'intro1', 'welcome card links newcomer #intro post');

[$s] = call($ext, 'POST', '/api/ext/social/v1/greetings', [], ['subjectDid' => $did('carol'), 'kind' => 'wave'], $did('carol'), true);
check($s === 400, 'cannot greet yourself');
[$s, $gr] = call($ext, 'POST', '/api/ext/social/v1/greetings', [], ['subjectDid' => $did('carol'), 'kind' => 'wave', 'on' => true], $did('alice'), true);
check($s === 200 && ($gr['counts']['wave'] ?? 0) === 1, 'preset greeting recorded');
[$s] = call($ext, 'POST', '/api/ext/social/v1/greetings', [], ['subjectDid' => $did('carol'), 'kind' => 'hug'], $did('alice'), true);
check($s === 400, 'unknown greeting kind rejected');

[$s, $sp1] = call($ext, 'GET', '/api/ext/social/v1/spotlight');
[$s2, $sp2] = call($ext, 'GET', '/api/ext/social/v1/spotlight');
check($s === 200 && ($sp1['member']['did'] ?? '') !== '' && ($sp1['member']['did'] ?? '') === ($sp2['member']['did'] ?? ''), 'spotlight picks one deterministic active poster for today');

echo "roles & badges\n";
[$s] = call($ext, 'POST', '/api/ext/social/v1/admin/roles', [], ['did' => $did('alice'), 'role' => 'organizer']);
check($s === 201, 'admin assigns built-in role');
[$s] = call($ext, 'POST', '/api/ext/social/v1/admin/roles', [], ['did' => $did('alice'), 'role' => 'steward']);
check($s === 201, 'admin assigns configured custom role');
[$s] = call($ext, 'POST', '/api/ext/social/v1/admin/roles', [], ['did' => $did('alice'), 'role' => 'unknown-role']);
check($s === 400, 'unconfigured role rejected');

// Trigger badge recomputation via cron.refresh
$ext->emit('cron.refresh', []);
[$s, $feed] = call($ext, 'GET', '/api/ext/social/v1/feed');
$alicePost = array_values(array_filter($feed['items'] ?? [], static fn ($it) => $it['did'] === $did('alice')))[0] ?? [];
check(in_array('organizer', $alicePost['author']['roles'] ?? [], true), 'feed items include author roles');
check(in_array('voucher', $alicePost['author']['badges'] ?? [], true) && in_array('welcomer', $alicePost['author']['badges'] ?? [], true) && in_array('founding', $alicePost['author']['badges'] ?? [], true), 'cron.refresh awards earned badges (voucher, welcomer, founding)');

echo "guestbooks\n";
[$s] = call($ext, 'POST', '/api/ext/social/v1/guestbook', [], ['ownerDid' => $did('bob'), 'body' => 'Hi Bob!'], $did('alice'), true);
check($s === 403, 'cannot sign guestbook until owner opts in');

[$s, $gbOn] = call($ext, 'POST', '/api/ext/social/v1/me/guestbook', [], ['enabled' => true], $did('bob'), true);
check($s === 200 && ($gbOn['enabled'] ?? false) === true, 'owner enables guestbook');
[$s, $gbSign] = call($ext, 'POST', '/api/ext/social/v1/guestbook', [], ['ownerDid' => $did('bob'), 'body' => 'Great woodworking!'], $did('alice'), true);
check($s === 201 && ($gbSign['entry']['id'] ?? 0) > 0, 'opted-in member signs guestbook');
$entryId = (int) ($gbSign['entry']['id'] ?? 0);

[$s] = call($ext, 'POST', '/api/ext/social/v1/guestbook', [], ['ownerDid' => $did('bob'), 'body' => 'Second note same day'], $did('alice'), true);
check($s === 429, 'one entry per author per owner per day enforced');

[$s, $gbGet] = call($ext, 'GET', '/api/ext/social/v1/guestbook', ['did' => $did('bob')]);
check($s === 200 && count($gbGet['entries'] ?? []) === 1, 'guestbook returns signed entries');

// CSV export
[$s, $_, $csvRaw] = call($ext, 'GET', '/api/ext/social/v1/me/guestbook.csv', [], [], $did('bob'), true);
check($s === 200 && $csvRaw instanceof ExtensionRawResponse && str_contains($csvRaw->body, 'Great woodworking!'), 'owner exports guestbook as CSV');

// Report & admin hide/restore
[$s] = call($ext, 'POST', "/api/ext/social/v1/guestbook/{$entryId}/report", [], ['reason' => 'spam'], $did('carol'), true);
check($s === 201, 'guestbook entry can be reported');
[$s, $gReps] = call($ext, 'GET', '/api/ext/social/v1/admin/guestbook/reports');
$repId = (int) ($gReps['reports'][0]['id'] ?? 0);
check($s === 200 && $repId > 0, 'admin sees open guestbook reports');
[$s] = call($ext, 'POST', "/api/ext/social/v1/admin/guestbook/reports/{$repId}/hide");
check($s === 200, 'admin hides reported guestbook entry');
[$s, $gbAfterHide] = call($ext, 'GET', '/api/ext/social/v1/guestbook', ['did' => $did('bob')]);
check($s === 200 && ($gbAfterHide['entries'] ?? []) === [], 'hidden guestbook entry disappears from public view');
[$s] = call($ext, 'POST', "/api/ext/social/v1/admin/guestbook/hidden/{$entryId}/restore");
check($s === 200, 'admin restores hidden guestbook entry');

// Disable keeps entries hidden without deleting
call($ext, 'POST', '/api/ext/social/v1/me/guestbook', [], ['enabled' => false], $did('bob'), true);
[$s, $gbDis] = call($ext, 'GET', '/api/ext/social/v1/guestbook', ['did' => $did('bob')]);
check($s === 200 && ($gbDis['enabled'] ?? true) === false && ($gbDis['entries'] ?? []) === [], 'disabling guestbook hides entries');
call($ext, 'POST', '/api/ext/social/v1/me/guestbook', [], ['enabled' => true], $did('bob'), true);
[$s, $gbRe] = call($ext, 'GET', '/api/ext/social/v1/guestbook', ['did' => $did('bob')]);
check($s === 200 && count($gbRe['entries'] ?? []) === 1, 're-enabling guestbook shows kept entries');

// Non-owner/non-author cannot delete; author can delete
[$s] = call($ext, 'DELETE', "/api/ext/social/v1/guestbook/{$entryId}", [], [], $did('carol'), true);
check($s === 403, 'third party cannot delete guestbook entry');
[$s] = call($ext, 'DELETE', "/api/ext/social/v1/guestbook/{$entryId}", [], [], $did('alice'), true);
check($s === 200, 'author can delete own guestbook entry');

echo "purge paths\n";
// Opt-out deletes greetings, vouch_prefs, guestbook, but keeps admin roles & earned badges
call($ext, 'POST', '/api/ext/social/v1/guestbook', [], ['ownerDid' => $did('bob'), 'body' => 'From Dave'], $did('dave'), true);
$ext->emit('user.extension_opt_out', ['did' => $did('alice'), 'extensionId' => 'social']);
$cnt = static fn (string $sql, array $a) => (int) (($s = $pdo->prepare($sql)) && $s->execute($a) ? $s->fetchColumn() : -1);
check($cnt("SELECT COUNT(*) FROM ext_social_greetings WHERE greeter_did = ?", [$did('alice')]) === 0, 'opt-out deletes member greetings');
check($cnt("SELECT COUNT(*) FROM ext_social_roles WHERE did = ?", [$did('alice')]) === 2, 'opt-out preserves admin-assigned roles');
check($cnt("SELECT COUNT(*) FROM ext_social_badges WHERE did = ?", [$did('alice')]) >= 1, 'opt-out preserves earned badges');

// Author opt-out deletes their guestbook entries
$ext->emit('user.extension_opt_out', ['did' => $did('dave'), 'extensionId' => 'social']);
check($cnt("SELECT COUNT(*) FROM ext_social_guestbook_entries WHERE author_did = ?", [$did('dave')]) === 0, 'author opt-out deletes their guestbook entries');

// Removal purges roles, badges, vouches, guestbook
$ext->emit('entry.removed', ['did' => $did('alice')]);
check($cnt("SELECT COUNT(*) FROM ext_social_roles WHERE did = ?", [$did('alice')]) === 0, 'entry.removed purges roles');
check($cnt("SELECT COUNT(*) FROM ext_social_badges WHERE did = ?", [$did('alice')]) === 0, 'entry.removed purges badges');
check($cnt("SELECT COUNT(*) FROM ext_social_vouches WHERE voucher_did = ?", [$did('alice')]) === 0, 'entry.removed purges vouches');

// Blocked purges owner guestbook
$ext->emit('entry.status_changed', ['did' => $did('bob'), 'status' => 'blocked']);
check($cnt("SELECT COUNT(*) FROM ext_social_guestbooks WHERE owner_did = ?", [$did('bob')]) === 0, 'blocked entry purges guestbook');

@unlink($dbPath);
@unlink($cfgPath);
@rmdir($tmp);

echo "\n" . ($failures === 0 ? 'All belonging smoke checks passed.' : "{$failures} check(s) failed.") . "\n";
exit($failures === 0 ? 0 : 1);
