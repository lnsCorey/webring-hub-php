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
$lockDir = is_dir(__DIR__ . '/../data') && is_writable(__DIR__ . '/../data')
    ? __DIR__ . '/../data'
    : sys_get_temp_dir();
$lockFile = $lockDir . '/webring-hub-refresh.lock';
$lockFp = @fopen($lockFile, 'c');
if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "[refresh] another refresh job is already running; exiting.\n");
    exit(0);
}

$pdo = Database::open($config);
$extensions = Extensions::load($config, $pdo);

$allowInsecure = !empty($config['allow_insecure']);
$allowPrivate = !empty($config['allow_private_hosts']);
$timeoutMs = (int) ($config['refresh_timeout_ms'] ?? 8000);
$budgetMs = max(1000, (int) ($config['fetch_budget_ms'] ?? 15000));
$cronBudgetSec = max(10, (int) ($config['cron_refresh_budget_seconds'] ?? 240));
$cronDeadline = microtime(true) + $cronBudgetSec;

$rows = Database::listAllForRefresh($pdo);
$total = count($rows);
fwrite(STDOUT, "[refresh] checking {$total} entries\n");

$updated = 0;
$failed = 0;

foreach ($rows as $row) {
    if (microtime(true) >= $cronDeadline) {
        fwrite(STDOUT, "[refresh] time budget ({$cronBudgetSec}s) reached; stopping early.\n");
        break;
    }
    $siteUrl = (string) ($row['site_url'] ?? '');
    try {
        $site = Site::verifySite($siteUrl, [
            'allowInsecure' => $allowInsecure,
            'allowPrivate' => $allowPrivate,
            'timeoutMs' => $timeoutMs,
            'budgetMs' => $budgetMs,
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
