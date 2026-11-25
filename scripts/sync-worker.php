#!/usr/bin/env php
<?php
declare(strict_types=1);

namespace WebringHub;

use WebringHub\Ext\WebhookSync\WebhookSyncExtension;

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
$lockFile = $lockDir . '/webring-hub-sync-worker.lock';
$lockFp = @fopen($lockFile, 'c');
if ($lockFp === false || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, "[sync-worker] another sync-worker process is already running; exiting.\n");
    exit(0);
}

// Optional CLI override: php scripts/sync-worker.php --limit=25
$cliLimit = null;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) {
        $cliLimit = max(1, min(200, (int) $m[1]));
    }
}

$pdo = Database::open($config);
$extensions = Extensions::load($config, $pdo);
$ctx = $extensions->context('webhook-sync');

if ($ctx === null) {
    fwrite(STDOUT, "[sync-worker] extension 'webhook-sync' is not enabled in config.php; nothing to do.\n");
    exit(0);
}

$stats = WebhookSyncExtension::runBatch($pdo, $ctx, $extensions, $cliLimit);
fwrite(
    STDOUT,
    sprintf(
        "[sync-worker] claimed=%d completed=%d notModified=%d retried=%d failed=%d\n",
        $stats['claimed'],
        $stats['completed'],
        $stats['notModified'],
        $stats['retried'],
        $stats['failed']
    )
);
