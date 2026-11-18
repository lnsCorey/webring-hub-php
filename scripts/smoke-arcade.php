<?php
declare(strict_types=1);

/**
 * Smoke test for Phase 0 static-file hosting (`serveFiles`) and the `arcade` extension.
 *
 * Run with: php scripts/smoke-arcade.php
 */

require_once __DIR__ . '/../src/AppError.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Verify.php';
require_once __DIR__ . '/../src/Net.php';
require_once __DIR__ . '/../src/Site.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Extensions.php';
require_once __DIR__ . '/../src/Entries.php';

use WebringHub\AppError;
use WebringHub\Database;
use WebringHub\Entries;
use WebringHub\ExtensionRequest;
use WebringHub\Extensions;
use WebringHub\Verify;

$failures = 0;

function check(bool $ok, string $label): void
{
    global $failures;
    if ($ok) {
        echo "  ok   {$label}\n";
    } else {
        echo "  FAIL {$label}\n";
        $failures++;
    }
}

function expectError(callable $fn, string $code, string $label): void
{
    global $failures;
    try {
        $fn();
        echo "  FAIL {$label} (did not throw)\n";
        $failures++;
    } catch (AppError $e) {
        check($e->errorCode === $code, "{$label} ({$e->errorCode})");
    }
}

function callExt(Extensions $ext, string $method, string $path, array $query = [], array $body = [], string $clientKey = 'client-a'): array
{
    $match = $ext->match($method, $path);
    if ($match === null) {
        throw new RuntimeException("No route matched {$method} {$path}");
    }
    $req = new ExtensionRequest($method, $path, $query, static fn (): array => $body, $clientKey);
    return Extensions::run($match, $req);
}

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Database::migrate($pdo);

$ext = Extensions::load(['extensions' => ['arcade']], $pdo);

echo "loader & static files\n";
check($ext->isLoaded('arcade'), 'arcade extension loads');
$adminInfo = $ext->adminList()[0] ?? [];
check(($adminInfo['schemaVersion'] ?? 0) === 2, 'arcade migrated to schema 2');
check(in_array('/ext/arcade/', $adminInfo['public'] ?? [], true), 'adminList reports /ext/arcade/ public mount');

$landing = $ext->resolvePublicFile('/ext/arcade/');
check($landing !== null && str_ends_with($landing['filePath'], 'index.html'), 'resolves /ext/arcade/ to public/index.html');
check(($landing['frameable'] ?? true) === false, 'arcade landing page is not frameable');

$gameHtml = $ext->resolvePublicFile('/ext/arcade/games/vector-rift/index.html');
check($gameHtml !== null && ($gameHtml['frameable'] ?? false) === true, 'game HTML is frameable');
check(($gameHtml['headers']['Access-Control-Allow-Origin'] ?? '') === '*', 'game route sets CORS header');
check(
    str_starts_with($gameHtml['headers']['Content-Security-Policy'] ?? '', 'sandbox allow-scripts allow-pointer-lock;'),
    'game pages are sandboxed by CSP even when opened directly (no access to hub storage)'
);

$thumbSvg = $ext->resolvePublicFile('/ext/arcade/games/vector-rift/thumbnail.svg');
check(
    $thumbSvg !== null && str_contains($thumbSvg['headers']['Content-Security-Policy'] ?? '', "default-src 'none'"),
    'SVG asset receives restrictive CSP'
);

expectError(static fn () => $ext->resolvePublicFile('/ext/arcade/../Extension.php'), 'forbidden', 'blocks .. path traversal');
expectError(static fn () => $ext->resolvePublicFile('/ext/arcade/%2e%2e/Extension.php'), 'invalid_path', 'blocks encoded traversal');
expectError(static fn () => $ext->resolvePublicFile('/ext/arcade/.htaccess'), 'forbidden', 'blocks dotfiles');

echo "catalog\n";
[$status, $list] = callExt($ext, 'GET', '/api/ext/arcade/v1/games');
check($status === 200 && ($list['total'] ?? 0) === 2, 'discovers both shipped games (vector-rift & starfall-siege)');
[$status, $filtered] = callExt($ext, 'GET', '/api/ext/arcade/v1/games', ['tag' => 'vector']);
check($status === 200 && ($filtered['total'] ?? 0) === 1 && ($filtered['games'][0]['slug'] ?? '') === 'vector-rift', 'filters games by tag');

[$status, $detail] = callExt($ext, 'GET', '/api/ext/arcade/v1/games/vector-rift');
check($status === 200 && ($detail['game']['title'] ?? '') === 'Vector Rift', 'returns single game details');

echo "sessions, scores & leaderboards\n";
$keys = Verify::generateKeyPair();
$aliceDid = 'did:web:alice.example';
Entries::saveVerified($pdo, $ext, [
    'siteUrl' => 'https://alice.example',
    'did' => $aliceDid,
    'manifest' => [],
    'profile' => [
        'displayName' => 'Alice',
        'handle' => 'alice',
        'avatarUrl' => 'https://alice.example/avatar.png',
        'updatedAt' => '2026-11-16T12:00:00Z',
    ],
    'publicKeyPem' => $keys['publicKeyPem'],
    'keyRotations' => [],
]);

expectError(
    static fn () => callExt($ext, 'POST', '/api/ext/arcade/v1/games/vector-rift/sessions', [], ['did' => 'did:web:unknown.example']),
    'player_not_found',
    'rejects session for unlisted DID'
);

[$status, $sess1] = callExt($ext, 'POST', '/api/ext/arcade/v1/games/vector-rift/sessions', [], ['did' => $aliceDid]);
check($status === 201 && !empty($sess1['sessionId']), 'creates play session for active DID');

[$status, $score1] = callExt($ext, 'POST', '/api/ext/arcade/v1/games/vector-rift/scores', [], [
    'sessionId' => $sess1['sessionId'],
    'did' => $aliceDid,
    'score' => 4200,
    'durationMs' => 35000,
    'meta' => ['wave' => 3],
]);
check($status === 200 && ($score1['accepted'] ?? false) === true && ($score1['isPersonalBest'] ?? false) === true && ($score1['rank'] ?? 0) === 1, 'accepts valid score and records personal best');

expectError(
    static fn () => callExt($ext, 'POST', '/api/ext/arcade/v1/games/vector-rift/scores', [], [
        'sessionId' => $sess1['sessionId'],
        'did' => $aliceDid,
        'score' => 5000,
        'durationMs' => 40000,
    ]),
    'session_used',
    'rejects replayed session token'
);

[$status, $sess2] = callExt($ext, 'POST', '/api/ext/arcade/v1/games/vector-rift/sessions', [], ['did' => $aliceDid]);
[$status, $score2] = callExt($ext, 'POST', '/api/ext/arcade/v1/games/vector-rift/scores', [], [
    'sessionId' => $sess2['sessionId'],
    'did' => $aliceDid,
    'score' => 1800,
    'durationMs' => 20000,
]);
check($status === 200 && ($score2['isPersonalBest'] ?? true) === false && ($score2['best'] ?? 0) === 4200, 'lower run keeps higher personal best');

[$status, $lb] = callExt($ext, 'GET', '/api/ext/arcade/v1/games/vector-rift/scores');
check($status === 200 && count($lb['scores'] ?? []) === 1 && ($lb['scores'][0]['displayName'] ?? '') === 'Alice', 'leaderboard joins active entry displayName');

[$status, $hist] = callExt($ext, 'GET', '/api/ext/arcade/v1/players/' . rawurlencode($aliceDid) . '/history');
check($status === 200 && count($hist['bestScores'] ?? []) === 1 && count($hist['recentPlays'] ?? []) === 2, 'player history returns bests and both runs');

check(($score2['verified'] ?? true) === false, 'score responses say the score is unverified');
check(($lb['scores'][0]['verified'] ?? true) === false, 'leaderboard rows carry verified=false');

echo "public reads don't write\n";
$snapshot = static fn (): string => json_encode($pdo->query('SELECT slug, present, updated_at, last_seen_at FROM ext_arcade_games ORDER BY slug')->fetchAll())
    . '|' . $pdo->query('SELECT COUNT(*) FROM ext_arcade_limits')->fetchColumn();
$freshExt = Extensions::load(['extensions' => ['arcade']], $pdo); // a new request: no per-request cache
$pdo->exec("UPDATE ext_arcade_games SET last_seen_at = '2000-01-01T00:00:00Z'");
$before = $snapshot();
callExt($freshExt, 'GET', '/api/ext/arcade/v1/games');
callExt($freshExt, 'GET', '/api/ext/arcade/v1/games/vector-rift');
callExt($freshExt, 'GET', '/api/ext/arcade/v1/games/vector-rift/scores');
check($snapshot() === $before, 'catalog, game and leaderboard reads leave the database untouched');
$freshExt->emit('cron.refresh', ['checked' => 0, 'updated' => 0, 'failed' => 0]);
check((string) $pdo->query("SELECT last_seen_at FROM ext_arcade_games WHERE slug = 'vector-rift'")->fetchColumn() !== '2000-01-01T00:00:00Z', 'the cron refresh rescans the games folder');

echo "per-client limits\n";
$quotaPdo = new PDO('sqlite::memory:');
$quotaPdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$quotaPdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
Database::migrate($quotaPdo);
$quotaExt = Extensions::load([
    'extensions' => ['arcade'],
    'extension_settings' => ['arcade' => ['max_sessions_per_did_hour' => 2, 'max_sessions_per_ip_hour' => 3]],
], $quotaPdo);
$bobDid = 'did:web:bob_b.example';
$carolDid = 'did:web:bobxb.example';
foreach ([$bobDid, $carolDid] as $playerDid) {
    Entries::saveVerified($quotaPdo, $quotaExt, [
        'siteUrl' => 'https://' . substr($playerDid, strlen('did:web:')),
        'did' => $playerDid,
        'manifest' => [],
        'profile' => ['displayName' => $playerDid, 'updatedAt' => '2026-11-17T00:00:00Z'],
        'publicKeyPem' => Verify::generateKeyPair()['publicKeyPem'],
        'keyRotations' => [],
    ]);
}
$startAs = static fn (string $did, string $client) => callExt($quotaExt, 'POST', '/api/ext/arcade/v1/games/vector-rift/sessions', [], ['did' => $did], $client);
$startAs($bobDid, 'attacker');
$startAs($bobDid, 'attacker');
expectError(static fn () => $startAs($bobDid, 'attacker'), 'rate_limited', 'one client is limited per player');
[$status] = $startAs($bobDid, 'real-bob');
check($status === 201, "another client can still start sessions for that player (the attacker can't use up their quota)");
$startAs($carolDid, 'attacker');
expectError(static fn () => $startAs($carolDid, 'attacker'), 'rate_limited', 'one client is limited across all players');

echo "exact purge\n";
$carolRowsBefore = (int) $quotaPdo->query("SELECT COUNT(*) FROM ext_arcade_limits WHERE did = 'did:web:bobxb.example'")->fetchColumn();
Entries::remove($quotaPdo, $quotaExt, $bobDid, 'admin');
$carolRowsAfter = (int) $quotaPdo->query("SELECT COUNT(*) FROM ext_arcade_limits WHERE did = 'did:web:bobxb.example'")->fetchColumn();
check($carolRowsBefore > 0 && $carolRowsAfter === $carolRowsBefore, 'removing bob_b does not purge bobxb (_ is not a wildcard)');
check((int) $quotaPdo->query("SELECT COUNT(*) FROM ext_arcade_limits WHERE did = 'did:web:bob_b.example'")->fetchColumn() === 0, "removing a player deletes that player's limit rows");

echo "admin & cleanup\n";
callExt($ext, 'POST', '/api/ext/arcade/v1/admin/games/starfall-siege/enabled', [], ['enabled' => false]);
[$status, $afterDisable] = callExt($ext, 'GET', '/api/ext/arcade/v1/games');
check(($afterDisable['total'] ?? 0) === 1, 'disabled game is hidden from public catalog');
callExt($ext, 'POST', '/api/ext/arcade/v1/admin/games/starfall-siege/enabled', [], ['enabled' => true]);

Entries::remove($pdo, $ext, $aliceDid, 'self');
[$status, $lbAfterRemove] = callExt($ext, 'GET', '/api/ext/arcade/v1/games/vector-rift/scores');
check(count($lbAfterRemove['scores'] ?? []) === 0, 'entry.removed deletes player scores and history');

if ($failures > 0) {
    echo "{$failures} check(s) failed\n";
    exit(1);
}

echo "all arcade checks passed\n";
