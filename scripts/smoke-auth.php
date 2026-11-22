#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Smoke test for cryptographic DID auth, sessions, CSRF validation, and per-extension
 * opt-in / opt-out data erasure (`user.extension_opt_out`).
 *
 *   php scripts/smoke-auth.php
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
require_once __DIR__ . '/../src/Auth.php';
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

function expectCode(callable $fn, string $expectedCode, string $label): void
{
    try {
        $fn();
        check(false, "{$label} (expected {$expectedCode}, did not throw)");
    } catch (AppError $e) {
        check($e->errorCode === $expectedCode, "{$label} ({$e->errorCode})");
    }
}

$pdo = new \PDO('sqlite::memory:');
$pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(\PDO::ATTR_DEFAULT_FETCH_MODE, \PDO::FETCH_ASSOC);
Database::migrate($pdo);

$ext = Extensions::load(['extensions' => ['arcade']], $pdo);
$hubUrl = 'https://hub.example';

$aliceKeys = Verify::generateKeyPair();
$aliceDid = 'did:web:alice.example';
$aliceSiteUrl = 'https://alice.example';
Entries::saveVerified($pdo, $ext, [
    'siteUrl' => $aliceSiteUrl,
    'did' => $aliceDid,
    'handle' => 'alice',
    'displayName' => 'Alice',
    'tagline' => '',
    'bio' => '',
    'location' => '',
    'avatarUrl' => '',
    'bannerUrl' => '',
    'interests' => [],
    'links' => [],
    'publicKeyPem' => $aliceKeys['publicKeyPem'],
    'profileUpdatedAt' => Database::isoNow(),
    'keyRotations' => [],
]);

echo "challenge -> verify -> claim\n";
$ch = Auth::createChallenge($pdo, $hubUrl, 'arcade');
check(!empty($ch['challengeId']) && !empty($ch['challengeSecret']) && !empty($ch['nonce']), 'creates auth challenge');

$pending = Auth::claimChallenge($pdo, $ch['challengeId'], $ch['challengeSecret'], $ext);
check(($pending['status'] ?? '') === 'pending', 'unapproved challenge returns pending');

$createdAt = Database::isoNow();
$payload = [
    'action' => 'hub_auth',
    'hubUrl' => $hubUrl,
    'did' => $aliceDid,
    'siteUrl' => $aliceSiteUrl,
    'nonce' => $ch['nonce'],
    'challengeId' => $ch['challengeId'],
    'optInExtensions' => ['arcade'],
    'createdAt' => $createdAt,
];
$sig = Verify::signPayload($payload, $aliceKeys['privateKeyPem']);

// Wrong hub URL is refused
expectCode(
    static fn () => Auth::verifySignedAuth($pdo, array_merge($payload, ['hubUrl' => 'https://other.example', 'signature' => $sig]), $hubUrl, $ext),
    'hub_mismatch',
    'refuses hub URL mismatch'
);

// Wrong key signature is refused
$eveKeys = Verify::generateKeyPair();
$badSig = Verify::signPayload($payload, $eveKeys['privateKeyPem']);
expectCode(
    static fn () => Auth::verifySignedAuth($pdo, array_merge($payload, ['signature' => $badSig]), $hubUrl, $ext),
    'invalid_signature',
    'refuses signature from wrong key'
);

$verified = Auth::verifySignedAuth($pdo, array_merge($payload, ['signature' => $sig]), $hubUrl, $ext);
check(($verified['ok'] ?? false) === true && ($verified['did'] ?? '') === $aliceDid, 'accepts valid Ed25519 signed auth');

$claimed = Auth::claimChallenge($pdo, $ch['challengeId'], $ch['challengeSecret'], $ext);
check(($claimed['status'] ?? '') === 'authenticated' && !empty($claimed['token']) && !empty($claimed['csrfToken']), 'claims approved challenge into session token');

// Reclaiming same challenge fails
expectCode(
    static fn () => Auth::claimChallenge($pdo, $ch['challengeId'], $ch['challengeSecret'], $ext),
    'challenge_used',
    'rejects replayed challenge claim'
);

echo "sessions, csrf & consent\n";
$session = Auth::getSession($pdo, (string) $claimed['token']);
check($session !== null && $session['did'] === $aliceDid, 'getSession resolves active session');
check(Auth::isOptedIn($pdo, $aliceDid, 'arcade') === true, 'initial optInExtensions applied on claim');

expectCode(
    static fn () => Auth::requireCsrf($session, ['HTTP_X_HUB_CSRF' => 'wrong']),
    'csrf_invalid',
    'rejects invalid CSRF token'
);
expectCode(
    static fn () => Auth::requireCsrf($session, ['HTTP_X_HUB_CSRF' => $session['csrfToken'], 'HTTP_SEC_FETCH_SITE' => 'cross-site']),
    'csrf_rejected',
    'rejects cross-site Sec-Fetch-Site'
);
Auth::requireCsrf($session, ['HTTP_X_HUB_CSRF' => $session['csrfToken'], 'HTTP_SEC_FETCH_SITE' => 'same-origin']);
check(true, 'accepts matching CSRF token on same-origin request');

echo "opt-out event & stateless signed opt-out\n";
$optOutEvents = [];
$probeCtx = new ExtensionContext('probe-opt', ['version' => '1.0.0'], $pdo, []);
$probeCtx->on('user.extension_opt_out', static function (array $e) use (&$optOutEvents): void {
    $optOutEvents[] = $e;
});
$reflect = new \ReflectionProperty(Extensions::class, 'loaded');
$loaded = $reflect->getValue($ext);
$loaded['probe-opt'] = $probeCtx;
$reflect->setValue($ext, $loaded);

Auth::setConsent($pdo, $aliceDid, 'arcade', false, $ext);
check(Auth::isOptedIn($pdo, $aliceDid, 'arcade') === false, 'opting out flips consent to false');
check(count($optOutEvents) === 1 && ($optOutEvents[0]['did'] ?? '') === $aliceDid && ($optOutEvents[0]['extensionId'] ?? '') === 'arcade', 'emits user.extension_opt_out event');

// Re-opt-in, then test stateless signed opt-out (Studio flow)
Auth::setConsent($pdo, $aliceDid, 'arcade', true, $ext);
$optNonce = Database::createNonce($pdo);
$optPayload = [
    'action' => 'ext_opt_out',
    'hubUrl' => $hubUrl,
    'did' => $aliceDid,
    'siteUrl' => $aliceSiteUrl,
    'extensionId' => 'arcade',
    'nonce' => $optNonce,
    'createdAt' => Database::isoNow(),
];
$optSig = Verify::signPayload($optPayload, $aliceKeys['privateKeyPem']);
Auth::verifySignedOptOut($pdo, array_merge($optPayload, ['signature' => $optSig]), $hubUrl, 'arcade', $ext);
check(Auth::isOptedIn($pdo, $aliceDid, 'arcade') === false && count($optOutEvents) === 2, 'stateless signed opt-out revokes consent and emits opt-out event');

echo "direct launch from studio\n";
$launchNonce = Database::createNonce($pdo);
$launchPayload = [
    'action' => 'hub_auth',
    'hubUrl' => $hubUrl,
    'did' => $aliceDid,
    'siteUrl' => $aliceSiteUrl,
    'nonce' => $launchNonce,
    'challengeId' => '',
    'optInExtensions' => [],
    'createdAt' => Database::isoNow(),
];
$launchSig = Verify::signPayload($launchPayload, $aliceKeys['privateKeyPem']);
$launchRes = Auth::verifySignedAuth($pdo, array_merge($launchPayload, ['signature' => $launchSig]), $hubUrl, $ext);
check(($launchRes['mode'] ?? '') === 'launch' && !empty($launchRes['code']) && !empty($launchRes['secret']), 'issues direct launch code and secret');
$launchClaim = Auth::claimChallenge($pdo, (string) $launchRes['code'], (string) $launchRes['secret'], $ext);
check(($launchClaim['status'] ?? '') === 'authenticated', 'direct launch code claims into session');

// Blocked entry cannot keep session or sign in
Entries::setStatus($pdo, $ext, $aliceDid, 'blocked');
check(Auth::getSession($pdo, (string) $launchClaim['token']) === null, 'blocked entry invalidates active session');

echo $failures === 0 ? "all auth checks passed\n" : "{$failures} check(s) failed\n";
exit($failures === 0 ? 0 : 1);
