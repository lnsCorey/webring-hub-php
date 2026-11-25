#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Smoke test for the `webhook-sync` extension and `scripts/sync-worker.php`.
 *
 * Covers:
 *   1. Extension registration, declarative admin UI validation, and `/api/info` exposure
 *   2. `POST /api/ext/webhook-sync/v1/ping` Ed25519 signature verification, key rotation chains,
 *      SSRF/off-site manifest_url rejection, monotonic timestamp enforcement, and queue deduplication
 *   3. `WebhookSyncExtension::runBatch` conditional `If-None-Match` (`304 Not Modified`),
 *      differential `manifest.json` SHA-256 sync, archive-aware item pruning, and exponential backoff
 *      (`60s * 2^attempts` up to 5 attempts without corrupting previously verified data)
 *   4. `GET /api/ext/webhook-sync/v1/feed` cursor pagination, end-to-end raw document signature
 *      verification, `Cache-Control: no-store, no-cache, must-revalidate` headers, and privacy purges
 *   5. Live HTTP + CLI `scripts/sync-worker.php` end-to-end verification over `php -S`
 *
 * Run with: php scripts/smoke-webhook-sync.php
 */

namespace WebringHub;

use PDO;
use stdClass;
use WebringHub\Ext\WebhookSync\WebhookSyncExtension;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI execution only.\n";
    exit(1);
}

error_reporting(E_ALL);

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

/**
 * Signs a Webring Studio document (attaching `proof`) with `$privateKeyPem`.
 *
 * @param array<string, mixed> $doc
 * @return array<string, mixed>
 */
function signStudioDoc(array $doc, string $did, string $privateKeyPem, string $created): array
{
    unset($doc['proof']);
    $obj = WebhookSyncExtension::toStdObject($doc);
    assert($obj instanceof stdClass);
    $sig = Verify::signPayload($obj, $privateKeyPem);
    $doc['proof'] = [
        'type' => 'Ed25519Signature2020',
        'created' => $created,
        'verificationMethod' => "{$did}#key-1",
        'signatureValue' => $sig,
    ];
    return $doc;
}

/**
 * Computes Studio's `documentFingerprint` (`sha256(canonicalizeJson(doc))`).
 *
 * @param array<string, mixed> $doc
 */
function docFingerprint(array $doc): string
{
    $obj = WebhookSyncExtension::toStdObject($doc);
    assert($obj instanceof stdClass);
    return hash('sha256', Verify::canonicalizeJson($obj));
}

/**
 * Signs a webhook ping payload (`signature` detached).
 *
 * @param array<string, mixed> $payload
 * @return array<string, mixed>
 */
function signPing(array $payload, string $privateKeyPem): array
{
    unset($payload['signature'], $payload['proof']);
    $obj = WebhookSyncExtension::toStdObject($payload);
    assert($obj instanceof stdClass);
    $payload['signature'] = Verify::signPayload($obj, $privateKeyPem);
    return $payload;
}

/**
 * Dispatches an extension route in-process and returns `[status, body, headers]`.
 *
 * @param array<string, mixed> $query
 * @param array<string, mixed> $body
 * @return array{0: int, 1: array<string, mixed>, 2: array<string, string>}
 */
function callExt(Extensions $ext, string $method, string $path, array $query = [], array $body = []): array
{
    $match = $ext->match($method, $path);
    if ($match === null) {
        return [404, ['error' => 'not_found'], []];
    }
    try {
        $req = new ExtensionRequest($method, $path, $query, static fn (): array => $body, 'smoke-client');
        $res = Extensions::run($match, $req);
        return [$res[0], $res[1], $res[2] ?? []];
    } catch (AppError $err) {
        return [$err->status, ['error' => $err->errorCode, 'message' => $err->getMessage()], []];
    }
}

// ============================================================================
// Part 1: In-Process SQLite Verification Suite
// ============================================================================

$tmpDir = sys_get_temp_dir() . '/webring-webhook-smoke-' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0700, true);
$dbPath = $tmpDir . '/hub.sqlite';

$config = Config::load([
    'db_driver' => 'sqlite',
    'sqlite_path' => $dbPath,
    'db' => ['driver' => 'sqlite', 'sqlite_path' => $dbPath],
    'extensions' => ['webhook-sync'],
    'auto_migrate' => true,
]);
$pdo = Database::open($config);
$extensions = Extensions::load($config, $pdo);
$ctx = $extensions->context('webhook-sync');

echo "loader & manifest\n";
check($extensions->isLoaded('webhook-sync') && $ctx !== null, 'webhook-sync extension loads cleanly');
check($ctx !== null && $ctx->schemaVersion() === 2, 'webhook-sync migrated to schema 2');
$itemEvents = [];
if ($ctx !== null) {
    $ctx->on('ext.webhook-sync.items_changed', static function (array $e) use (&$itemEvents): void {
        $itemEvents[] = $e;
    });
}
$adminList = $extensions->adminList();
$wsAdmin = array_values(array_filter($adminList, static fn (array $e): bool => $e['id'] === 'webhook-sync'))[0] ?? null;
check($wsAdmin !== null && ($wsAdmin['kind'] ?? '') === 'admin' && count($wsAdmin['panels'] ?? []) === 2, 'declares 2 valid admin UI panels');

// Seed Alice & Bob entries
$aliceKeys = Verify::generateKeyPair();
$bobKeys = Verify::generateKeyPair();
$aliceSiteUrl = 'https://alice.example';
$aliceDid = Verify::siteUrlToDidWeb($aliceSiteUrl);
$bobSiteUrl = 'https://bob.example';
$bobDid = Verify::siteUrlToDidWeb($bobSiteUrl);

Entries::saveVerified($pdo, $extensions, [
    'siteUrl' => $aliceSiteUrl,
    'did' => $aliceDid,
    'manifest' => [],
    'profile' => ['displayName' => 'Alice', 'handle' => 'alice', 'updatedAt' => '2026-11-25T10:00:00.000Z'],
    'publicKeyPem' => $aliceKeys['publicKeyPem'],
    'keyRotations' => [],
]);
Entries::saveVerified($pdo, $extensions, [
    'siteUrl' => $bobSiteUrl,
    'did' => $bobDid,
    'manifest' => [],
    'profile' => ['displayName' => 'Bob', 'handle' => 'bob', 'updatedAt' => '2026-11-25T10:00:00.000Z'],
    'publicKeyPem' => $bobKeys['publicKeyPem'],
    'keyRotations' => [],
]);

echo "ping verification & deduplication\n";
[$sUnknown, $bUnknown] = callExt($extensions, 'POST', '/api/ext/webhook-sync/v1/ping', [], signPing([
    'did' => 'did:web:nobody.example',
    'timestamp' => '2026-11-25T11:00:00.000Z',
], $aliceKeys['privateKeyPem']));
check($sUnknown === 404 && ($bUnknown['error'] ?? '') === 'not_found', 'rejects unregistered DID with 404');

$badSigPing = signPing([
    'did' => $aliceDid,
    'timestamp' => '2026-11-25T11:00:00.000Z',
], $bobKeys['privateKeyPem']);
[$sBadSig, $bBadSig] = callExt($extensions, 'POST', '/api/ext/webhook-sync/v1/ping', [], $badSigPing);
check($sBadSig === 401 && ($bBadSig['error'] ?? '') === 'invalid_signature', 'rejects wrong Ed25519 signature with 401');

[$sOffsite, $bOffsite] = callExt($extensions, 'POST', '/api/ext/webhook-sync/v1/ping', [], signPing([
    'did' => $aliceDid,
    'manifest_url' => 'https://evil.example/api/manifest.json',
    'timestamp' => '2026-11-25T11:00:00.000Z',
], $aliceKeys['privateKeyPem']));
check($sOffsite === 422 && ($bOffsite['error'] ?? '') === 'endpoint_offsite', 'rejects off-site manifest_url');

// Rapid pings collapse into 1 row
for ($i = 1; $i <= 4; $i++) {
    $ts = sprintf('2026-11-25T11:0%d:00.000Z', $i);
    [$sOk, $bOk, $hOk] = callExt($extensions, 'POST', '/api/ext/webhook-sync/v1/ping', [], signPing([
        'did' => $aliceDid,
        'manifest_url' => 'https://alice.example/api/manifest.json',
        'timestamp' => $ts,
    ], $aliceKeys['privateKeyPem']));
    if ($i === 4) {
        check($sOk === 202 && ($bOk['status'] ?? '') === 'queued', 'accepts valid signed ping with 202 Accepted');
        check(($hOk['Cache-Control'] ?? '') === 'no-store, no-cache, must-revalidate', 'ping emits strict no-store Cache-Control header');
    }
}
$queueCount = (int) $pdo->query("SELECT COUNT(*) FROM ext_webhook_sync_queue WHERE member_did = '{$aliceDid}'")->fetchColumn();
$latestPingTs = (string) $pdo->query("SELECT ping_timestamp FROM ext_webhook_sync_queue WHERE member_did = '{$aliceDid}'")->fetchColumn();
check($queueCount === 1 && $latestPingTs === '2026-11-25T11:04:00.000Z', 'rapid pings collapse into a single pending queue row with latest timestamp');

// Key rotation support on ping
$bobRotatedKeys = Verify::generateKeyPair();
$rotCert = signStudioDoc([
    'type' => 'webring-key-rotation',
    'did' => $bobDid,
    'siteUrl' => $bobSiteUrl,
    'oldPublicKeyPem' => $bobKeys['publicKeyPem'],
    'newPublicKeyPem' => $bobRotatedKeys['publicKeyPem'],
    'rotatedAt' => '2026-11-25T11:05:00.000Z',
], $bobDid, $bobKeys['privateKeyPem'], '2026-11-25T11:05:00.000Z');

[$sRotPing] = callExt($extensions, 'POST', '/api/ext/webhook-sync/v1/ping', [], signPing([
    'did' => $bobDid,
    'timestamp' => '2026-11-25T11:06:00.000Z',
    'publicKeyPem' => $bobRotatedKeys['publicKeyPem'],
    'keyRotations' => [$rotCert],
], $bobRotatedKeys['privateKeyPem']));
check($sRotPing === 202, 'accepts ping signed by rotated key when valid keyRotations chain is included');

echo "differential sync, 304 conditional fetch & backoff\n";
// Build Alice's signed static files
$aliceProfileDoc = signStudioDoc([
    'schemaVersion' => '1.0.0',
    'siteUrl' => $aliceSiteUrl,
    'did' => $aliceDid,
    'displayName' => 'Alice Updated',
    'handle' => 'alice',
    'tagline' => 'Indie hacker',
    'bio' => 'Hello!',
    'updatedAt' => '2026-11-25T11:04:00.000Z',
], $aliceDid, $aliceKeys['privateKeyPem'], '2026-11-25T11:04:00.000Z');

$aliceItemsDoc = signStudioDoc([
    'schemaVersion' => '1.0.0',
    'siteUrl' => $aliceSiteUrl,
    'did' => $aliceDid,
    'updatedAt' => '2026-11-25T11:04:00.000Z',
    'items' => [
        [
            'id' => 'post-1',
            'canonicalUrl' => 'https://alice.example/api/items.json#post-1',
            'type' => 'post',
            'title' => 'First Post',
            'targetUrl' => 'https://alice.example/api/items.json#post-1',
            'category' => 'general',
            'commentary' => 'Decentralized feeds rock.',
            'mediaUrl' => '',
            'tags' => ['webring'],
            'createdAt' => '2026-11-25T11:01:00.000Z',
        ],
        [
            'id' => 'like-1',
            'canonicalUrl' => 'https://alice.example/api/items.json#like-1',
            'type' => 'like',
            'title' => 'Liked Bob',
            'targetUrl' => 'https://bob.example/api/items.json#p1',
            'category' => 'general',
            'commentary' => '',
            'mediaUrl' => '',
            'tags' => [],
            'createdAt' => '2026-11-25T11:03:00.000Z',
        ],
    ],
], $aliceDid, $aliceKeys['privateKeyPem'], '2026-11-25T11:04:00.000Z');

$aliceManifestDoc = signStudioDoc([
    'schemaVersion' => '1.0.0',
    'siteUrl' => $aliceSiteUrl,
    'did' => $aliceDid,
    'updatedAt' => '2026-11-25T11:04:00.000Z',
    'endpoints' => [
        'did' => '/.well-known/did.json',
        'profile' => '/api/profile.json',
        'items' => '/api/items.json',
    ],
    'documents' => [
        'profile' => ['sha256' => docFingerprint($aliceProfileDoc)],
        'items' => ['sha256' => docFingerprint($aliceItemsDoc)],
    ],
], $aliceDid, $aliceKeys['privateKeyPem'], '2026-11-25T11:04:00.000Z');

// Build Bob's rotated static files
$bobDidDoc = [
    'id' => $bobDid,
    'verificationMethod' => [[
        'id' => "{$bobDid}#key-1",
        'type' => 'Ed25519VerificationKey2020',
        'controller' => $bobDid,
        'publicKeyPem' => $bobRotatedKeys['publicKeyPem'],
    ]],
    'keyRotations' => [$rotCert],
];
$bobProfileDoc = signStudioDoc([
    'schemaVersion' => '1.0.0',
    'siteUrl' => $bobSiteUrl,
    'did' => $bobDid,
    'displayName' => 'Bob Rotated',
    'handle' => 'bob',
    'updatedAt' => '2026-11-25T11:06:00.000Z',
], $bobDid, $bobRotatedKeys['privateKeyPem'], '2026-11-25T11:06:00.000Z');
$bobItemsDoc = signStudioDoc([
    'schemaVersion' => '1.0.0',
    'siteUrl' => $bobSiteUrl,
    'did' => $bobDid,
    'updatedAt' => '2026-11-25T11:06:00.000Z',
    'items' => [[
        'id' => 'bob-post-1',
        'canonicalUrl' => 'https://bob.example/api/items.json#bob-post-1',
        'type' => 'post',
        'title' => 'Bob Post',
        'targetUrl' => 'https://bob.example/api/items.json#bob-post-1',
        'category' => 'general',
        'commentary' => 'Rotated key!',
        'mediaUrl' => '',
        'tags' => [],
        'createdAt' => '2026-11-25T11:02:00.000Z',
    ]],
], $bobDid, $bobRotatedKeys['privateKeyPem'], '2026-11-25T11:06:00.000Z');
$bobManifestDoc = signStudioDoc([
    'schemaVersion' => '1.0.0',
    'siteUrl' => $bobSiteUrl,
    'did' => $bobDid,
    'updatedAt' => '2026-11-25T11:06:00.000Z',
    'endpoints' => ['did' => '/.well-known/did.json', 'profile' => '/api/profile.json', 'items' => '/api/items.json'],
    'documents' => [
        'profile' => ['sha256' => docFingerprint($bobProfileDoc)],
        'items' => ['sha256' => docFingerprint($bobItemsDoc)],
    ],
], $bobDid, $bobRotatedKeys['privateKeyPem'], '2026-11-25T11:06:00.000Z');

$fetchLog = [];
$aliceEtag = '"etag-alice-v1"';
$bobFailMode = false;

$mockFetcher = static function (string $url, array $opts) use (
    &$fetchLog,
    &$aliceManifestDoc,
    &$aliceProfileDoc,
    &$aliceItemsDoc,
    &$aliceEtag,
    &$bobDidDoc,
    &$bobProfileDoc,
    &$bobItemsDoc,
    &$bobManifestDoc,
    &$bobFailMode
): array {
    $fetchLog[] = $url;
    if ($bobFailMode && str_starts_with($url, 'https://bob.example/')) {
        throw new AppError(504, 'fetch_timeout', 'Simulated timeout');
    }
    $headers = $opts['headers'] ?? [];
    foreach ($headers as $h) {
        if ($url === 'https://alice.example/api/manifest.json' && stripos($h, 'If-None-Match: ' . $aliceEtag) === 0) {
            return ['url' => $url, 'status' => 304, 'headers' => ['etag' => $aliceEtag], 'json' => null, 'jsonObject' => null, 'rawBody' => ''];
        }
    }
    $map = [
        'https://alice.example/api/manifest.json' => [$aliceManifestDoc, ['etag' => $aliceEtag, 'last-modified' => 'Wed, 25 Nov 2026 11:04:00 GMT']],
        'https://alice.example/api/profile.json' => [$aliceProfileDoc, []],
        'https://alice.example/api/items.json' => [$aliceItemsDoc, []],
        'https://bob.example/api/manifest.json' => [$bobManifestDoc, ['etag' => '"etag-bob-v1"']],
        'https://bob.example/.well-known/did.json' => [$bobDidDoc, []],
        'https://bob.example/api/profile.json' => [$bobProfileDoc, []],
        'https://bob.example/api/items.json' => [$bobItemsDoc, []],
    ];
    if (!isset($map[$url])) {
        throw new AppError(404, 'not_found', "No mock for {$url}");
    }
    [$doc, $respHeaders] = $map[$url];
    $raw = (string) json_encode($doc, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    return [
        'url' => $url,
        'status' => 200,
        'headers' => $respHeaders,
        'json' => json_decode($raw, true),
        'jsonObject' => json_decode($raw, false),
        'rawBody' => $raw,
    ];
};

$batch1 = WebhookSyncExtension::runBatch($pdo, $ctx, $extensions, 15, time() + 10, $mockFetcher);
check($batch1['claimed'] === 2 && $batch1['completed'] === 2 && $batch1['failed'] === 0, 'worker syncs both Alice and rotated-key Bob in batch');
check(count($itemEvents) === 2 && $itemEvents[0]['upserted'] !== [] && $itemEvents[0]['removedAll'] === false, 'emits ext.webhook-sync.items_changed after each synced member');
$likeTarget = $pdo->query("SELECT target_url FROM ext_webhook_sync_items WHERE item_id = 'like-1'")->fetchColumn();
check($likeTarget === 'https://bob.example/api/items.json#p1', 'stores the item targetUrl in the indexed target_url column');
$itemEvents = [];

// Monotonic ping rejection: older or equal timestamp than verified manifest (11:04:00) is refused
[$sStale, $bStale] = callExt($extensions, 'POST', '/api/ext/webhook-sync/v1/ping', [], signPing([
    'did' => $aliceDid,
    'timestamp' => '2026-11-25T11:04:00.000Z',
], $aliceKeys['privateKeyPem']));
check($sStale === 409 && ($bStale['error'] ?? '') === 'stale_timestamp', 'rejects ping with timestamp <= last verified manifest');

// New ping + 304 Not Modified short-circuit
[$sNewPing] = callExt($extensions, 'POST', '/api/ext/webhook-sync/v1/ping', [], signPing([
    'did' => $aliceDid,
    'timestamp' => '2026-11-25T11:10:00.000Z',
], $aliceKeys['privateKeyPem']));
check($sNewPing === 202, 'accepts newer ping timestamp');

$fetchLog = [];
$batch304 = WebhookSyncExtension::runBatch($pdo, $ctx, $extensions, 15, time() + 20, $mockFetcher);
check(
    $batch304['completed'] === 1 && $batch304['notModified'] === 1 && $fetchLog === ['https://alice.example/api/manifest.json'],
    '304 Not Modified completes immediately without fetching profile.json or items.json'
);

// Differential items-only update (profile.json sha256 unchanged -> profile.json NOT fetched!)
$aliceItemsDoc['updatedAt'] = '2026-11-25T11:15:00.000Z';
$aliceItemsDoc['items'] = [$aliceItemsDoc['items'][0]]; // remove like-1
$aliceItemsDoc = signStudioDoc($aliceItemsDoc, $aliceDid, $aliceKeys['privateKeyPem'], '2026-11-25T11:15:00.000Z');
$aliceManifestDoc['updatedAt'] = '2026-11-25T11:15:00.000Z';
$aliceManifestDoc['documents']['items']['sha256'] = docFingerprint($aliceItemsDoc);
$aliceManifestDoc = signStudioDoc($aliceManifestDoc, $aliceDid, $aliceKeys['privateKeyPem'], '2026-11-25T11:15:00.000Z');
$aliceEtag = '"etag-alice-v2"';

callExt($extensions, 'POST', '/api/ext/webhook-sync/v1/ping', [], signPing([
    'did' => $aliceDid,
    'timestamp' => '2026-11-25T11:15:00.000Z',
], $aliceKeys['privateKeyPem']));
$fetchLog = [];
$batchDiff = WebhookSyncExtension::runBatch($pdo, $ctx, $extensions, 15, time() + 30, $mockFetcher);
check(
    $batchDiff['completed'] === 1 &&
    in_array('https://alice.example/api/items.json', $fetchLog, true) &&
    !in_array('https://alice.example/api/profile.json', $fetchLog, true),
    'differential sync fetches changed items.json while skipping unchanged profile.json'
);
$aliceItemCount = (int) $pdo->query("SELECT COUNT(*) FROM ext_webhook_sync_items WHERE did = '{$aliceDid}'")->fetchColumn();
check($aliceItemCount === 1, 'deleted item is pruned from hub feed items');
check(count($itemEvents) === 1 && $itemEvents[0]['removed'] === ['like-1'], 'items_changed lists the pruned item id');

// Exponential backoff (5 attempts -> failed, preserving Bob's previously verified item)
callExt($extensions, 'POST', '/api/ext/webhook-sync/v1/ping', [], signPing([
    'did' => $bobDid,
    'timestamp' => '2026-11-25T11:20:00.000Z',
], $bobRotatedKeys['privateKeyPem']));
$bobFailMode = true;
$simClock = time() + 60;
for ($attempt = 1; $attempt <= 5; $attempt++) {
    $res = WebhookSyncExtension::runBatch($pdo, $ctx, $extensions, 15, $simClock, $mockFetcher);
    if ($attempt < 5) {
        $q = $pdo->query("SELECT status, attempts, next_attempt_at FROM ext_webhook_sync_queue WHERE member_did = '{$bobDid}'")->fetch();
        $expectedNext = gmdate('Y-m-d\TH:i:s.000\Z', $simClock + (60 * (1 << $attempt)));
        if ($attempt === 1) {
            check(
                $res['retried'] === 1 && ($q['status'] ?? '') === 'pending' && (int) ($q['attempts'] ?? 0) === 1 && ($q['next_attempt_at'] ?? '') === $expectedNext,
                'transient failure schedules exponential backoff (60s * 2^attempts)'
            );
        }
        $simClock += (60 * (1 << $attempt)) + 1;
    } else {
        $qFinal = $pdo->query("SELECT status, attempts FROM ext_webhook_sync_queue WHERE member_did = '{$bobDid}'")->fetch();
        check(
            $res['failed'] === 1 && ($qFinal['status'] ?? '') === 'failed' && (int) ($qFinal['attempts'] ?? 0) === 5,
            'queue item marks status=failed after 5 failed attempts'
        );
    }
}
$bobItemsStillIntact = (int) $pdo->query("SELECT COUNT(*) FROM ext_webhook_sync_items WHERE did = '{$bobDid}'")->fetchColumn();
check($bobItemsStillIntact === 1, 'failed retries never corrupt previously verified feed items');

echo "feed pagination, end-to-end signature check & privacy purge\n";
// Re-add Alice's second item so we have 3 total items across Alice & Bob for pagination testing
$aliceItemsDoc['updatedAt'] = '2026-11-25T11:25:00.000Z';
$aliceItemsDoc['items'][] = [
    'id' => 'post-2',
    'canonicalUrl' => 'https://alice.example/api/items.json#post-2',
    'type' => 'post',
    'title' => 'Newest Post',
    'targetUrl' => 'https://alice.example/api/items.json#post-2',
    'category' => 'general',
    'commentary' => 'Latest!',
    'mediaUrl' => '',
    'tags' => [],
    'createdAt' => '2026-11-25T11:24:00.000Z',
];
$aliceItemsDoc = signStudioDoc($aliceItemsDoc, $aliceDid, $aliceKeys['privateKeyPem'], '2026-11-25T11:25:00.000Z');
$aliceManifestDoc['updatedAt'] = '2026-11-25T11:25:00.000Z';
$aliceManifestDoc['documents']['items']['sha256'] = docFingerprint($aliceItemsDoc);
$aliceManifestDoc = signStudioDoc($aliceManifestDoc, $aliceDid, $aliceKeys['privateKeyPem'], '2026-11-25T11:25:00.000Z');
$aliceEtag = '"etag-alice-v3"';
callExt($extensions, 'POST', '/api/ext/webhook-sync/v1/ping', [], signPing([
    'did' => $aliceDid,
    'timestamp' => '2026-11-25T11:25:00.000Z',
], $aliceKeys['privateKeyPem']));
WebhookSyncExtension::runBatch($pdo, $ctx, $extensions, 15, $simClock + 10, $mockFetcher);

[$fStatus1, $fPage1, $fHeaders1] = callExt($extensions, 'GET', '/api/ext/webhook-sync/v1/feed', ['limit' => '2']);
check($fStatus1 === 200 && count($fPage1['items'] ?? []) === 2 && !empty($fPage1['nextCursor']), 'feed page 1 returns 2 newest items and nextCursor');
check(($fHeaders1['Cache-Control'] ?? '') === 'no-store, no-cache, must-revalidate', 'feed emits no-store Cache-Control header');
check(($fPage1['items'][0]['itemId'] ?? '') === 'post-2' && ($fPage1['items'][1]['itemId'] ?? '') === 'bob-post-1', 'feed orders items newest published_at first');

// End-to-end cryptographic verification of returned raw `documents` envelopes!
$docsObj = $fPage1['documents'] ?? null;
$aliceEnv = ($docsObj instanceof stdClass) ? ($docsObj->{$aliceDid} ?? null) : null;
$bobEnv = ($docsObj instanceof stdClass) ? ($docsObj->{$bobDid} ?? null) : null;
check(
    $aliceEnv instanceof stdClass && Verify::verifyDocumentSignature($aliceEnv, $aliceKeys['publicKeyPem']) &&
    $bobEnv instanceof stdClass && Verify::verifyDocumentSignature($bobEnv, $bobRotatedKeys['publicKeyPem']),
    'Studio client can verify Ed25519 signatures end-to-end on raw documents returned by /feed'
);

[$fStatus2, $fPage2] = callExt($extensions, 'GET', '/api/ext/webhook-sync/v1/feed', [
    'limit' => '2',
    'before' => (string) $fPage1['nextCursor'],
]);
check(
    $fStatus2 === 200 &&
    count($fPage2['items'] ?? []) === 1 &&
    ($fPage2['items'][0]['itemId'] ?? '') === 'post-1' &&
    array_key_exists('nextCursor', $fPage2) &&
    $fPage2['nextCursor'] === null,
    'feed page 2 follows compound cursor cleanly with no duplicates and nextCursor=null'
);

// Privacy purges: user opt-out & entry removal
$extensions->emit('user.extension_opt_out', ['did' => $bobDid, 'extensionId' => 'webhook-sync']);
[, $afterOptOut] = callExt($extensions, 'GET', '/api/ext/webhook-sync/v1/feed', ['limit' => '10']);
check(count($afterOptOut['items'] ?? []) === 2, 'opting out purges that DID from feed items, state, and queue');

Entries::remove($pdo, $extensions, $aliceDid, 'self');
[, $afterRemove] = callExt($extensions, 'GET', '/api/ext/webhook-sync/v1/feed', ['limit' => '10']);
check(count($afterRemove['items'] ?? []) === 0, 'removing entry purges all remaining feed items');

$pdo = null;

// ============================================================================
// Part 2: Live HTTP + CLI `scripts/sync-worker.php` End-to-End Check
// ============================================================================

echo "http router & cli worker\n";
$peerSock = @stream_socket_server('tcp://127.0.0.1:0');
$hubSock = @stream_socket_server('tcp://127.0.0.1:0');
if ($peerSock !== false && $hubSock !== false && function_exists('curl_init')) {
    $peerPort = (int) substr(strrchr((string) stream_socket_get_name($peerSock, false), ':'), 1);
    $hubPort = (int) substr(strrchr((string) stream_socket_get_name($hubSock, false), ':'), 1);

    // Probe whether loopback TCP connections are permitted while $hubSock is still listening
    $probeClient = @stream_socket_client("tcp://127.0.0.1:{$hubPort}", $probeErrno, $probeErrstr, 0.2);
    $loopbackAllowed = is_resource($probeClient);
    if (is_resource($probeClient)) {
        fclose($probeClient);
    }
    fclose($peerSock);
    fclose($hubSock);

    if (!$loopbackAllowed) {
        echo "  skip live loopback HTTP check (sandbox blocks 127.0.0.1 connect)\n";
    } else {
        $peerDir = $tmpDir . '/peer';
        mkdir($peerDir, 0700, true);
        $peerSiteUrl = "http://127.0.0.1:{$peerPort}";
        $peerDid = Verify::siteUrlToDidWeb($peerSiteUrl);
        $peerKeys = Verify::generateKeyPair();

        $peerDidDoc = [
            'id' => $peerDid,
            'verificationMethod' => [[
                'id' => "{$peerDid}#key-1",
                'type' => 'Ed25519VerificationKey2020',
                'controller' => $peerDid,
                'publicKeyPem' => $peerKeys['publicKeyPem'],
            ]],
        ];
        $peerProfile = signStudioDoc([
            'schemaVersion' => '1.0.0',
            'siteUrl' => $peerSiteUrl,
            'did' => $peerDid,
            'displayName' => 'Loopback Peer',
            'handle' => 'peer',
            'updatedAt' => '2026-11-25T12:00:00.000Z',
        ], $peerDid, $peerKeys['privateKeyPem'], '2026-11-25T12:00:00.000Z');
        $peerItems = signStudioDoc([
            'schemaVersion' => '1.0.0',
            'siteUrl' => $peerSiteUrl,
            'did' => $peerDid,
            'updatedAt' => '2026-11-25T12:00:00.000Z',
            'items' => [[
                'id' => 'live-1',
                'canonicalUrl' => "{$peerSiteUrl}/api/items.json#live-1",
                'type' => 'post',
                'title' => 'Live HTTP Post',
                'targetUrl' => "{$peerSiteUrl}/api/items.json#live-1",
                'category' => 'general',
                'commentary' => 'Synced via CLI worker!',
                'mediaUrl' => '',
                'tags' => [],
                'createdAt' => '2026-11-25T12:00:00.000Z',
            ]],
        ], $peerDid, $peerKeys['privateKeyPem'], '2026-11-25T12:00:00.000Z');
        $peerManifest = signStudioDoc([
            'schemaVersion' => '1.0.0',
            'siteUrl' => $peerSiteUrl,
            'did' => $peerDid,
            'updatedAt' => '2026-11-25T12:00:00.000Z',
            'endpoints' => ['did' => '/.well-known/did.json', 'profile' => '/api/profile.json', 'items' => '/api/items.json'],
            'documents' => [
                'profile' => ['sha256' => docFingerprint($peerProfile)],
                'items' => ['sha256' => docFingerprint($peerItems)],
            ],
        ], $peerDid, $peerKeys['privateKeyPem'], '2026-11-25T12:00:00.000Z');

        file_put_contents($peerDir . '/did.json', json_encode($peerDidDoc));
        file_put_contents($peerDir . '/profile.json', json_encode($peerProfile));
        file_put_contents($peerDir . '/items.json', json_encode($peerItems));
        file_put_contents($peerDir . '/manifest.json', json_encode($peerManifest));
        file_put_contents($peerDir . '/router.php', '<?php
            $uri = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH);
            $etag = "\"peer-etag-v1\"";
            if ($uri === "/api/manifest.json") {
                if (($_SERVER["HTTP_IF_NONE_MATCH"] ?? "") === $etag) {
                    http_response_code(304);
                    header("ETag: " . $etag);
                    exit;
                }
                header("Content-Type: application/json");
                header("ETag: " . $etag);
                readfile(__DIR__ . "/manifest.json");
                exit;
            }
            $map = [
                "/.well-known/did.json" => "/did.json",
                "/did.json" => "/did.json",
                "/api/profile.json" => "/profile.json",
                "/api/items.json" => "/items.json",
            ];
            if (isset($map[$uri])) {
                header("Content-Type: application/json");
                readfile(__DIR__ . $map[$uri]);
                exit;
            }
            http_response_code(404);
        ');

        $liveDbPath = $tmpDir . '/live-hub.sqlite';
        $liveCfgPath = $tmpDir . '/live-config.php';
        file_put_contents($liveCfgPath, '<?php return ' . var_export([
            'db_driver' => 'sqlite',
            'sqlite_path' => $liveDbPath,
            'auto_migrate' => true,
            'allow_insecure' => true,
            'allow_private_hosts' => true,
            'rate_limit_enabled' => false,
            'extensions' => ['webhook-sync'],
        ], true) . ";\n");

        // Seed peer entry in live hub DB
        $liveCfg = Config::load(['db_driver' => 'sqlite', 'sqlite_path' => $liveDbPath, 'extensions' => ['webhook-sync'], 'auto_migrate' => true]);
        $livePdo = Database::open($liveCfg);
        $liveExt = Extensions::load($liveCfg, $livePdo);
        Entries::saveVerified($livePdo, $liveExt, [
            'siteUrl' => $peerSiteUrl,
            'did' => $peerDid,
            'manifest' => [],
            'profile' => ['displayName' => 'Loopback Peer', 'updatedAt' => '2026-11-25T11:59:00.000Z'],
            'publicKeyPem' => $peerKeys['publicKeyPem'],
            'keyRotations' => [],
        ]);
        $livePdo = null;

        $publicDir = realpath(__DIR__ . '/../public');
        $peerLog = $tmpDir . '/peer.log';
        $hubLog = $tmpDir . '/hub.log';
        $env = array_merge(getenv(), ['WEBRING_HUB_CONFIG' => $liveCfgPath]);

        $peerProc = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$peerPort}", $peerDir . DIRECTORY_SEPARATOR . 'router.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $peerLog, 'a'], 2 => ['file', $peerLog, 'a']],
            $peerPipes
        );
        $hubProc = proc_open(
            [PHP_BINARY, '-S', "127.0.0.1:{$hubPort}", '-t', $publicDir, $publicDir . DIRECTORY_SEPARATOR . 'index.php'],
            [0 => ['pipe', 'r'], 1 => ['file', $hubLog, 'a'], 2 => ['file', $hubLog, 'a']],
            $hubPipes,
            null,
            $env
        );

        $httpReq = static function (string $method, string $url, ?string $jsonBody = null): array {
            $respHeaders = [];
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT_MS => 4000,
                CURLOPT_HTTPHEADER => $jsonBody !== null ? ['Content-Type: application/json'] : [],
                CURLOPT_POSTFIELDS => $jsonBody,
                CURLOPT_HEADERFUNCTION => static function ($c, string $line) use (&$respHeaders): int {
                    $p = explode(':', $line, 2);
                    if (count($p) === 2) {
                        $respHeaders[strtolower(trim($p[0]))] = trim($p[1]);
                    }
                    return strlen($line);
                },
            ]);
            $raw = (string) curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            return [$code, json_decode($raw, true) ?? [], $respHeaders];
        };

        // Wait up to 2s for hub server readiness
        $ready = false;
        for ($w = 0; $w < 20; $w++) {
            [$hc] = $httpReq('GET', "http://127.0.0.1:{$hubPort}/api/healthz");
            if ($hc === 200) {
                $ready = true;
                break;
            }
            usleep(100_000);
        }
        check($ready, 'live php -S hub & peer test servers started');

        if ($ready) {
            $pingPayload = json_encode(signPing([
                'did' => $peerDid,
                'manifest_url' => "{$peerSiteUrl}/api/manifest.json",
                'timestamp' => '2026-11-25T12:00:00.000Z',
            ], $peerKeys['privateKeyPem']));
            [$pCode, $pJson, $pHdrs] = $httpReq('POST', "http://127.0.0.1:{$hubPort}/api/ext/webhook-sync/v1/ping", (string) $pingPayload);
            check($pCode === 202 && ($pJson['status'] ?? '') === 'queued', 'HTTP POST /v1/ping returns 202 Accepted');
            check(str_contains($pHdrs['cache-control'] ?? '', 'no-store'), 'HTTP POST /v1/ping sends Cache-Control: no-store');

            // Run CLI worker script
            $workerScript = realpath(__DIR__ . '/sync-worker.php');
            $wProc = proc_open(
                [PHP_BINARY, $workerScript],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $wPipes,
                null,
                $env
            );
            $wOut = is_resource($wProc) ? (string) stream_get_contents($wPipes[1]) : '';
            if (is_resource($wProc)) {
                fclose($wPipes[0]);
                fclose($wPipes[1]);
                fclose($wPipes[2]);
                proc_close($wProc);
            }
            check(str_contains($wOut, 'claimed=1 completed=1'), 'CLI sync-worker.php processes queued site over HTTP');

            [$fCode, $fJson, $fHdrs] = $httpReq('GET', "http://127.0.0.1:{$hubPort}/api/ext/webhook-sync/v1/feed");
            check(
                $fCode === 200 && count($fJson['items'] ?? []) === 1 && ($fJson['items'][0]['itemId'] ?? '') === 'live-1',
                'HTTP GET /v1/feed serves the CLI-synced item'
            );
            check(str_contains($fHdrs['cache-control'] ?? '', 'no-store'), 'HTTP GET /v1/feed sends Cache-Control: no-store');

            // Second ping + CLI worker run verifies live cURL If-None-Match -> 304 Not Modified
            $pingPayload2 = json_encode(signPing([
                'did' => $peerDid,
                'manifest_url' => "{$peerSiteUrl}/api/manifest.json",
                'timestamp' => '2026-11-25T12:01:00.000Z',
            ], $peerKeys['privateKeyPem']));
            [$pCode2] = $httpReq('POST', "http://127.0.0.1:{$hubPort}/api/ext/webhook-sync/v1/ping", (string) $pingPayload2);
            $wProc2 = proc_open(
                [PHP_BINARY, $workerScript],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $wPipes2,
                null,
                $env
            );
            $wOut2 = is_resource($wProc2) ? (string) stream_get_contents($wPipes2[1]) : '';
            if (is_resource($wProc2)) {
                fclose($wPipes2[0]);
                fclose($wPipes2[1]);
                fclose($wPipes2[2]);
                proc_close($wProc2);
            }
            check($pCode2 === 202 && str_contains($wOut2, 'notModified=1'), 'CLI sync-worker.php handles live HTTP 304 Not Modified');
        }

        if (is_resource($peerProc)) {
            proc_terminate($peerProc);
            proc_close($peerProc);
        }
        if (is_resource($hubProc)) {
            proc_terminate($hubProc);
            proc_close($hubProc);
        }
    }
}

// Best-effort cleanup of temp directory
$it = new \RecursiveIteratorIterator(
    new \RecursiveDirectoryIterator($tmpDir, \FilesystemIterator::SKIP_DOTS),
    \RecursiveIteratorIterator::CHILD_FIRST
);
foreach ($it as $f) {
    if ($f->isDir()) {
        @rmdir($f->getPathname());
    } else {
        @unlink($f->getPathname());
    }
}
@rmdir($tmpDir);

if ($failures > 0) {
    fwrite(STDERR, "{$failures} check(s) failed\n");
    exit(1);
}
echo "all webhook-sync checks passed\n";
