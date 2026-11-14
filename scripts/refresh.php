#!/usr/bin/env php
<?php
declare(strict_types=1);

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

$config = Config::load();
$pdo = Database::open($config);
$extensions = Extensions::load($config, $pdo);

$allowInsecure = !empty($config['allow_insecure']);
$allowPrivate = !empty($config['allow_private_hosts']);
$timeoutMs = (int) ($config['refresh_timeout_ms'] ?? 8000);

$rows = Database::listAllForRefresh($pdo);
$total = count($rows);
fwrite(STDOUT, "[refresh] checking {$total} entries\n");

$updated = 0;
$failed = 0;

foreach ($rows as $row) {
    $siteUrl = (string) ($row['site_url'] ?? '');
    try {
        $site = Site::verifySite($siteUrl, [
            'allowInsecure' => $allowInsecure,
            'allowPrivate' => $allowPrivate,
            'timeoutMs' => $timeoutMs,
        ]);
        if ($site['did'] !== $row['did']) {
            throw new \RuntimeException('the site now publishes a different did:web identity');
        }
        Entries::saveVerified($pdo, $extensions, $site);
        $updated++;
    } catch (\Throwable $err) {
        $message = $err->getMessage() ?: 'Unknown error';
        Database::recordFailure($pdo, $row, $message);
        $failed++;
        fwrite(STDERR, "[refresh] {$siteUrl}: {$message}\n");
    }
}

$extensions->emit('cron.refresh', ['checked' => $total, 'updated' => $updated, 'failed' => $failed]);

$untouched = $total - $updated - $failed;
fwrite(STDOUT, "[refresh] done: {$updated} updated, {$failed} failed, {$untouched} untouched\n");
