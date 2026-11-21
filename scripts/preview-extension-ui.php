#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Local preview helper for the Hub Extension UI (carousel, admin panels, key-witness, Arcade).
 * Boots the hub on a throwaway SQLite database in the system temp folder with sample listings,
 * key observations, and arcade scores, then launches PHP's built-in server.
 *
 * Writes nothing outside the system temp folder.
 *
 *   php scripts/preview-extension-ui.php [port]
 */

namespace WebringHub;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI execution only.\n";
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

$port = isset($argv[1]) && is_numeric($argv[1]) ? (int) $argv[1] : 8091;
$tmpDir = sys_get_temp_dir() . '/webring-hub-preview-' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0777, true);
$dbPath = $tmpDir . '/preview.sqlite';
$adminToken = 'preview-admin-token-' . bin2hex(random_bytes(12));

$config = Config::load([
    'db_driver' => 'sqlite',
    'sqlite_path' => $dbPath,
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $dbPath],
    'admin_token' => $adminToken,
    'extensions' => ['key-witness', 'arcade'],
    'auto_migrate' => true,
    'instance' => [
        'title' => 'Neon Webring Hub',
        'description' => 'Local preview with key-witness admin panels and the retro-modern Arcade.',
        'hubUrl' => "http://127.0.0.1:{$port}",
    ],
]);

$pdo = Database::open($config);
$ext = Extensions::load($config, $pdo);

// Seed sample active listings
$aliceKey1 = Verify::generateKeyPair();
$aliceKey2 = Verify::generateKeyPair();
$bobKey = Verify::generateKeyPair();

$samples = [
    [
        'siteUrl' => 'https://alice.example',
        'did' => 'did:web:alice.example',
        'handle' => 'alice',
        'displayName' => 'Alice Synth',
        'tagline' => 'Building tiny retro games and static sites',
        'bio' => 'Indie web creator.',
        'location' => 'Portland, OR',
        'avatarUrl' => '',
        'bannerUrl' => '',
        'interests' => ['indieweb', 'arcade', 'synth'],
        'links' => [],
        'publicKeyPem' => $aliceKey1['publicKeyPem'],
        'profileUpdatedAt' => Database::isoNow(),
        'keyRotations' => [],
    ],
    [
        'siteUrl' => 'https://bob.example',
        'did' => 'did:web:bob.example',
        'handle' => 'bob',
        'displayName' => 'Bob Vector',
        'tagline' => 'Vector graphics & cryptography notes',
        'bio' => 'Static-first publishing enthusiast.',
        'location' => 'Toronto, ON',
        'avatarUrl' => '',
        'bannerUrl' => '',
        'interests' => ['graphics', 'crypto', 'webrings'],
        'links' => [],
        'publicKeyPem' => $bobKey['publicKeyPem'],
        'profileUpdatedAt' => Database::isoNow(),
        'keyRotations' => [],
    ],
];

foreach ($samples as $site) {
    Entries::saveVerified($pdo, $ext, $site);
}

// Trigger a rejected key + operator acceptance for Alice so key-witness panels show rich data
try {
    Entries::saveVerified($pdo, $ext, array_merge($samples[0], ['publicKeyPem' => $aliceKey2['publicKeyPem']]));
} catch (AppError) {
    // Expected 409 key_changed -> recorded as 'rejected' by key-witness
}
Entries::saveVerified($pdo, $ext, array_merge($samples[0], ['publicKeyPem' => $aliceKey2['publicKeyPem']]), true);

// Seed sample Arcade scores
$runExt = static function (string $method, string $path, array $body = []) use ($ext): array {
    $match = $ext->match($method, $path);
    if ($match === null) {
        return [404, []];
    }
    return Extensions::run($match, new ExtensionRequest($method, $path, [], static fn (): array => $body, 'preview-client'));
};

foreach (
    [
        ['vector-rift', 'did:web:alice.example', 12450, 48000],
        ['vector-rift', 'did:web:bob.example', 9800, 39000],
        ['starfall-siege', 'did:web:alice.example', 18200, 62000],
    ] as [$slug, $did, $score, $dur]
) {
    [, $sess] = $runExt('POST', "/api/ext/arcade/v1/games/{$slug}/sessions", ['did' => $did]);
    if (!empty($sess['sessionId'])) {
        $runExt('POST', "/api/ext/arcade/v1/games/{$slug}/scores", [
            'sessionId' => $sess['sessionId'],
            'did' => $did,
            'score' => $score,
            'durationMs' => $dur,
        ]);
    }
}

$pdo = null;

$baseUrl = "http://127.0.0.1:{$port}";
echo "\nWebring Hub Extension UI Preview\n";
echo "================================\n";
echo "  Home (carousel): {$baseUrl}/\n";
echo "  Arcade:          {$baseUrl}/ext/arcade/\n";
echo "  Admin panels:    {$baseUrl}/admin\n";
echo "  API info:        {$baseUrl}/api/info\n";
echo "  Admin token:     {$adminToken}\n";
echo "  Temp DB:         {$dbPath}\n";
echo "\nPress Ctrl+C to stop.\n\n";

$env = array_merge($_ENV, $_SERVER, [
    'DB_DRIVER' => 'sqlite',
    'DATABASE_PATH' => $dbPath,
    'ADMIN_TOKEN' => $adminToken,
    'HUB_EXTENSIONS' => 'key-witness,arcade',
    'HUB_TITLE' => 'Neon Webring Hub',
    'HUB_DESCRIPTION' => 'Local preview with key-witness admin panels and the retro-modern Arcade.',
    'HUB_URL' => $baseUrl,
    'AUTO_MIGRATE' => '1',
]);

$docRoot = realpath(__DIR__ . '/../public') ?: (__DIR__ . '/../public');
$router = $docRoot . DIRECTORY_SEPARATOR . 'index.php';
$cmd = [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $docRoot, $router];
$proc = proc_open($cmd, [0 => STDIN, 1 => STDOUT, 2 => STDERR], $pipes, $docRoot, $env);
if (!is_resource($proc)) {
    fwrite(STDERR, "Failed to start PHP built-in server.\n");
    exit(1);
}
$code = proc_close($proc);
@unlink($dbPath);
@unlink($dbPath . '-wal');
@unlink($dbPath . '-shm');
@rmdir($tmpDir);
exit($code);
