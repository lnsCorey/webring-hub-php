<?php
declare(strict_types=1);

/**
 * Smoke tests for Phase 4 security hardening in webring-hub-php:
 * - Trailing dot stripping & malformed hostname rejection (Item #22)
 * - SPKI key comparison & silent-delete replacement with 409 site_conflict (Items #22, #23)
 * - Candidate key storage on key_changed (Item #24)
 * - Salted HMAC rate-limit keys (never storing raw IPs) & purge (Item #27)
 *
 * Run with: php scripts/smoke-security-phase4.php
 */

require_once __DIR__ . '/../src/AppError.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Verify.php';
require_once __DIR__ . '/../src/Database.php';

use WebringHub\Database;
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

echo "lookalike_hosts\n";
check(Verify::normalizeSiteUrl('https://example.com.') === 'https://example.com', 'strips trailing dot from hostname');
check(Verify::normalizeSiteUrl('https://example..com') === '', 'rejects double-dot hostname');
check(Verify::normalizeSiteUrl('https://bad_host.example') === '', 'rejects underscore in hostname');

echo "rate_limit_privacy\n";
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
Database::migrate($pdo);
check(Database::checkRateLimit($pdo, 'write:203.0.113.5', 3600, 5) === true, 'rate limit check succeeds');
$rawKeys = $pdo->query("SELECT ip_key FROM rate_limits")->fetchAll(PDO::FETCH_COLUMN);
check(count($rawKeys) === 1 && !str_contains((string) $rawKeys[0], '203.0.113.5'), 'raw client IP is not stored in rate_limits');

echo "candidate_key\n";
// Phase 5 moved candidates to their own table; the end-to-end flow is in smoke-security-phase5.php.
$candidatePem = Verify::generateKeyPair()['publicKeyPem'];
Database::recordCandidateKey($pdo, 'did:web:alice.example', $candidatePem);
$stored = Database::getCandidateKey($pdo, 'did:web:alice.example');
check($stored !== null && $stored['fingerprint'] === Verify::spkiFingerprint($candidatePem), 'stores and retrieves candidate key');
Database::clearCandidateKey($pdo, 'did:web:alice.example');
check(Database::getCandidateKey($pdo, 'did:web:alice.example') === null, 'clears candidate key');

if ($failures > 0) {
    echo "{$failures} check(s) failed\n";
    exit(1);
}

echo "all phase 4 checks passed\n";
