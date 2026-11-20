<?php
declare(strict_types=1);

/**
 * Smoke tests for phase 5 security hardening in webring-hub-php (no network, temporary SQLite):
 * - Candidate key survives the key_changed rollback; first key kept, conflicts flagged (item 24)
 * - Trust-key only accepts the reviewed candidate that the site still serves (item 24)
 * - Rotation chain compares keys by SPKI fingerprint (item 23)
 * - Registrable domains from the Public Suffix List; domain cap query (item 10)
 * - Shared verification deadline; failed public refreshes start the cooldown (item 10)
 * - Canonical site paths only; versioned migrations record failures (item 22)
 * - IP hash salt from config; CSP without http: images (item 27)
 *
 * Run with: php scripts/smoke-security-phase5.php
 */

error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    throw new ErrorException($str, 0, $no, $file, $line);
});

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
use WebringHub\Net;
use WebringHub\PublicSuffix;
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

/** Runs $fn and returns the AppError code it threw, or '' if it didn't throw one. */
function errorCode(callable $fn): string
{
    try {
        $fn();
    } catch (AppError $err) {
        return $err->errorCode;
    }
    return '';
}

function freshPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    Database::migrate($pdo);
    return $pdo;
}

/** @return array<string, mixed> a verified-site array as Site::verifySite returns it */
function fakeSite(string $siteUrl, string $publicKeyPem, array $keyRotations = []): array
{
    return [
        'siteUrl' => $siteUrl,
        'did' => Verify::siteUrlToDidWeb($siteUrl),
        'manifest' => [],
        'profile' => ['displayName' => 'Alice', 'updatedAt' => '2026-11-17T00:00:00.000Z'],
        'publicKeyPem' => $publicKeyPem,
        'keyRotations' => $keyRotations,
        'fetchedAt' => '2026-11-17T00:00:00.000Z',
    ];
}

/** The same key, wrapped at 40 columns instead of 64 (what a different PEM encoder may emit). */
function rewrap(string $pem): string
{
    preg_match('/-----BEGIN PUBLIC KEY-----(.+?)-----END PUBLIC KEY-----/s', $pem, $m);
    $b64 = preg_replace('/\s+/', '', $m[1]);
    return "-----BEGIN PUBLIC KEY-----\r\n" . chunk_split($b64, 40, "\r\n") . "-----END PUBLIC KEY-----";
}

$ext = new Extensions(__DIR__ . '/../extensions');
$siteUrl = 'https://alice.example';
$did = Verify::siteUrlToDidWeb($siteUrl);
$keyA = Verify::generateKeyPair();
$keyB = Verify::generateKeyPair();
$keyC = Verify::generateKeyPair();
$fpB = Verify::spkiFingerprint($keyB['publicKeyPem']);

echo "candidate_key_survives_rollback\n";
$pdo = freshPdo();
Entries::saveVerified($pdo, $ext, fakeSite($siteUrl, $keyA['publicKeyPem']));
$code = errorCode(fn () => Entries::saveVerified($pdo, $ext, fakeSite($siteUrl, $keyB['publicKeyPem'])));
check($code === 'key_changed', 'a new key without a rotation certificate is refused');
$cand = Database::getCandidateKey($pdo, $did);
check($cand !== null && $cand['fingerprint'] === $fpB, 'the refused key is recorded as the candidate (phase 4 lost it to the rollback)');
check(Verify::spkiFingerprint((string) Database::getEntryByDid($pdo, $did)['public_key_pem']) === Verify::spkiFingerprint($keyA['publicKeyPem']), 'the listing keeps the pinned key');
errorCode(fn () => Entries::saveVerified($pdo, $ext, fakeSite($siteUrl, $keyC['publicKeyPem'])));
$cand = Database::getCandidateKey($pdo, $did);
check($cand !== null && $cand['fingerprint'] === $fpB, 'a second different key does not replace the first candidate');
check($cand !== null && $cand['conflict'] === true, 'a second different key sets the conflict flag');
$admin = Database::toAdminEntries($pdo, [Database::getEntryByDid($pdo, $did)])[0];
check($admin['keyChanged'] === true && $admin['candidateFingerprint'] === $fpB && $admin['candidateConflict'] === true, 'admin listing exposes keyChanged, candidate fingerprint and conflict');

echo "trust_reviewed_candidate_only\n";
$row = Database::getEntryByDid($pdo, $did);
$serve = fn (string $pem) => fn (): array => fakeSite($siteUrl, $pem);
check(errorCode(fn () => Entries::trustCandidateKey($pdo, $ext, $row, null, $serve($keyB['publicKeyPem']))) === 'invalid_request', 'trust without expectedFingerprint is refused');
check(errorCode(fn () => Entries::trustCandidateKey($pdo, $ext, $row, Verify::spkiFingerprint($keyC['publicKeyPem']), $serve($keyC['publicKeyPem']))) === 'candidate_mismatch', 'trust naming a key other than the candidate is refused');
check(errorCode(fn () => Entries::trustCandidateKey($pdo, $ext, $row, $fpB, $serve($keyC['publicKeyPem']))) === 'served_key_changed', 'trust is refused when the site no longer serves the reviewed key');
check(Verify::spkiFingerprint((string) Database::getEntryByDid($pdo, $did)['public_key_pem']) === Verify::spkiFingerprint($keyA['publicKeyPem']), 'refused trust attempts leave the pinned key unchanged');
$result = Entries::trustCandidateKey($pdo, $ext, $row, strtoupper($fpB), $serve(rewrap($keyB['publicKeyPem'])));
check($result['keyRotated'] === true && $result['keyAcceptedBy'] === 'operator', 'trusting the reviewed candidate pins it (fingerprint is case-insensitive, PEM wrapping ignored)');
check(Database::getCandidateKey($pdo, $did) === null, 'the candidate is cleared once trusted');
check(errorCode(fn () => Entries::trustCandidateKey($pdo, $ext, Database::getEntryByDid($pdo, $did), $fpB, $serve($keyB['publicKeyPem']))) === 'no_candidate', 'trust with no pending candidate is refused (no trust-on-first-use fallback)');

echo "candidate_cleared_when_site_reverts\n";
$pdo2 = freshPdo();
Entries::saveVerified($pdo2, $ext, fakeSite($siteUrl, $keyA['publicKeyPem']));
errorCode(fn () => Entries::saveVerified($pdo2, $ext, fakeSite($siteUrl, $keyB['publicKeyPem'])));
check(Database::getCandidateKey($pdo2, $did) !== null, 'candidate recorded');
Entries::saveVerified($pdo2, $ext, fakeSite($siteUrl, $keyA['publicKeyPem']));
check(Database::getCandidateKey($pdo2, $did) === null, 'serving the pinned key again clears the candidate');
errorCode(fn () => Entries::saveVerified($pdo2, $ext, fakeSite($siteUrl, $keyB['publicKeyPem'])));
$res = Entries::trustCandidateKey($pdo2, $ext, Database::getEntryByDid($pdo2, $did), $fpB, $serve($keyA['publicKeyPem']));
check($res['keyRotated'] === false && Database::getCandidateKey($pdo2, $did) === null, 'trusting while the site serves its pinned key again re-verifies and clears the candidate (no false conflict)');

echo "rotation_chain_by_fingerprint\n";
$cert = (object) [
    'type' => 'webring-key-rotation',
    'did' => $did,
    'siteUrl' => $siteUrl,
    'oldPublicKeyPem' => rewrap($keyA['publicKeyPem']),
    'newPublicKeyPem' => $keyB['publicKeyPem'],
    'createdAt' => '2026-11-17T00:00:00.000Z',
];
$cert->proof = (object) [
    'type' => 'Ed25519Signature2020',
    'signatureValue' => Verify::signPayload($cert, $keyA['privateKeyPem']),
];
check(Verify::verifyKeyRotationChain($keyA['publicKeyPem'], rewrap($keyB['publicKeyPem']), $did, $siteUrl, [$cert]) === true, 'a certificate whose PEMs are wrapped differently still links the chain');
check(Verify::verifyKeyRotationChain($keyA['publicKeyPem'], $keyC['publicKeyPem'], $did, $siteUrl, [$cert]) === false, 'the chain does not reach a key it never names');
check(Verify::verifyKeyRotationChain('not a key', $keyB['publicKeyPem'], $did, $siteUrl, [$cert]) === false, 'an unparseable pinned key never matches');

echo "registrable_domains\n";
check(PublicSuffix::isAvailable(), 'the bundled Public Suffix List loads');
check(Database::registrableDomain('https://alice.github.io') === 'alice.github.io', 'github.io sites are separate domains');
check(Database::registrableDomain('https://shop.x.co.uk/blog') === 'x.co.uk', 'co.uk is a public suffix');
check(Database::registrableDomain('https://a.b.example.com') === 'example.com', 'ordinary subdomains share their parent domain');
check(Database::registrableDomain('https://192.0.2.10') === '192.0.2.10', 'IP literals are kept as they are');
$pdo3 = freshPdo();
Entries::saveVerified($pdo3, $ext, fakeSite('https://alice.github.io', Verify::generateKeyPair()['publicKeyPem']));
Entries::saveVerified($pdo3, $ext, fakeSite('https://a.example.com', Verify::generateKeyPair()['publicKeyPem']));
check(Database::countEntriesByRegistrableDomain($pdo3, 'https://bob.github.io') === 0, 'one github.io site does not count against another');
check(Database::countEntriesByRegistrableDomain($pdo3, 'https://b.example.com') === 1, 'subdomains of one domain count together');
check((string) $pdo3->query("SELECT registrable_domain FROM entries WHERE did = 'did:web:alice.github.io'")->fetchColumn() === 'alice.github.io', 'registrable_domain is stored on save');

echo "shared_deadline_and_cooldown\n";
check(errorCode(fn () => Net::safeGetJson('https://example.com/api/manifest.json', ['deadline' => microtime(true) - 1.0])) === 'fetch_timeout', 'a fetch whose shared deadline has passed stops before connecting');
$pdo4 = freshPdo();
Entries::saveVerified($pdo4, $ext, fakeSite($siteUrl, $keyA['publicKeyPem']));
$pdo4->exec("UPDATE entries SET last_checked_at = '2000-01-01T00:00:00.000Z'");
$before = Database::getEntryByDid($pdo4, $did);
Database::recordCheckAttempt($pdo4, $before, 'The site took too long to respond.');
$after = Database::getEntryByDid($pdo4, $did);
check($after['last_checked_at'] !== '2000-01-01T00:00:00.000Z', 'a failed public refresh moves last_checked_at (starts the cooldown)');
check((int) $after['fail_count'] === (int) $before['fail_count'] && $after['status'] === 'active', 'a failed public refresh does not count toward marking the site stale');

echo "canonical_site_paths\n";
check(Verify::normalizeSiteUrl('https://a.example/social') === 'https://a.example/social', 'a plain subpath is accepted');
check(Verify::normalizeSiteUrl('https://a.example/social/') === 'https://a.example/social', 'one trailing slash is still trimmed');
check(Verify::normalizeSiteUrl('https://a.example//x') === '', 'empty path segments are rejected');
check(Verify::normalizeSiteUrl('https://a.example/a/../b') === '', 'dot-dot segments are rejected');
check(Verify::normalizeSiteUrl('https://a.example/./b') === '', 'dot segments are rejected');
check(Verify::normalizeSiteUrl('https://a.example/%78') === '', 'percent-encoded paths are rejected');
check(Verify::normalizeSiteUrl('https://a.example/x y') === '', 'paths with spaces are rejected');

echo "versioned_migrations\n";
$pdo5 = freshPdo();
$ids = $pdo5->query('SELECT id FROM schema_migrations ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
check(in_array('2026_11_registrable_domain', $ids, true) && in_array('2026_11_key_candidates_table', $ids, true), 'migrations are recorded once applied');
Database::migrate($pdo5);
check((int) $pdo5->query('SELECT COUNT(*) FROM schema_migrations')->fetchColumn() === count($ids), 'running migrate again applies nothing twice');
$ran = 0;
Database::runMigration($pdo5, 'test_failing', function () use (&$ran): void {
    $ran++;
    throw new RuntimeException('boom');
});
$status = Database::maintenanceStatus($pdo5);
check(count($status['migrationErrors']) === 1 && $status['migrationErrors'][0]['id'] === 'test_failing' && $status['migrationErrors'][0]['message'] === 'boom', 'a failed migration is recorded for the admin page, not swallowed');
Database::runMigration($pdo5, 'test_failing', fn () => null);
check(Database::maintenanceStatus($pdo5)['migrationErrors'] === [], 'a successful retry clears the recorded error');
check(Database::maintenanceStatus($pdo5)['pendingMigrations'] === [], 'no upgrades are pending after migrate');
$pdo5->exec("DELETE FROM schema_migrations WHERE id = '2026_11_registrable_domain'");
check(Database::maintenanceStatus($pdo5)['pendingMigrations'] === ['2026_11_registrable_domain'], 'an upgrade that has not run is reported as pending (auto_migrate off)');
Database::runMigrations($pdo5, null, true);
check(Database::maintenanceStatus($pdo5)['pendingMigrations'] === [], 'running upgrades clears the pending list');
$legacy = new PDO('sqlite::memory:');
$legacy->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$legacy->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$legacy->exec("CREATE TABLE settings (`key` TEXT PRIMARY KEY, `value` TEXT NOT NULL, updated_at TEXT NOT NULL)");
$legacy->prepare('INSERT INTO settings VALUES (?, ?, ?)')->execute(['candidate_key:' . $did, $keyB['publicKeyPem'], '2026-11-10T00:00:00Z']);
Database::migrate($legacy);
$moved = Database::getCandidateKey($legacy, $did);
check($moved !== null && $moved['fingerprint'] === $fpB, 'phase 4 candidate keys in settings move to key_candidates');
check((int) $legacy->query("SELECT COUNT(*) FROM settings WHERE `key` LIKE 'candidate_key:%'")->fetchColumn() === 0, 'the old settings rows are removed');

echo "ip_salt_and_csp\n";
$pdo6 = freshPdo();
Database::configureIpHashSalt('');
check(Database::ipSaltInDatabase() === true, 'without IP_HASH_SALT the database salt is used (and reported)');
$dbHash = Database::clientKeyHash($pdo6, '203.0.113.5');
Database::configureIpHashSalt(str_repeat('s', 40));
check(Database::ipSaltInDatabase() === false, 'a configured salt of 32+ characters is used');
check(Database::clientKeyHash($pdo6, '203.0.113.5') !== $dbHash, 'the configured salt changes the hash');
Database::configureIpHashSalt('short');
check(Database::ipSaltInDatabase() === true, 'a salt shorter than 32 characters is ignored');
Database::configureIpHashSalt('');
$index = (string) file_get_contents(__DIR__ . '/../public/index.php');
check(preg_match("/img-src 'self' https: data:;/", $index) === 1 && !str_contains($index, 'https: http: data:'), 'the hub CSP no longer allows http: images');
check(str_contains($index, "header_remove('X-Powered-By')") && str_contains($index, 'Strict-Transport-Security'), 'responses drop X-Powered-By and send HSTS over HTTPS');
check(!file_exists(__DIR__ . '/../config.example copy.php'), 'the stray pre-fix config copy is gone');

if ($failures > 0) {
    echo "{$failures} check(s) failed\n";
    exit(1);
}

echo "all phase 5 checks passed\n";
