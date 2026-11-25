<?php
declare(strict_types=1);

/**
 * Smoke test for the `social` extension (Conversations & Reactions) and the framework pieces it
 * relies on: extension events, `reads` dependencies and webhook-sync's `target_url` column.
 *
 * Run with: php scripts/smoke-social.php
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

/**
 * @return array{0: int, 1: array<string, mixed>}
 */
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

$dbPath = sys_get_temp_dir() . '/webring-social-smoke-' . bin2hex(random_bytes(4)) . '.sqlite';
// Never read the real config.php: its extension list would leak into these scenarios.
$cfgPath = sys_get_temp_dir() . '/webring-social-smoke-' . bin2hex(random_bytes(4)) . '.config.php';
file_put_contents($cfgPath, "<?php
return [];
");
putenv("WEBRING_HUB_CONFIG={$cfgPath}");
$baseConfig = [
    'db_driver' => 'sqlite',
    'sqlite_path' => $dbPath,
    'auto_migrate' => true,
    'hub_url' => 'https://hub.example',
];
$load = static function (array $ids, array $settings = []) use ($baseConfig): array {
    $config = Config::load(array_merge($baseConfig, ['extensions' => $ids, 'extension_settings' => ['social' => $settings]]));
    $pdo = Database::open($config);
    return [$pdo, Extensions::load($config, $pdo)];
};

echo "loader & dependencies\n";
[$pdo, $ext] = $load(['social']);
$err = array_values(array_filter($ext->adminList(), static fn ($e) => $e['id'] === 'social'))[0]['error'] ?? '';
check(!$ext->isLoaded('social') && str_contains((string) $err, 'webhook-sync'), 'social refuses to load without webhook-sync, naming it');
[$pdo, $ext] = $load(['social', 'webhook-sync'], ['autoHideReports' => 3]);
check($ext->isLoaded('webhook-sync') && $ext->isLoaded('social'), 'social loads after webhook-sync even when listed first');
$social = array_values(array_filter($ext->adminList(), static fn ($e) => $e['id'] === 'social'))[0] ?? [];
check(($social['kind'] ?? '') === 'both' && count($social['panels'] ?? []) === 5 && ($social['uiError'] ?? null) === null, 'social declares a public card and 5 valid admin panels');
check(($ext->context('webhook-sync')?->schemaVersion() ?? 0) === 3, 'webhook-sync is at schema 3');

// ---------------------------------------------------------------------------
// Seed members and items (as webhook-sync would after verifying them)
// ---------------------------------------------------------------------------
$now = Database::isoNow();
$insEntry = $pdo->prepare(
    "INSERT INTO entries (id, site_url, did, handle, display_name, public_key_pem, profile_updated_at, verified_at, last_checked_at, created_at, updated_at)
     VALUES (?, ?, ?, ?, ?, 'pem', ?, ?, ?, ?, ?)"
);
foreach (['alice' => 'alice', 'bob' => 'bob', 'carol' => 'sam', 'dave' => 'sam', 'eve' => 'eve', 'mallory' => 'mallory'] as $name => $handle) {
    $insEntry->execute([$name, "https://{$name}.example", "did:web:{$name}.example", $handle, ucfirst($name), $now, $now, $now, $now, $now]);
}
$did = static fn (string $n): string => "did:web:{$n}.example";
$url = static fn (string $n, string $id): string => "https://{$n}.example/api/items.json#{$id}";

$items = [
    ['alice', 'a1', 'post', '2026-11-20T10:00:00.000Z', ['title' => 'Hello', 'commentary' => 'Hi @bob and did:web:carol.example, also @sam and @alice']],
    ['bob', 'b1', 'comment', '2026-11-20T11:00:00.000Z', ['commentary' => 'Nice post!', 'targetUrl' => $url('alice', 'a1')]],
    ['bob', 'b2', 'like', '2026-11-20T11:05:00.000Z', ['commentary' => '', 'targetUrl' => $url('alice', 'a1')]],
    ['carol', 'c1', 'comment', '2026-11-20T12:00:00.000Z', ['commentary' => 'Agreed', 'targetUrl' => $url('bob', 'b1'), 'targetAuthorUrl' => 'https://bob.example']],
    ['alice', 'a2', 'comment', '2026-11-20T13:00:00.000Z', ['commentary' => 'Thanks both', 'targetUrl' => $url('carol', 'c1')]],
    // Mallory claims Alice's canonical URL: it must not capture replies to a1.
    ['mallory', 'm1', 'post', '2026-11-20T09:00:00.000Z', ['commentary' => 'forged', 'canonicalUrl' => $url('alice', 'a1')]],
    // Studio publishes posts with targetUrl = their own canonicalUrl; stored as-is here (pre-migration rows).
    ['eve', 'e1', 'post', '2026-11-19T09:00:00.000Z', ['commentary' => 'Eve here', 'targetUrl' => $url('eve', 'e1')]],
];
$insItem = $pdo->prepare(
    'INSERT INTO ext_webhook_sync_items (did, item_id, canonical_url, content_type, published_at, updated_at, raw_item_json, target_url) VALUES (?, ?, ?, ?, ?, NULL, ?, ?)'
);
$byMember = [];
foreach ($items as [$n, $id, $type, $at, $extra]) {
    $canonical = $extra['canonicalUrl'] ?? $url($n, $id);
    $raw = array_merge(['id' => $id, 'canonicalUrl' => $canonical, 'type' => $type, 'title' => '', 'createdAt' => $at, 'tags' => []], $extra);
    $insItem->execute([$did($n), $id, $canonical, $type, $at, json_encode($raw), $extra['targetUrl'] ?? null]);
    $byMember[$n][] = $id;
}
$wsCtx = $ext->context('webhook-sync');
foreach ($byMember as $n => $ids) {
    $wsCtx->emit('items_changed', ['did' => $did($n), 'upserted' => $ids, 'removed' => [], 'removedAll' => false]);
}

echo "mentions\n";
$mentions = $pdo->query('SELECT item_id, mentioned_did, source FROM ext_social_mentions ORDER BY item_id, mentioned_did')->fetchAll(PDO::FETCH_NUM);
$m = array_map(static fn ($r) => implode('|', $r), $mentions);
check(in_array('a1|did:web:bob.example|handle', $m, true), '@handle resolves to the one member with that handle');
check(in_array('a1|did:web:carol.example|did', $m, true), 'did:web: token resolves to an active member');
check(!in_array('a1|did:web:dave.example|handle', $m, true) && !in_array('a1|did:web:carol.example|handle', $m, true), 'an ambiguous @handle (two members) is skipped');
check(!in_array('a1|did:web:alice.example|handle', $m, true), 'an author never mentions themselves');
check(in_array('c1|did:web:bob.example|target', $m, true), 'targetAuthorUrl counts as a mention of that member');

echo "feed & threads\n";
[$s, $feed] = call($ext, 'GET', '/api/ext/social/v1/feed');
$feedIds = array_map(static fn ($i) => $i['itemId'], $feed['items'] ?? []);
check($s === 200 && $feedIds === ['a1', 'm1', 'e1'], 'feed lists top-level items only, newest first (' . implode(',', $feedIds) . ')');
$a1 = $feed['items'][0] ?? [];
check(($a1['replyCount'] ?? -1) === 1 && ($a1['author']['displayName'] ?? '') === 'Alice', 'a1 carries author details and counts one comment reply (likes are not replies)');
check(($feed['items'][1]['replyCount'] ?? -1) === 0, "a forged canonical URL doesn't capture another member's replies");
[$s, $page] = call($ext, 'GET', '/api/ext/social/v1/feed', ['limit' => '2']);
[$s2, $page2] = call($ext, 'GET', '/api/ext/social/v1/feed', ['limit' => '2', 'before' => (string) ($page['nextCursor'] ?? '')]);
check(count($page['items']) === 2 && ($page2['items'][0]['itemId'] ?? '') === 'e1' && $page2['nextCursor'] === null, 'feed pages with a compound cursor');

[$s, $thread] = call($ext, 'GET', '/api/ext/social/v1/thread', ['did' => $did('alice'), 'id' => 'a2']);
$root = $thread['root'] ?? [];
$b1 = $root['children'][0] ?? [];
check($s === 200 && ($root['itemId'] ?? '') === 'a1' && ($thread['focus']['itemId'] ?? '') === 'a2', 'a thread opened from a deep reply climbs to its root');
check(count($root['children'] ?? []) === 2 && ($b1['itemId'] ?? '') === 'b1' && ($b1['children'][0]['children'][0]['itemId'] ?? '') === 'a2', 'thread nests replies in order (a1 → b1 → c1 → a2, plus the like)');
check(($thread['nodeCount'] ?? 0) === 5 && $thread['truncated'] === false, 'thread counts its nodes');
[$s, $selfThread] = call($ext, 'GET', '/api/ext/social/v1/thread', ['did' => $did('eve'), 'id' => 'e1']);
check($s === 200 && ($selfThread['root']['itemId'] ?? '') === 'e1' && ($selfThread['root']['children'] ?? null) === [], 'a post whose targetUrl is its own URL is its own root, not a reply to itself');
[$s] = call($ext, 'GET', '/api/ext/social/v1/thread', ['did' => $did('alice'), 'id' => 'nope']);
check($s === 404, 'unknown thread item is 404');

echo "reactions\n";
$react = static fn (string $who, string $n, string $id, string $r, bool $on = true) => call($ext, 'POST', '/api/ext/social/v1/reactions', [], ['did' => $did($n), 'itemId' => $id, 'reaction' => $r, 'on' => $on], $did($who), true);
[$s, $r1] = $react('bob', 'alice', 'a1', 'heart');
[$s2, $r2] = $react('bob', 'alice', 'a1', 'heart');
check($s === 200 && ($r2['reactions']['heart'] ?? 0) === 1 && $r2['myReactions'] === ['heart'], 'reacting is idempotent');
$react('carol', 'alice', 'a1', 'heart');
$react('carol', 'alice', 'a1', 'celebrate');
[$s, $r3] = $react('carol', 'alice', 'a1', 'celebrate', false);
check(($r3['reactions']['heart'] ?? 0) === 2 && !isset($r3['reactions']['celebrate']), 'reactions toggle off');
[$s] = $react('bob', 'alice', 'a1', 'angry');
check($s === 400, 'a reaction outside the allowlist is refused');
[$s, $feedBob] = call($ext, 'GET', '/api/ext/social/v1/feed', [], [], $did('bob'));
check(($feedBob['items'][0]['myReactions'] ?? []) === ['heart'] && ($feedBob['items'][0]['reactions']['heart'] ?? 0) === 2, "feed shows counts and the viewer's own reactions");

echo "reports & moderation\n";
$report = static fn (string $who, string $n, string $id, string $reason = 'spam') => call($ext, 'POST', '/api/ext/social/v1/reports', [], ['did' => $did($n), 'itemId' => $id, 'reason' => $reason, 'note' => "note\x01"], $did($who), true);
[$s, $b] = $report('bob', 'bob', 'b1');
check($s === 400 && ($b['error'] ?? '') === 'own_item', 'you cannot report your own item');
[$s] = $report('carol', 'alice', 'a1');
[$s2, $b2] = $report('carol', 'alice', 'a1');
check($s === 200 && $s2 === 409 && ($b2['error'] ?? '') === 'already_reported', 'one report per member per item');
[$s] = $report('dave', 'alice', 'a1', 'abuse');
[$s, $b3] = $report('eve', 'alice', 'a1', 'other');
check(($b3['autoHidden'] ?? false) === true, 'the third distinct report hides the item automatically');
[$s, $feed2] = call($ext, 'GET', '/api/ext/social/v1/feed');
check(!in_array('a1', array_map(static fn ($i) => $i['itemId'], $feed2['items']), true), 'a hidden item leaves the feed');
[$s, $th2] = call($ext, 'GET', '/api/ext/social/v1/thread', ['did' => $did('bob'), 'id' => 'b1']);
check(($th2['root']['hidden'] ?? false) === true && !isset($th2['root']['commentary']) && count($th2['root']['children']) === 2, 'a hidden thread root becomes a placeholder; its replies stay visible');
[$s] = $react('bob', 'alice', 'a1', 'like');
check($s === 404, 'hidden items cannot be reacted to');

[$s, $reps] = call($ext, 'GET', '/api/ext/social/v1/admin/reports', ['status' => 'open']);
$rep = $reps['reports'][0] ?? [];
check(count($reps['reports']) === 1 && $rep['reportCount'] === 3 && $rep['visibility'] === 'hidden' && str_contains($rep['reasons'], 'abuse'), 'admin sees one grouped report row with reasons and current visibility');
$note = $pdo->query("SELECT note FROM ext_social_reports WHERE reporter_did = 'did:web:carol.example'")->fetchColumn();
check($note === 'note', 'control characters are stripped from report notes');
call($ext, 'POST', '/api/ext/social/v1/admin/reports/' . $rep['id'] . '/dismiss');
[$s, $feed3] = call($ext, 'GET', '/api/ext/social/v1/feed');
check(in_array('a1', array_map(static fn ($i) => $i['itemId'], $feed3['items']), true), 'dismissing reports undoes an automatic hide');
[$s] = $report('mallory', 'alice', 'a1');
[$s, $reps2] = call($ext, 'GET', '/api/ext/social/v1/admin/reports');
call($ext, 'POST', '/api/ext/social/v1/admin/reports/' . ($reps2['reports'][0]['id'] ?? 0) . '/hide');
[$s, $hidden] = call($ext, 'GET', '/api/ext/social/v1/admin/hidden');
check(count($hidden['hidden']) === 1 && $hidden['hidden'][0]['reason'] === 'admin', 'admin hide is listed as hidden by admin');
[$s, $reps3] = call($ext, 'GET', '/api/ext/social/v1/admin/reports');
check(count($reps3['reports']) === 0, 'hiding resolves the open reports');
[$s] = call($ext, 'POST', '/api/ext/social/v1/admin/hidden/' . $hidden['hidden'][0]['id'] . '/restore');
[$s2, $hidden2] = call($ext, 'GET', '/api/ext/social/v1/admin/hidden');
check($s === 200 && count($hidden2['hidden']) === 0, 'restore shows the item again');
[$s] = call($ext, 'POST', '/api/ext/social/v1/admin/hidden/999/restore');
check($s === 404, 'restoring an unknown id is 404');
[$s, $sum] = call($ext, 'GET', '/api/ext/social/v1/admin/summary');
check($s === 200 && $sum['openReports'] === 0 && $sum['reactions7d'] >= 0 && $sum['activeThreads7d'] >= 0, 'admin summary answers');

echo "activity\n";
[$s, $act] = call($ext, 'GET', '/api/ext/social/v1/me/activity', [], [], $did('alice'), true);
$kinds = array_map(static fn ($e) => $e['kind'] . ':' . ($e['item']['itemId'] ?? $e['target']['itemId']), $act['events'] ?? []);
check(in_array('reply:b1', $kinds, true) && in_array('reply:b2', $kinds, true), "replies to alice's items appear in her activity");
check(in_array('reactions:a1', $kinds, true) && !in_array('reply:a2', $kinds, true), "reactions on her items are grouped; her own replies aren't listed");
check(($act['unread'] ?? 0) === count($act['events']) && $act['lastSeenAt'] === null, 'everything is unread before the first visit');
call($ext, 'POST', '/api/ext/social/v1/me/activity/seen', [], [], $did('alice'), true);
[$s, $act2] = call($ext, 'GET', '/api/ext/social/v1/me/activity', [], [], $did('alice'), true);
check($act2['unread'] === 0 && $act2['lastSeenAt'] !== null, 'marking seen clears unread');
[$s, $actBob] = call($ext, 'GET', '/api/ext/social/v1/me/activity', [], [], $did('bob'), false);
$bobKinds = array_map(static fn ($e) => $e['kind'] . ':' . ($e['item']['itemId'] ?? $e['target']['itemId']), $actBob['events']);
check(in_array('mention:a1', $bobKinds, true) && in_array('mention:c1', $bobKinds, true) && in_array('reply:c1', $bobKinds, true), 'mentions and replies reach bob');
check($actBob['unread'] === 0 && $actBob['lastSeenAt'] === null, 'no read-marker is kept for a member who has not opted in');

echo "purging\n";
$react('alice', 'bob', 'b1', 'like');
$b1Key = hash('sha256', $did('bob') . "
b1");
$before = (int) $pdo->query("SELECT COUNT(*) FROM ext_social_reactions WHERE item_key = '{$b1Key}'")->fetchColumn();
$wsCtx->emit('items_changed', ['did' => $did('bob'), 'upserted' => [], 'removed' => ['b1'], 'removedAll' => false]);
$after = (int) $pdo->query("SELECT COUNT(*) FROM ext_social_reactions WHERE item_key = '{$b1Key}'")->fetchColumn();
check($before === 1 && $after === 0, "a removed item's reactions are deleted");
$ext->emit('user.extension_opt_out', ['did' => $did('bob'), 'extensionId' => 'social']);
check((int) $pdo->query("SELECT COUNT(*) FROM ext_social_reactions WHERE reactor_did = 'did:web:bob.example'")->fetchColumn() === 0, "opting out deletes the member's reactions");
check((int) $pdo->query("SELECT COUNT(*) FROM ext_social_mentions WHERE mentioned_did = 'did:web:bob.example'")->fetchColumn() > 0, "opting out keeps others' public mentions of the member");
$ext->emit('user.extension_opt_out', ['did' => $did('carol'), 'extensionId' => 'arcade']);
check((int) $pdo->query("SELECT COUNT(*) FROM ext_social_reactions WHERE reactor_did = 'did:web:carol.example'")->fetchColumn() === 1, "another extension's opt-out leaves social data alone");
$pdo->exec("UPDATE entries SET status = 'blocked' WHERE did = 'did:web:carol.example'");
$ext->emit('entry.status_changed', ['did' => $did('carol'), 'status' => 'blocked']);
check((int) $pdo->query("SELECT COUNT(*) FROM ext_social_reports WHERE reporter_did = 'did:web:carol.example'")->fetchColumn() === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM ext_social_mentions WHERE mentioned_did = 'did:web:carol.example'")->fetchColumn() === 0, 'blocking a member purges their actions and mentions of them');
$pdo->exec("UPDATE entries SET status = 'stale' WHERE did = 'did:web:eve.example'");
[$s, $feed4] = call($ext, 'GET', '/api/ext/social/v1/feed');
check(!in_array('e1', array_map(static fn ($i) => $i['itemId'], $feed4['items']), true), 'stale members are left out of the feed');
$ext->emit('entry.removed', ['did' => $did('alice'), 'siteUrl' => 'https://alice.example', 'reason' => 'self']);
check((int) $pdo->query("SELECT COUNT(*) FROM ext_social_reactions WHERE item_did = 'did:web:alice.example'")->fetchColumn() === 0
    && (int) $pdo->query("SELECT COUNT(*) FROM ext_social_reports WHERE item_did = 'did:web:alice.example'")->fetchColumn() === 0, "removing a member deletes everything about their items");

$pdo->exec("INSERT INTO ext_social_hidden (item_key, item_did, item_id, reason, hidden_at) VALUES ('k', 'did:web:gone.example', 'x', 'admin', '{$now}')");
$ext->emit('cron.refresh', ['checked' => 0, 'updated' => 0, 'failed' => 0]);
check((int) $pdo->query("SELECT COUNT(*) FROM ext_social_hidden WHERE item_did = 'did:web:gone.example'")->fetchColumn() === 0, 'cron prunes rows whose item left the hub');

echo "members-only & webhook-sync backfill\n";
[$pdo, $ext] = $load(['webhook-sync', 'social'], ['membersOnly' => true]);
[$s, $b] = call($ext, 'GET', '/api/ext/social/v1/feed');
[$s2] = call($ext, 'GET', '/api/ext/social/v1/feed', [], [], $did('bob'));
check($s === 401 && ($b['error'] ?? '') === 'auth_required' && $s2 === 200, 'membersOnly requires sign-in to read');
$pdo->exec("UPDATE ext_webhook_sync_items SET target_url = NULL");
$pdo->exec("UPDATE settings SET `value` = '1' WHERE `key` = 'ext:webhook-sync:schema'");
[$pdo, $ext] = $load(['webhook-sync', 'social']);
$t = $pdo->query("SELECT target_url FROM ext_webhook_sync_items WHERE item_id = 'b2'")->fetchColumn();
check($t === 'https://alice.example/api/items.json#a1', 'webhook-sync v2 backfills target_url from raw_item_json');


echo "live router: sign-in, CSRF and opt-in gating\n";
require_once __DIR__ . '/../src/Auth.php';
$adminToken = bin2hex(random_bytes(16));
file_put_contents($cfgPath, '<?php return ' . var_export([
    'db_driver' => 'sqlite',
    'sqlite_path' => $dbPath,
    'auto_migrate' => true,
    'hub_url' => 'https://hub.example',
    'admin_token' => $adminToken,
    'extensions' => ['webhook-sync', 'social'],
    'rate_limit_enabled' => false,
], true) . ";\n");
$rawToken = bin2hex(random_bytes(32));
$csrf = bin2hex(random_bytes(24));
$pdo->prepare('INSERT INTO user_sessions (token_hash, did, csrf_token, created_at, last_seen_at, expires_at) VALUES (?, ?, ?, ?, ?, ?)')
    ->execute([hash('sha256', $rawToken), $did('bob'), $csrf, $now, $now, gmdate('Y-m-d\TH:i:s.000\Z', time() + 3600)]);
$pdo = null;
$ext = null;

$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr((string) stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$serverLog = sys_get_temp_dir() . '/webring-social-smoke-' . $port . '.log';
$publicDir = realpath(__DIR__ . '/../public');
$proc = proc_open(
    [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=' . E_ALL, '-S', "127.0.0.1:{$port}", '-t', $publicDir, $publicDir . DIRECTORY_SEPARATOR . 'index.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']],
    $pipes,
    null,
    array_merge(getenv(), ['WEBRING_HUB_CONFIG' => $cfgPath])
);
$base = "http://127.0.0.1:{$port}";

/** @return array{status: int, headers: array<string, string>, json: array<string, mixed>, body: string} */
function http(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ch = curl_init($url);
    $resp = [];
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => array_map(static fn ($k, $v) => "{$k}: {$v}", array_keys($headers), $headers),
        CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$resp): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $resp[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $out = (string) curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($out, true);
    return ['status' => $status, 'headers' => $resp, 'json' => is_array($json) ? $json : [], 'body' => $out];
}

try {
    $ready = false;
    for ($i = 0; $i < 50 && !$ready; $i++) {
        usleep(100000);
        $ready = http('GET', "{$base}/api/healthz")['status'] === 200;
    }
    check($ready, 'php -S serves the hub');
    $page = http('GET', "{$base}/ext/social/");
    check($page['status'] === 200 && str_contains($page['body'], '/hub-auth.js') && str_contains($page['headers']['content-security-policy'] ?? '', "script-src 'self'"), '/ext/social/ serves the page with a strict CSP');
    check(http('GET', "{$base}/ext/social/app.js")['status'] === 200 && http('GET', "{$base}/ext/social/card.svg")['status'] === 200, 'page script and card art are served');
    check(http('GET', "{$base}/api/ext/social/v1/feed")['status'] === 200, 'feed is public by default');
    $ics = http('GET', "{$base}/api/ext/social/v1/events.ics");
    check($ics['status'] === 200 && str_starts_with($ics['headers']['content-type'] ?? '', 'text/calendar') && str_starts_with($ics['body'], 'BEGIN:VCALENDAR')
        && str_contains($ics['headers']['content-security-policy'] ?? '', "default-src 'none'"), 'raw extension responses (events.ics) are served as text/calendar with a locked-down CSP');

    $cookie = ['Cookie' => "webring_hub_session={$rawToken}", 'Content-Type' => 'application/json'];
    $reactBody = (string) json_encode(['did' => 'did:web:mallory.example', 'itemId' => 'm1', 'reaction' => 'like', 'on' => true]);
    $r = http('POST', "{$base}/api/ext/social/v1/reactions", ['Content-Type' => 'application/json'], $reactBody);
    check($r['status'] === 401 && ($r['json']['error'] ?? '') === 'auth_required', 'reacting signed out is 401');
    $r = http('POST', "{$base}/api/ext/social/v1/reactions", $cookie, $reactBody);
    check($r['status'] === 403 && ($r['json']['error'] ?? '') === 'csrf_invalid', 'reacting without the CSRF header is 403');
    $r = http('POST', "{$base}/api/ext/social/v1/reactions", $cookie + ['X-Hub-Csrf' => $csrf, 'Sec-Fetch-Site' => 'cross-site'], $reactBody);
    check($r['status'] === 403 && ($r['json']['error'] ?? '') === 'csrf_rejected', 'a cross-site request is rejected');
    $r = http('POST', "{$base}/api/ext/social/v1/reactions", $cookie + ['X-Hub-Csrf' => $csrf], $reactBody);
    check($r['status'] === 403 && ($r['json']['error'] ?? '') === 'opt_in_required', 'reacting before opting in is 403');

    $pdo = new PDO('sqlite:' . $dbPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    \WebringHub\Auth::setConsent($pdo, $did('bob'), 'social', true);
    $pdo = null;
    $r = http('POST', "{$base}/api/ext/social/v1/reactions", $cookie + ['X-Hub-Csrf' => $csrf], $reactBody);
    check($r['status'] === 200 && ($r['json']['myReactions'] ?? []) === ['like'], 'signed in, opted in and with CSRF, the reaction is recorded');
    $a = http('GET', "{$base}/api/ext/social/v1/me/activity", ['Cookie' => "webring_hub_session={$rawToken}"]);
    check($a['status'] === 200 && isset($a['json']['events']), 'activity answers for the signed-in member');
    $c = http('GET', "{$base}/api/ext/social/v1/config", ['Cookie' => "webring_hub_session={$rawToken}"]);
    check(($c['json']['signedIn'] ?? false) === true && ($c['json']['optedIn'] ?? false) === true, 'config reports sign-in and opt-in state');
    $ping = http('POST', "{$base}/api/ext/webhook-sync/v1/ping", $cookie, '{}');
    check($ping['status'] !== 403 && !str_starts_with((string) ($ping['json']['error'] ?? ''), 'csrf'), 'a session cookie without CSRF does not block routes that need no sign-in');
    $admin = http('GET', "{$base}/api/ext/social/v1/admin/summary", ['Authorization' => "Bearer {$adminToken}"]);
    check($admin['status'] === 200 && isset($admin['json']['openReports']), 'admin summary works over HTTP with the Bearer token');

    $log = (string) @file_get_contents($serverLog);
    check(!preg_match('/PHP (Warning|Notice|Deprecated|Fatal error)/', $log), 'no PHP warnings or errors in the server log');
    if ($failures > 0) {
        echo "--- server log ---\n" . substr($log, -3000) . "\n";
    }
} finally {
    proc_terminate($proc);
    proc_close($proc);
    @unlink($serverLog);
}

@unlink($dbPath);
@unlink($cfgPath);
echo $failures === 0 ? "all social checks passed\n" : "{$failures} check(s) failed\n";
exit($failures === 0 ? 0 : 1);
