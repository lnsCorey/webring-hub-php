#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Smoke test for the extension framework and the key-witness extension.
 * Runs against a throwaway SQLite database; needs PHP 8.1+ with pdo_sqlite and sodium or openssl.
 *
 *   php scripts/smoke-extensions.php
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

$failures = 0;
function check(bool $ok, string $label): void
{
    global $failures;
    fwrite($ok ? STDOUT : STDERR, ($ok ? '  ok   ' : '  FAIL ') . $label . "\n");
    if (!$ok) {
        $failures++;
    }
}

// Shared with webring studio/tests/federation.test.ts: Ed25519 key from a seed of 32 0x07 bytes.
const VECTOR_PEM = "-----BEGIN PUBLIC KEY-----\nMCowBQYDK2VwAyEA6kpsY+KcUgq+9VB7Ey7F+ZVHdq6+vnuSQh7qaRRG0iw=\n-----END PUBLIC KEY-----\n";
const VECTOR_FINGERPRINT = '324be2dea8bc44461b0233e51fa48902ed6b1cc671e7739af2551e0bfe68f54e';

echo "fingerprint\n";
check(Verify::spkiFingerprint(VECTOR_PEM) === VECTOR_FINGERPRINT, 'SPKI fingerprint matches the shared vector');
check(Verify::spkiFingerprint(str_replace("\n", "\r\n", VECTOR_PEM)) === VECTOR_FINGERPRINT, 'line endings do not change it');
check(Verify::spkiFingerprint('not a key') === '', 'bad input gives an empty string');

$dbPath = sys_get_temp_dir() . '/webring-ext-smoke-' . bin2hex(random_bytes(4)) . '.sqlite';
$config = Config::load([
    'db_driver' => 'sqlite',
    'sqlite_path' => $dbPath,
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $dbPath],
    'extensions' => ['key-witness', 'does-not-exist', 'Bad_Id'],
    // Forced on: the operator's config.php may turn it off for the live database
    'auto_migrate' => true,
]);
$pdo = Database::open($config);

/** @return array<string, mixed> */
function fakeSite(string $pem): array
{
    return [
        'siteUrl' => 'https://alice.example',
        'did' => 'did:web:alice.example',
        'handle' => 'alice',
        'displayName' => 'Alice',
        'tagline' => '',
        'bio' => '',
        'location' => '',
        'avatarUrl' => '',
        'bannerUrl' => '',
        'interests' => [],
        'links' => [],
        'publicKeyPem' => $pem,
        'profileUpdatedAt' => '2026-01-01T00:00:00.000Z',
        'keyRotations' => [],
    ];
}

echo "loader\n";
// An entry listed before the extension was turned on should be backfilled.
$first = Verify::generateKeyPair();
Database::upsertEntry($pdo, fakeSite($first['publicKeyPem']));
$ext = Extensions::load($config, $pdo);
$admin = $ext->adminList();
$byId = [];
foreach ($admin as $item) {
    $byId[$item['id']] = $item;
}
check(($byId['key-witness']['loaded'] ?? false) === true, 'key-witness loads');
check(($byId['key-witness']['schemaVersion'] ?? 0) === 2, 'key-witness migrated to schema 2 (MySQL collation migration is no-op on SQLite)');
check(($byId['key-witness']['kind'] ?? '') === 'admin' && ($byId['key-witness']['uiError'] ?? null) === null, 'key-witness is an admin-only extension with valid ui');
check(count($byId['key-witness']['panels'] ?? []) === 3, 'key-witness declares 3 admin panels');
check(($byId['does-not-exist']['loaded'] ?? true) === false && !empty($byId['does-not-exist']['error']), 'a missing extension is reported, not fatal');
check(($byId['Bad_Id']['loaded'] ?? true) === false, 'an invalid id is refused');
$pubList = $ext->publicList();
check(
    count($pubList) === 1
    && ($pubList[0]['id'] ?? '') === 'key-witness'
    && ($pubList[0]['apiVersion'] ?? 0) === 1
    && !isset($pubList[0]['ui']),
    '/api/info lists only loaded extensions (and no public card for key-witness)'
);
check($ext->match('GET', '/api/ext/key-witness/v2/keys/x') === null, 'unknown route version does not match');
check($ext->match('POST', '/api/ext/key-witness/v1/keys/x') === null, 'wrong method does not match');

$did = 'did:web:alice.example';
$fetch = static function () use ($ext, $did): array {
    $match = $ext->match('GET', '/api/ext/key-witness/v1/keys/' . rawurlencode($did));
    if ($match === null) {
        return [0, []];
    }
    return Extensions::run($match, new ExtensionRequest('GET', '', [], static fn (): array => []));
};

echo "key-witness\n";
[$status, $body] = $fetch();
$fp1 = Verify::spkiFingerprint($first['publicKeyPem']);
check($status === 200, 'route answers 200');
check(($body['current']['fingerprint'] ?? '') === $fp1, 'current fingerprint is the pinned key');
check(count($body['history'] ?? []) === 1 && $body['history'][0]['outcome'] === 'first-seen', 'backfilled as first-seen');

// Same key again: history stays at one item.
Entries::saveVerified($pdo, $ext, fakeSite($first['publicKeyPem']));
[, $body] = $fetch();
check(count($body['history']) === 1, 'a refresh with the same key adds nothing');

// A new key with no rotation certificate is refused by the core and recorded as rejected.
$second = Verify::generateKeyPair();
$rejected = false;
try {
    Entries::saveVerified($pdo, $ext, fakeSite($second['publicKeyPem']));
} catch (AppError $err) {
    $rejected = $err->errorCode === 'key_changed';
}
check($rejected, 'core refuses an unexplained key change');
[, $body] = $fetch();
$fp2 = Verify::spkiFingerprint($second['publicKeyPem']);
check(($body['current']['fingerprint'] ?? '') === $fp1, 'current key is unchanged after a refusal');
check(in_array(['fingerprint' => $fp2, 'outcome' => 'rejected'], array_map(
    static fn ($h) => ['fingerprint' => $h['fingerprint'], 'outcome' => $h['outcome']],
    $body['history']
), true), 'the refused key is in the history as rejected');

// The operator accepts the new key.
$result = Entries::saveVerified($pdo, $ext, fakeSite($second['publicKeyPem']), true);
check($result['keyAcceptedBy'] === 'operator', 'core reports the operator accepted it');
[, $body] = $fetch();
check(($body['current']['fingerprint'] ?? '') === $fp2, 'current key is the accepted one');
$outcomes = array_column($body['history'], 'outcome');
check(in_array('operator-accepted', $outcomes, true), 'history shows operator-accepted');

echo "key-witness admin routes\n";
$sumMatch = $ext->match('GET', '/api/ext/key-witness/v1/admin/summary');
$obsMatch = $ext->match('GET', '/api/ext/key-witness/v1/admin/observations');
check($sumMatch !== null && ($sumMatch['route']['admin'] ?? false) === true, 'v1/admin/summary requires admin auth');
check($obsMatch !== null && ($obsMatch['route']['admin'] ?? false) === true, 'v1/admin/observations requires admin auth');
[$sumStatus, $sumBody] = Extensions::run($sumMatch, new ExtensionRequest('GET', '', [], static fn (): array => []));
check($sumStatus === 200 && ($sumBody['sitesTracked'] ?? 0) === 1 && ($sumBody['keysSeen'] ?? 0) === 2 && ($sumBody['operatorAccepted'] ?? 0) === 1 && ($sumBody['rejectedLast30d'] ?? 0) === 1, 'summary counts match observed history');
[$obsStatus, $obsBody] = Extensions::run($obsMatch, new ExtensionRequest('GET', '', ['limit' => '10'], static fn (): array => []));
check($obsStatus === 200 && count($obsBody['observations'] ?? []) >= 3 && count($obsBody['changedSites'] ?? []) === 1, 'observations and changedSites list the rotated site');

// Blocked entries are not served.
Entries::setStatus($pdo, $ext, $did, 'blocked');
$notFound = false;
try {
    $fetch();
} catch (AppError $err) {
    $notFound = $err->status === 404;
}
check($notFound, 'blocked entries answer 404');
Entries::setStatus($pdo, $ext, $did, 'active');

// Removal deletes the whole history.
Entries::remove($pdo, $ext, $did, 'self');
$table = 'ext_key_witness_observations';
$left = (int) $pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
check($left === 0, 'removing the entry deletes its key history');
[, $sumAfter] = Extensions::run($sumMatch, new ExtensionRequest('GET', '', [], static fn (): array => []));
[, $obsAfter] = Extensions::run($obsMatch, new ExtensionRequest('GET', '', [], static fn (): array => []));
check(($sumAfter['sitesTracked'] ?? -1) === 0 && count($obsAfter['observations'] ?? []) === 0 && count($obsAfter['changedSites'] ?? []) === 0, 'removed site disappears from admin summary and observations');

// A listener that throws never reaches the caller.
$ctxProbe = new ExtensionContext('probe', ['version' => '0'], $pdo, $config);
$ctxProbe->on('entry.removed', static function (): void {
    throw new \RuntimeException('boom');
});
$threw = false;
try {
    $reflect = new \ReflectionProperty(Extensions::class, 'loaded');
    $loaded = $reflect->getValue($ext);
    $loaded['probe'] = $ctxProbe;
    $reflect->setValue($ext, $loaded);
    $ext->emit('entry.removed', ['did' => 'did:web:nobody.example']);
} catch (\Throwable) {
    $threw = true;
}
check(!$threw, 'a failing listener does not break the core');

$pdo = null;
@unlink($dbPath);
@unlink($dbPath . '-wal');
@unlink($dbPath . '-shm');

echo $failures === 0 ? "all checks passed\n" : "{$failures} check(s) failed\n";
exit($failures === 0 ? 0 : 1);
