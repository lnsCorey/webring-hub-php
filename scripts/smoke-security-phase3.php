<?php
declare(strict_types=1);

/**
 * Smoke tests for Phase 3 security hardening in webring-hub-php:
 * - Registrable domain entry cap helper
 * - public/ web root isolation and internal .htaccess deny files
 * - Nginx sample config presence
 *
 * Run with: php scripts/smoke-security-phase3.php
 */

require_once __DIR__ . '/../src/AppError.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Verify.php';
require_once __DIR__ . '/../src/Database.php';

use WebringHub\Database;

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

echo "domain_cap\n";
check(Database::registrableDomain('https://sub.example.com/blog') === 'example.com', 'extracts registrable domain from subdomain');
check(Database::registrableDomain('https://example.com') === 'example.com', 'extracts registrable domain from apex');

$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
Database::migrate($pdo);
$now = '2026-11-16T00:00:00Z';
$stmt = $pdo->prepare("INSERT INTO entries (id, did, site_url, display_name, public_key_pem, profile_updated_at, verified_at, last_checked_at, status, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->execute(['e1', 'did:web:a.example.com', 'https://a.example.com', 'A', 'k1', $now, $now, $now, 'active', $now, $now]);
$stmt->execute(['e2', 'did:web:b.example.com', 'https://b.example.com', 'B', 'k2', $now, $now, $now, 'stale', $now, $now]);
check(Database::countEntriesByRegistrableDomain($pdo, 'https://c.example.com') === 2, 'counts entries per registrable domain');
check(Database::countEntriesByRegistrableDomain($pdo, 'https://a.example.com', 'did:web:a.example.com') === 1, 'excludes self DID when counting domain entries');

echo "web_root_isolation\n";
check(is_file(__DIR__ . '/../public/index.php'), 'public/index.php exists');
check(is_file(__DIR__ . '/../public/.htaccess'), 'public/.htaccess exists');
check(is_file(__DIR__ . '/../nginx.example.conf'), 'nginx.example.conf exists');
foreach (['src', 'sql', 'scripts', 'extensions', 'docs', 'data'] as $dir) {
    $ht = __DIR__ . "/../{$dir}/.htaccess";
    check(is_file($ht) && str_contains((string) file_get_contents($ht), 'Require all denied'), "{$dir}/.htaccess denies direct access");
}

if ($failures > 0) {
    echo "{$failures} check(s) failed\n";
    exit(1);
}

echo "all phase 3 checks passed\n";
