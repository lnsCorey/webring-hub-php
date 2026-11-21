<?php
declare(strict_types=1);

/**
 * Router-level smoke tests: starts the hub with PHP's built-in server (`php -S`) on a throwaway
 * SQLite database and config, then drives it over HTTP. Unlike the other smoke scripts this goes
 * through public/index.php, so it catches routing bugs such as the phase 4 closure that turned
 * every extension admin route into a 500.
 *
 * Never touches config.php or data/: the server reads the temporary config named by
 * WEBRING_HUB_CONFIG. Needs the curl extension. No outbound network is required; the one
 * verification it triggers targets a reserved .example name and is expected to fail.
 *
 * Run with: php scripts/smoke-router.php
 */

error_reporting(E_ALL);

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
use WebringHub\Extensions;
use WebringHub\Verify;

$failures = 0;

function check(bool $condition, string $label): void
{
    global $failures;
    if ($condition) {
        echo "  ok   {$label}\n";
    } else {
        echo "  FAIL {$label}\n";
        $failures++;
    }
}

if (!function_exists('curl_init')) {
    fwrite(STDERR, "The curl extension is required.\n");
    exit(1);
}

// ---- temporary install ----

$tmp = sys_get_temp_dir() . '/webring-hub-router-' . bin2hex(random_bytes(4));
mkdir($tmp, 0700, true);
$dbPath = $tmp . '/hub.sqlite';
$adminToken = bin2hex(random_bytes(32));
$configPath = $tmp . '/config.php';
file_put_contents($configPath, '<?php return ' . var_export([
    'db_driver' => 'sqlite',
    'sqlite_path' => $dbPath,
    'auto_migrate' => true,
    'admin_token' => $adminToken,
    'admin_rate_limit_max_failures' => 3,
    'trust_proxy' => false,
    'rate_limit_enabled' => true,
    'refresh_cooldown_seconds' => 300,
    'fetch_budget_ms' => 4000,
    'refresh_timeout_ms' => 3000,
    'hub_url' => '',
    'ip_hash_salt' => '',
    'extensions' => ['arcade'],
], true) . ";\n");

// Seed a listing whose site later served a different key (so a candidate is waiting for review).
// auto_migrate is forced on: the operator's config.php may turn it off for the live database
$cfg = WebringHub\Config::load(['db_driver' => 'sqlite', 'sqlite_path' => $dbPath, 'extensions' => [], 'auto_migrate' => true]);
$pdo = Database::open($cfg);
$ext = Extensions::load(['extensions' => []], $pdo);
$siteUrl = 'https://alice.example';
$did = Verify::siteUrlToDidWeb($siteUrl);
$keyA = Verify::generateKeyPair();
$keyB = Verify::generateKeyPair();
$site = static fn (string $pem): array => [
    'siteUrl' => $siteUrl,
    'did' => $did,
    'manifest' => [],
    'profile' => ['displayName' => 'Alice', 'updatedAt' => '2026-11-17T00:00:00.000Z'],
    'publicKeyPem' => $pem,
    'keyRotations' => [],
];
Entries::saveVerified($pdo, $ext, $site($keyA['publicKeyPem']));
try {
    Entries::saveVerified($pdo, $ext, $site($keyB['publicKeyPem']));
} catch (AppError) {
    // key_changed, as intended
}
$fpA = Verify::spkiFingerprint($keyA['publicKeyPem']);
$fpB = Verify::spkiFingerprint($keyB['publicKeyPem']);
// Last checked long ago, so the first public refresh is allowed.
$pdo->exec("UPDATE entries SET last_checked_at = '2000-01-01T00:00:00.000Z'");
$pdo = null;

// ---- start the server ----

$sock = stream_socket_server('tcp://127.0.0.1:0');
$port = (int) substr(strrchr((string) stream_socket_get_name($sock, false), ':'), 1);
fclose($sock);
$serverLog = $tmp . '/server.log';
$env = array_merge(getenv(), ['WEBRING_HUB_CONFIG' => $configPath]);
$publicDir = realpath(__DIR__ . '/../public');
$cmd = [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'error_reporting=' . E_ALL, '-S', "127.0.0.1:{$port}", '-t', $publicDir, $publicDir . DIRECTORY_SEPARATOR . 'index.php'];
$proc = proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['file', $serverLog, 'a'], 2 => ['file', $serverLog, 'a']], $pipes, null, $env);
if (!is_resource($proc)) {
    fwrite(STDERR, "Could not start php -S.\n");
    exit(1);
}

$base = "http://127.0.0.1:{$port}";

/**
 * @param array<string, string> $headers
 * @return array{status: int, headers: array<string, string>, body: string, json: array<string, mixed>}
 */
function http(string $method, string $url, array $headers = [], ?string $body = null): array
{
    $ch = curl_init($url);
    $respHeaders = [];
    $lines = [];
    foreach ($headers as $k => $v) {
        $lines[] = "{$k}: {$v}";
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => $lines,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$respHeaders): int {
            $parts = explode(':', $line, 2);
            if (count($parts) === 2) {
                $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
            }
            return strlen($line);
        },
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $out = curl_exec($ch);
    $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $text = is_string($out) ? $out : '';
    $json = json_decode($text, true);
    return ['status' => $status, 'headers' => $respHeaders, 'body' => $text, 'json' => is_array($json) ? $json : []];
}

$admin = ['Authorization' => "Bearer {$adminToken}"];
$jsonType = ['Content-Type' => 'application/json'];

$ready = false;
for ($i = 0; $i < 50 && !$ready; $i++) {
    usleep(100000);
    $ready = http('GET', "{$base}/api/healthz")['status'] === 200;
}

try {
    check($ready, 'php -S serves the hub');

    echo "headers\n";
    $home = http('GET', "{$base}/");
    check($home['status'] === 200, 'home page renders');
    check(!isset($home['headers']['x-powered-by']), 'no X-Powered-By header');
    check(!isset($home['headers']['strict-transport-security']), 'no HSTS over plain HTTP');
    check(!str_contains($home['headers']['content-security-policy'] ?? '', 'http:'), 'CSP does not allow http: images');
    $viaTls = http('GET', "{$base}/api/healthz", ['X-Forwarded-Proto' => 'https']);
    check(str_contains($viaTls['headers']['strict-transport-security'] ?? '', 'max-age=31536000'), 'HSTS is sent when the request arrived over HTTPS');

    echo "extension admin routes (N7)\n";
    $games = http('GET', "{$base}/api/ext/arcade/v1/admin/games", $admin);
    check($games['status'] === 200 && isset($games['json']['games']), 'arcade admin route works with a valid token (was a 500)');
    $badGames = http('GET', "{$base}/api/ext/arcade/v1/admin/games", ['Authorization' => 'Bearer ' . str_repeat('0', 64)]);
    check($badGames['status'] === 401, 'arcade admin route refuses a wrong token');

    echo "input checks\n";
    $noType = http('POST', "{$base}/api/entries", [], '{"siteUrl":"https://x.example"}');
    check($noType['status'] === 415, 'POST without Content-Type: application/json is refused');

    echo "removal needs a configured hub URL (item 17)\n";
    $removeBody = static fn (string $hubUrl): string => (string) json_encode([
        'siteUrl' => $siteUrl,
        'hubUrl' => $hubUrl,
        'nonce' => 'n',
        'createdAt' => gmdate('c'),
        'signature' => 'x',
    ]);
    $enc = rawurlencode($did);
    $noHub = http('POST', "{$base}/api/entries/{$enc}/remove", $jsonType + ['Host' => 'evil-hub.example'], $removeBody('http://evil-hub.example'));
    check($noHub['status'] === 503 && ($noHub['json']['error'] ?? '') === 'hub_url_not_configured', 'removal is refused while no hub URL is set (no Host-header fallback)');
    $status = http('GET', "{$base}/api/admin/status", $admin);
    check(($status['json']['hubUrlConfigured'] ?? true) === false, 'admin status reports the missing hub URL');
    check(($status['json']['ipSaltInDatabase'] ?? false) === true, 'admin status reports the IP salt stored in the database');
    $set = http('POST', "{$base}/api/admin/settings", $admin + $jsonType, '{"directoryUrl":"https://hub.example"}');
    check($set['status'] === 200, 'operator sets the hub URL');
    check((http('GET', "{$base}/api/admin/status", $admin)['json']['hubUrlConfigured'] ?? false) === true, 'admin status reports the hub URL once set');
    $wrongHub = http('POST', "{$base}/api/entries/{$enc}/remove", $jsonType + ['Host' => 'evil-hub.example'], $removeBody('http://evil-hub.example'));
    check($wrongHub['status'] === 403 && ($wrongHub['json']['error'] ?? '') === 'hub_mismatch', 'a removal addressed to another hub is refused, whatever the Host header says');

    echo "reviewed key trust (item 24)\n";
    $list = http('GET', "{$base}/api/admin/entries", $admin);
    $entry = $list['json']['entries'][0] ?? [];
    check(($entry['keyChanged'] ?? false) === true && ($entry['candidateFingerprint'] ?? '') === $fpB, 'admin listing shows the key change and the candidate fingerprint');
    $trustUrl = "{$base}/api/admin/entries/{$enc}/trust-key";
    $noFp = http('POST', $trustUrl, $admin + $jsonType, '{}');
    check($noFp['status'] === 400, 'trust-key without expectedFingerprint is refused');
    $wrongFp = http('POST', $trustUrl, $admin + $jsonType, (string) json_encode(['expectedFingerprint' => $fpA]));
    check($wrongFp['status'] === 409 && ($wrongFp['json']['error'] ?? '') === 'candidate_mismatch', 'trust-key naming a key other than the candidate is refused');
    $unreachable = http('POST', $trustUrl, $admin + $jsonType, (string) json_encode(['expectedFingerprint' => $fpB]));
    check($unreachable['status'] >= 400, 'trust-key with the right fingerprint still re-fetches the site (unreachable here)');
    $after = http('GET', "{$base}/api/admin/entries", $admin)['json']['entries'][0] ?? [];
    check(($after['keyFingerprint'] ?? '') === $fpA, 'the pinned key is unchanged after refused trust attempts');

    echo "refresh cooldown after a failure (item 10)\n";
    $first = http('POST', "{$base}/api/entries/{$enc}/refresh", $jsonType, '{}');
    check($first['status'] >= 400 && $first['status'] !== 429, 'a refresh of an unreachable site fails');
    $second = http('POST', "{$base}/api/entries/{$enc}/refresh", $jsonType, '{}');
    check($second['status'] === 429 && ($second['json']['error'] ?? '') === 'refresh_cooldown', 'the next refresh is refused by the cooldown');
    check((int) ($second['headers']['retry-after'] ?? 0) > 0 && (int) ($second['json']['retryAfterSeconds'] ?? 0) > 0, 'the cooldown reply says when to retry');

    echo "admin sign-in limit\n";
    for ($i = 0; $i < 3; $i++) {
        http('GET', "{$base}/api/admin/status", ['Authorization' => 'Bearer wrong-token']);
    }
    $locked = http('GET', "{$base}/api/admin/status", $admin);
    check($locked['status'] === 429, 'after repeated failed sign-ins even the right token is refused for a while');

    $log = (string) @file_get_contents($serverLog);
    check(!preg_match('/PHP (Warning|Notice|Deprecated|Fatal error)|Undefined variable/', $log), 'no PHP warnings or errors in the server log');
} finally {
    proc_terminate($proc);
    proc_close($proc);
    foreach ((array) glob($tmp . '/*') as $f) {
        @unlink((string) $f);
    }
    @rmdir($tmp);
}

if ($failures > 0) {
    echo "{$failures} check(s) failed\n";
    if (isset($log) && $log !== '') {
        echo "--- server log ---\n" . substr($log, -4000) . "\n";
    }
    exit(1);
}

echo "all router checks passed\n";
