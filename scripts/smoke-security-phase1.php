<?php
declare(strict_types=1);

/**
 * Smoke tests for Phase 1 security hardening:
 * - Config defaults (trust_proxy off, trusted_proxy_hops, global_crawl_rate_limit)
 * - Net::resolveClientIp() proxy hop parsing & fallback
 * - Net::safeGetJson() host/port/percent-encoding/IDN guards
 * - Database::checkRateLimit() global crawl cap + hourly window preservation
 *
 * Run with: php scripts/smoke-security-phase1.php
 */

require_once __DIR__ . '/../src/AppError.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Net.php';
require_once __DIR__ . '/../src/Database.php';

use WebringHub\AppError;
use WebringHub\Config;
use WebringHub\Database;
use WebringHub\Net;

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

function expectAppError(callable $fn, string $expectedCode, string $label): void
{
    global $failures;
    try {
        $fn();
        echo "  FAIL {$label} (did not throw)\n";
        $failures++;
    } catch (AppError $e) {
        if ($e->errorCode === $expectedCode) {
            echo "  ok   {$label}\n";
        } else {
            echo "  FAIL {$label} (expected {$expectedCode}, got {$e->errorCode}: {$e->getMessage()})\n";
            $failures++;
        }
    }
}

echo "config\n";
$example = require __DIR__ . '/../config.example.php';
check(($example['trust_proxy'] ?? true) === false, 'config.example.php defaults trust_proxy to false');
check(($example['trusted_proxy_hops'] ?? 0) === 1, 'config.example.php defaults trusted_proxy_hops to 1');
$cfg = Config::load([]);
check(($cfg['global_crawl_rate_limit']['window_seconds'] ?? 0) === 60, 'Config::load sets global_crawl_rate_limit window_seconds');
check(($cfg['global_crawl_rate_limit']['max'] ?? 0) >= 1, 'Config::load sets global_crawl_rate_limit max');

echo "clientIp\n";
$spoofServer = [
    'REMOTE_ADDR' => '198.51.100.10',
    'HTTP_X_FORWARDED_FOR' => '203.0.113.99, 192.0.2.50',
];
check(Net::resolveClientIp($spoofServer, false, 1) === '198.51.100.10', 'ignores X-Forwarded-For when trust_proxy is false');
check(Net::resolveClientIp($spoofServer, true, 1) === '192.0.2.50', 'takes rightmost IP when trusted_proxy_hops is 1');
check(Net::resolveClientIp($spoofServer, true, 2) === '203.0.113.99', 'takes 2nd from right when trusted_proxy_hops is 2');
check(Net::resolveClientIp($spoofServer, true, 3) === '198.51.100.10', 'falls back to REMOTE_ADDR when hops exceed chain length');
check(
    Net::resolveClientIp(['REMOTE_ADDR' => '::ffff:198.51.100.10', 'HTTP_X_FORWARDED_FOR' => 'not-an-ip'], true, 1) === '198.51.100.10',
    'falls back to normalized REMOTE_ADDR on invalid X-Forwarded-For IP'
);

echo "net_ssrf_guards\n";
expectAppError(
    static fn () => Net::safeGetJson('https://loc%61lhost/.well-known/webring.json'),
    'invalid_url',
    'rejects percent-encoded hostname'
);
expectAppError(
    static fn () => Net::safeGetJson('https://example.com:8443/.well-known/webring.json'),
    'unsafe_port',
    'rejects non-443 port'
);
expectAppError(
    static fn () => Net::safeGetJson('http://example.com/.well-known/webring.json'),
    'insecure_url',
    'rejects http:// when allowInsecure is false'
);
expectAppError(
    static fn () => Net::safeGetJson('https://user:pass@example.com/.well-known/webring.json'),
    'unsafe_url',
    'rejects URL with credentials'
);
expectAppError(
    static fn () => Net::safeGetJson('https://127.0.0.1/.well-known/webring.json'),
    'unsafe_host',
    'rejects loopback IPv4'
);
expectAppError(
    static fn () => Net::safeGetJson('https://[::1]/.well-known/webring.json'),
    'unsafe_host',
    'rejects loopback IPv6'
);

echo "rate_limit\n";
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
Database::migrate($pdo);
check(Database::checkRateLimit($pdo, 'write:198.51.100.10', 3600, 2) === true, 'per-IP write 1/2 allowed');
check(Database::checkRateLimit($pdo, 'global:crawl', 60, 2) === true, 'global crawl 1/2 allowed');
check(Database::checkRateLimit($pdo, 'global:crawl', 60, 2) === true, 'global crawl 2/2 allowed');
check(Database::checkRateLimit($pdo, 'global:crawl', 60, 2) === false, 'global crawl 3/2 blocked');
check(Database::checkRateLimit($pdo, 'write:198.51.100.10', 3600, 2) === true, 'per-IP write 2/2 still allowed');
check(Database::checkRateLimit($pdo, 'write:198.51.100.10', 3600, 2) === false, 'per-IP write 3/2 blocked');

if ($failures > 0) {
    echo "{$failures} check(s) failed\n";
    exit(1);
}

echo "all phase 1 checks passed\n";
