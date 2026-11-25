<?php
declare(strict_types=1);

namespace WebringHub\Ext\WebhookSync;

use PDO;
use stdClass;
use WebringHub\AppError;
use WebringHub\Database;
use WebringHub\Entries;
use WebringHub\Extension;
use WebringHub\ExtensionContext;
use WebringHub\ExtensionRequest;
use WebringHub\Extensions;
use WebringHub\Site;
use WebringHub\Verify;

/**
 * Webhook Sync & Feed Relay (`webhook-sync`):
 *
 * 1. `POST /api/ext/webhook-sync/v1/ping`:
 *    Fast-acknowledge (<50ms, zero network I/O) signed webhook endpoint. Verifies the sender's
 *    Ed25519 signature (including signed key-rotation chains), enforces monotonic timestamps and
 *    SSRF-safe endpoint validation, collapses rapid pings per DID into a single `pending` queue row,
 *    and returns `202 Accepted` with `Cache-Control: no-store, no-cache, must-revalidate`.
 *
 * 2. CLI Worker (`WebhookSyncExtension::runBatch` / `scripts/sync-worker.php`):
 *    Atomically claims batches of due `pending` rows, performs conditional `If-None-Match` /
 *    `If-Modified-Since` manifest checks (`304 Not Modified` short-circuit), compares SHA-256
 *    document fingerprints in `manifest.json`, verifies Ed25519 proofs, stores raw signed
 *    envelopes + indexed items, and retries transient failures with exponential backoff
 *    (`60s * 2^attempts`, up to 5 attempts).
 *
 * 3. `GET /api/ext/webhook-sync/v1/feed`:
 *    Cursor-paginated read endpoint returning verified items alongside their authors' raw signed
 *    `items.json` document envelopes so Webring Studio clients can verify signatures end-to-end.
 */
final class WebhookSyncExtension implements Extension
{
    public const NO_CACHE_HEADERS = [
        'Cache-Control' => 'no-store, no-cache, must-revalidate',
        'Pragma' => 'no-cache',
    ];

    public const DEFAULT_BATCH_SIZE = 15;
    public const DEFAULT_MAX_ATTEMPTS = 5;
    public const STALE_CLAIM_SECONDS = 300;
    public const MAX_FUTURE_SKEW_SECONDS = 300;
    private const ALLOWED_ITEM_TYPES = ['post', 'like', 'share', 'recommendation', 'comment'];

    private ExtensionContext $ctx;
    private string $queueTable = '';
    private string $stateTable = '';
    private string $itemsTable = '';

    public function register(ExtensionContext $ctx): void
    {
        $this->ctx = $ctx;
        $this->queueTable = $ctx->table('queue');
        $this->stateTable = $ctx->table('state');
        $this->itemsTable = $ctx->table('items');

        $ctx->migrate(1, function (PDO $pdo, string $driver): void {
            $this->createTables($pdo, $driver);
        });

        $ctx->on('entry.removed', fn (array $e) => $this->purgeDid((string) ($e['did'] ?? '')));
        $ctx->on('entry.status_changed', function (array $e): void {
            $status = (string) ($e['status'] ?? '');
            if ($status === 'blocked') {
                $this->purgeDid((string) ($e['did'] ?? ''));
            }
        });
        $ctx->on('user.extension_opt_out', function (array $e): void {
            if (($e['extensionId'] ?? '') === 'webhook-sync') {
                $this->purgeDid((string) ($e['did'] ?? ''));
            }
        });

        $ctx->post('v1/ping', fn (array $params, ExtensionRequest $req) => $this->handlePing($req), [
            'rateLimited' => true,
        ]);
        $ctx->get('v1/feed', fn (array $params, ExtensionRequest $req) => $this->handleFeed($req));
        $ctx->get('v1/admin/summary', fn () => $this->handleAdminSummary(), ['admin' => true]);
        $ctx->get('v1/admin/queue', fn (array $params, ExtensionRequest $req) => $this->handleAdminQueue($req), ['admin' => true]);
    }

    /**
     * Parses an ISO-8601 timestamp string and normalizes it to UTC `YYYY-MM-DDTHH:mm:ss.sssZ`
     * so lexicographic SQL comparisons (`<=`, `<`, `ORDER BY`) behave identically in MySQL and SQLite.
     * Returns null if invalid.
     */
    public static function normalizeIsoTimestamp(mixed $raw): ?string
    {
        if (!is_string($raw)) {
            return null;
        }
        $trimmed = trim($raw);
        if ($trimmed === '' || strlen($trimmed) > 64) {
            return null;
        }
        // Require explicit date + time + timezone (Z or ±HH:MM)
        if (!preg_match('/^\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}(?:\.(\d{1,9}))?(?:Z|[+-]\d{2}:?\d{2})$/i', $trimmed)) {
            return null;
        }
        try {
            $dt = new \DateTimeImmutable($trimmed);
            $utc = $dt->setTimezone(new \DateTimeZone('UTC'));
            $ms = substr($utc->format('u'), 0, 3);
            return $utc->format('Y-m-d\TH:i:s') . '.' . $ms . 'Z';
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Converts an associative array (e.g. from `json_decode(..., true)`) recursively into `stdClass`
     * objects where appropriate, preserving sequential lists as arrays, so `Verify::verifyKeyRotationChain`
     * and `Verify::canonicalizeJson` treat objects and lists identically to `json_decode(..., false)`.
     */
    public static function toStdObject(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map([self::class, 'toStdObject'], $value);
        }
        $obj = new stdClass();
        foreach ($value as $k => $v) {
            $obj->{(string) $k} = self::toStdObject($v);
        }
        return $obj;
    }

    /**
     * POST /api/ext/webhook-sync/v1/ping
     *
     * @return array{0: int, 1: array<string, mixed>, 2: array<string, string>}
     */
    private function handlePing(ExtensionRequest $req): array
    {
        $body = $req->json();
        if (array_is_list($body) || count($body) === 0) {
            throw new AppError(400, 'invalid_request', 'Send a signed JSON ping object.');
        }

        $did = is_string($body['did'] ?? null) ? trim($body['did']) : '';
        if ($did === '' || !str_starts_with($did, 'did:web:') || strlen($did) > 255) {
            throw new AppError(400, 'invalid_did', 'Ping must include a valid did:web identifier.');
        }

        $signature = is_string($body['signature'] ?? null) ? trim($body['signature']) : '';
        if ($signature === '' && isset($body['proof']) && is_array($body['proof']) && is_string($body['proof']['signatureValue'] ?? null)) {
            $signature = trim($body['proof']['signatureValue']);
        }
        if ($signature === '') {
            throw new AppError(401, 'missing_signature', 'Ping must include a detached Ed25519 signature.');
        }

        $normTimestamp = self::normalizeIsoTimestamp($body['timestamp'] ?? null);
        if ($normTimestamp === null) {
            throw new AppError(400, 'invalid_timestamp', 'Ping must include a valid ISO-8601 UTC timestamp.');
        }
        $maxFutureIso = gmdate('Y-m-d\TH:i:s.000\Z', time() + self::MAX_FUTURE_SKEW_SECONDS);
        if ($normTimestamp > $maxFutureIso) {
            throw new AppError(400, 'future_timestamp', 'Ping timestamp is too far in the future.');
        }

        $pdo = $this->ctx->pdo();
        if (Database::isBlocked($pdo, $did, '')) {
            throw new AppError(403, 'entry_blocked', 'This site is blocked on this hub.');
        }

        $entry = $this->ctx->entryByDid($did);
        if ($entry === null) {
            throw new AppError(404, 'not_found', 'DID is not registered on this hub.');
        }
        $entryStatus = (string) ($entry['status'] ?? '');
        if ($entryStatus === 'blocked') {
            throw new AppError(403, 'entry_blocked', 'This site is blocked on this hub.');
        }
        if ($entryStatus !== 'active') {
            throw new AppError(403, 'entry_inactive', 'Only active hub entries may enqueue webhook syncs.');
        }

        $siteUrl = Verify::normalizeSiteUrl((string) ($entry['site_url'] ?? ''));
        if ($siteUrl === '') {
            throw new AppError(500, 'invalid_entry_site', 'Stored entry site URL is invalid.');
        }
        if (Database::isBlocked($pdo, $did, $siteUrl)) {
            throw new AppError(403, 'entry_blocked', 'This site is blocked on this hub.');
        }

        // Validate manifest_url (if supplied) stays on the entry's exact site origin & path and uses https (unless dev allow_insecure)
        $rawManifestUrl = $body['manifest_url'] ?? ($body['manifestUrl'] ?? null);
        if ($rawManifestUrl !== null && (!is_string($rawManifestUrl) || trim($rawManifestUrl) === '')) {
            throw new AppError(400, 'invalid_manifest_url', 'manifest_url must be a non-empty URL string when provided.');
        }
        $manifestUrl = Site::resolveEndpoint($siteUrl, $rawManifestUrl, '/api/manifest.json');
        $manifestParts = parse_url($manifestUrl);
        if (!is_array($manifestParts) || empty($manifestParts['scheme']) || empty($manifestParts['host'])) {
            throw new AppError(400, 'invalid_manifest_url', 'manifest_url is not a valid URL.');
        }
        if (!empty($manifestParts['user']) || !empty($manifestParts['pass']) || isset($manifestParts['query']) || isset($manifestParts['fragment'])) {
            throw new AppError(400, 'invalid_manifest_url', 'manifest_url must not contain credentials, query strings, or fragments.');
        }

        // Verify detached Ed25519 signature over canonical JSON (excluding `signature` and `proof`)
        $signable = $body;
        unset($signable['signature'], $signable['proof']);
        $signableObj = self::toStdObject($signable);
        if (!$signableObj instanceof stdClass) {
            throw new AppError(400, 'invalid_request', 'Invalid ping payload.');
        }

        $pinnedPem = trim((string) ($entry['public_key_pem'] ?? ''));
        $verifyPem = $pinnedPem;

        // Support planned Ed25519 key rotations when the ping includes a valid `keyRotations` chain
        if (isset($body['keyRotations']) && is_array($body['keyRotations']) && count($body['keyRotations']) > 0) {
            $certs = [];
            foreach (array_slice($body['keyRotations'], 0, 10) as $certArr) {
                $certObj = self::toStdObject($certArr);
                if ($certObj instanceof stdClass) {
                    $certs[] = $certObj;
                }
            }
            $candidatePem = '';
            if (is_string($body['publicKeyPem'] ?? null) && trim($body['publicKeyPem']) !== '') {
                $candidatePem = trim($body['publicKeyPem']);
            } elseif (count($certs) > 0) {
                $lastCert = $certs[count($certs) - 1];
                if (is_string($lastCert->newPublicKeyPem ?? null)) {
                    $candidatePem = trim($lastCert->newPublicKeyPem);
                }
            }
            if (
                $candidatePem !== '' &&
                Verify::spkiFingerprint($candidatePem) !== '' &&
                Verify::verifyKeyRotationChain($pinnedPem, $candidatePem, $did, $siteUrl, $certs)
            ) {
                $verifyPem = $candidatePem;
            }
        }

        if (!Verify::verifyPayloadSignature($signableObj, $signature, $verifyPem)) {
            throw new AppError(401, 'invalid_signature', 'Ping signature did not verify against the pinned Ed25519 key.');
        }

        // Monotonic forward-only timestamp check against the last verified manifest timestamp
        $stateStmt = $pdo->prepare("SELECT last_manifest_updated_at FROM {$this->stateTable} WHERE did = ?");
        $stateStmt->execute([$did]);
        $lastManifestTs = $stateStmt->fetchColumn();
        if (is_string($lastManifestTs) && $lastManifestTs !== '' && $normTimestamp <= $lastManifestTs) {
            throw new AppError(
                409,
                'stale_timestamp',
                'Ping timestamp must be strictly newer than the last verified manifest timestamp.'
            );
        }

        $nowIso = $this->ctx->isoNow();
        $this->upsertQueuePending($pdo, $this->ctx->driver(), $did, $manifestUrl, $normTimestamp, $nowIso);

        return [
            202,
            [
                'status' => 'queued',
                'did' => $did,
                'manifestUrl' => $manifestUrl,
                'queuedAt' => $nowIso,
            ],
            self::NO_CACHE_HEADERS,
        ];
    }

    private function upsertQueuePending(
        PDO $pdo,
        string $driver,
        string $did,
        string $manifestUrl,
        string $pingTimestamp,
        string $nowIso
    ): void {
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("
                INSERT INTO {$this->queueTable}
                  (member_did, manifest_url, ping_timestamp, status, attempts, requested_at, next_attempt_at, claimed_at, claimed_by, completed_at, last_error)
                VALUES (?, ?, ?, 'pending', 0, ?, ?, NULL, NULL, NULL, NULL)
                ON CONFLICT(member_did) DO UPDATE SET
                  manifest_url = excluded.manifest_url,
                  ping_timestamp = CASE
                    WHEN excluded.ping_timestamp > {$this->queueTable}.ping_timestamp THEN excluded.ping_timestamp
                    ELSE {$this->queueTable}.ping_timestamp
                  END,
                  status = CASE
                    WHEN {$this->queueTable}.status = 'processing' THEN 'processing'
                    ELSE 'pending'
                  END,
                  attempts = CASE
                    WHEN {$this->queueTable}.status = 'processing' THEN {$this->queueTable}.attempts
                    ELSE 0
                  END,
                  requested_at = excluded.requested_at,
                  next_attempt_at = excluded.next_attempt_at,
                  last_error = CASE
                    WHEN {$this->queueTable}.status = 'processing' THEN {$this->queueTable}.last_error
                    ELSE NULL
                  END
            ");
            $stmt->execute([$did, $manifestUrl, $pingTimestamp, $nowIso, $nowIso]);
            return;
        }

        $stmt = $pdo->prepare("
            INSERT INTO {$this->queueTable}
              (member_did, manifest_url, ping_timestamp, status, attempts, requested_at, next_attempt_at, claimed_at, claimed_by, completed_at, last_error)
            VALUES (?, ?, ?, 'pending', 0, ?, ?, NULL, NULL, NULL, NULL)
            ON DUPLICATE KEY UPDATE
              manifest_url = VALUES(manifest_url),
              ping_timestamp = IF(VALUES(ping_timestamp) > ping_timestamp, VALUES(ping_timestamp), ping_timestamp),
              status = IF(status = 'processing', 'processing', 'pending'),
              attempts = IF(status = 'processing', attempts, 0),
              requested_at = VALUES(requested_at),
              next_attempt_at = VALUES(next_attempt_at),
              last_error = IF(status = 'processing', last_error, NULL)
        ");
        $stmt->execute([$did, $manifestUrl, $pingTimestamp, $nowIso, $nowIso]);
    }

    /**
     * GET /api/ext/webhook-sync/v1/feed?before=<cursor|iso>&limit=<1..100>&did=<optional>&type=<optional>
     *
     * @return array{0: int, 1: array<string, mixed>, 2: array<string, string>}
     */
    private function handleFeed(ExtensionRequest $req): array
    {
        $pdo = $this->ctx->pdo();
        $rawLimit = $req->query['limit'] ?? 50;
        $limit = is_numeric($rawLimit) ? min(max((int) $rawLimit, 1), 100) : 50;

        $filterDid = is_string($req->query['did'] ?? null) ? trim($req->query['did']) : '';
        $filterType = is_string($req->query['type'] ?? null) ? trim($req->query['type']) : '';
        if ($filterType !== '' && !in_array($filterType, self::ALLOWED_ITEM_TYPES, true)) {
            throw new AppError(400, 'invalid_type', 'type must be one of: post, like, share, recommendation, comment.');
        }

        // Cursor format: either plain ISO timestamp (`2026-11-25T12:00:00.000Z`) or compound
        // `publishedAt|did|itemId` for exact tie-breaking across items with identical timestamps.
        $rawBefore = is_string($req->query['before'] ?? null) ? trim($req->query['before']) : '';
        $cursorTs = null;
        $cursorDid = null;
        $cursorItemId = null;
        if ($rawBefore !== '') {
            if (str_contains($rawBefore, '|')) {
                $parts = explode('|', $rawBefore, 3);
                $cursorTs = self::normalizeIsoTimestamp($parts[0] ?? null);
                $cursorDid = isset($parts[1]) && $parts[1] !== '' ? $parts[1] : null;
                $cursorItemId = isset($parts[2]) && $parts[2] !== '' ? $parts[2] : null;
                if ($cursorTs === null) {
                    throw new AppError(400, 'invalid_cursor', 'Invalid before cursor timestamp.');
                }
            } else {
                $cursorTs = self::normalizeIsoTimestamp($rawBefore);
                if ($cursorTs === null) {
                    throw new AppError(400, 'invalid_cursor', 'before must be a valid ISO-8601 timestamp or cursor.');
                }
            }
        }

        $where = ["e.status = 'active'"];
        $params = [];

        if ($filterDid !== '') {
            $where[] = 'i.did = ?';
            $params[] = $filterDid;
        }
        if ($filterType !== '') {
            $where[] = 'i.content_type = ?';
            $params[] = $filterType;
        }
        if ($cursorTs !== null) {
            if ($cursorDid !== null && $cursorItemId !== null) {
                $where[] = '(i.published_at < ? OR (i.published_at = ? AND (i.did < ? OR (i.did = ? AND i.item_id < ?))))';
                array_push($params, $cursorTs, $cursorTs, $cursorDid, $cursorDid, $cursorItemId);
            } else {
                $where[] = 'i.published_at < ?';
                $params[] = $cursorTs;
            }
        }

        $fetchCount = $limit + 1;
        $sql = "
            SELECT i.did, e.site_url, i.item_id, i.canonical_url, i.content_type,
                   i.published_at, i.updated_at, i.raw_item_json
            FROM {$this->itemsTable} i
            INNER JOIN entries e ON e.did = i.did
            WHERE " . implode(' AND ', $where) . "
            ORDER BY i.published_at DESC, i.did DESC, i.item_id DESC
            LIMIT {$fetchCount}
        ";
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            $rows = array_slice($rows, 0, $limit);
        }

        $items = [];
        $didsOnPage = [];
        foreach ($rows as $row) {
            $did = (string) $row['did'];
            $didsOnPage[$did] = true;
            $rawDecoded = json_decode((string) $row['raw_item_json'], true);
            $items[] = [
                'did' => $did,
                'siteUrl' => (string) $row['site_url'],
                'itemId' => (string) $row['item_id'],
                'canonicalUrl' => (string) $row['canonical_url'],
                'contentType' => (string) $row['content_type'],
                'publishedAt' => (string) $row['published_at'],
                'updatedAt' => $row['updated_at'] !== null ? (string) $row['updated_at'] : null,
                'item' => is_array($rawDecoded) ? $rawDecoded : new stdClass(),
            ];
        }

        // Attach deduplicated raw signed `items.json` envelopes per author DID for end-to-end verification
        $documents = new stdClass();
        if (count($didsOnPage) > 0) {
            $didList = array_keys($didsOnPage);
            $placeholders = implode(',', array_fill(0, count($didList), '?'));
            $docStmt = $pdo->prepare(
                "SELECT did, raw_items_doc_json FROM {$this->stateTable} WHERE did IN ({$placeholders})"
            );
            $docStmt->execute($didList);
            foreach ($docStmt->fetchAll() as $docRow) {
                $rawDocStr = (string) ($docRow['raw_items_doc_json'] ?? '');
                if ($rawDocStr !== '') {
                    $decodedDoc = json_decode($rawDocStr, false);
                    if ($decodedDoc instanceof stdClass) {
                        $documents->{(string) $docRow['did']} = $decodedDoc;
                    }
                }
            }
        }

        $nextCursor = null;
        if ($hasMore && count($items) > 0) {
            $last = $items[count($items) - 1];
            $nextCursor = $last['publishedAt'] . '|' . $last['did'] . '|' . $last['itemId'];
        }

        return [
            200,
            [
                'items' => $items,
                'documents' => $documents,
                'nextCursor' => $nextCursor,
            ],
            self::NO_CACHE_HEADERS,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function handleAdminSummary(): array
    {
        $pdo = $this->ctx->pdo();
        $pending = (int) $pdo->query("SELECT COUNT(*) FROM {$this->queueTable} WHERE status = 'pending'")->fetchColumn();
        $processing = (int) $pdo->query("SELECT COUNT(*) FROM {$this->queueTable} WHERE status = 'processing'")->fetchColumn();
        $failed = (int) $pdo->query("SELECT COUNT(*) FROM {$this->queueTable} WHERE status = 'failed'")->fetchColumn();
        $syncedSites = (int) $pdo->query("SELECT COUNT(*) FROM {$this->stateTable}")->fetchColumn();
        $indexedItems = (int) $pdo->query("SELECT COUNT(*) FROM {$this->itemsTable}")->fetchColumn();

        return [
            'pendingQueue' => $pending,
            'processingQueue' => $processing,
            'failedQueue' => $failed,
            'syncedSites' => $syncedSites,
            'indexedItems' => $indexedItems,
        ];
    }

    /**
     * @return array{queue: list<array<string, mixed>>}
     */
    private function handleAdminQueue(ExtensionRequest $req): array
    {
        $pdo = $this->ctx->pdo();
        $rawLimit = $req->query['limit'] ?? 50;
        $limit = is_numeric($rawLimit) ? min(max((int) $rawLimit, 1), 200) : 50;

        $stmt = $pdo->prepare("
            SELECT member_did, manifest_url, status, attempts, requested_at, next_attempt_at, completed_at, last_error
            FROM {$this->queueTable}
            ORDER BY requested_at DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();

        $queue = [];
        foreach ($stmt->fetchAll() as $row) {
            $queue[] = [
                'did' => (string) $row['member_did'],
                'manifestUrl' => (string) $row['manifest_url'],
                'status' => (string) $row['status'],
                'attempts' => (int) $row['attempts'],
                'requestedAt' => (string) $row['requested_at'],
                'nextAttemptAt' => (string) $row['next_attempt_at'],
                'completedAt' => $row['completed_at'] !== null ? (string) $row['completed_at'] : null,
                'lastError' => (string) ($row['last_error'] ?? ''),
            ];
        }
        return ['queue' => $queue];
    }

    private function purgeDid(string $did): void
    {
        if ($did === '') {
            return;
        }
        $pdo = $this->ctx->pdo();
        $pdo->prepare("DELETE FROM {$this->queueTable} WHERE member_did = ?")->execute([$did]);
        $pdo->prepare("DELETE FROM {$this->stateTable} WHERE did = ?")->execute([$did]);
        $pdo->prepare("DELETE FROM {$this->itemsTable} WHERE did = ?")->execute([$did]);
    }

    // =========================================================================
    // CLI Batch Worker Logic (`scripts/sync-worker.php`)
    // =========================================================================

    /**
     * Claims up to `$batchSize` due `pending` rows atomically and processes each site's
     * conditional manifest + differential `profile.json` / `items.json` sync.
     *
     * @param ?callable(string, array<string, mixed>): array{url: string, status: int, headers: array<string, string>, json: mixed, jsonObject: mixed, rawBody: string} $customFetcher
     * @return array{claimed: int, completed: int, notModified: int, retried: int, failed: int}
     */
    public static function runBatch(
        PDO $pdo,
        ExtensionContext $ctx,
        Extensions $extensions,
        ?int $batchSize = null,
        ?int $nowEpoch = null,
        ?callable $customFetcher = null
    ): array {
        $queueTable = $ctx->table('queue');
        $stateTable = $ctx->table('state');
        $itemsTable = $ctx->table('items');
        $extCfg = $ctx->config();

        $limit = max(1, (int) ($batchSize ?? $extCfg['batch_size'] ?? self::DEFAULT_BATCH_SIZE));
        $maxAttempts = max(1, (int) ($extCfg['max_attempts'] ?? self::DEFAULT_MAX_ATTEMPTS));
        $nowTs = $nowEpoch ?? time();
        $nowIso = gmdate('Y-m-d\TH:i:s.000\Z', $nowTs);
        $staleClaimIso = gmdate('Y-m-d\TH:i:s.000\Z', $nowTs - self::STALE_CLAIM_SECONDS);
        $workerToken = bin2hex(random_bytes(16));

        // Recover rows left in 'processing' by a killed/crashed worker process
        $reclaimStmt = $pdo->prepare("
            UPDATE {$queueTable}
            SET status = 'pending', claimed_at = NULL, claimed_by = NULL
            WHERE status = 'processing' AND (claimed_at IS NULL OR claimed_at <= ?)
        ");
        $reclaimStmt->execute([$staleClaimIso]);

        // Select candidate pending rows due now
        $candStmt = $pdo->prepare("
            SELECT member_did
            FROM {$queueTable}
            WHERE status = 'pending' AND next_attempt_at <= ?
            ORDER BY next_attempt_at ASC, requested_at ASC
            LIMIT ?
        ");
        $candStmt->bindValue(1, $nowIso, PDO::PARAM_STR);
        $candStmt->bindValue(2, $limit, PDO::PARAM_INT);
        $candStmt->execute();
        $candidateDids = $candStmt->fetchAll(PDO::FETCH_COLUMN);

        // Claim each row atomically with `WHERE status = 'pending'` so overlapping workers never double-claim
        $claimStmt = $pdo->prepare("
            UPDATE {$queueTable}
            SET status = 'processing', claimed_at = ?, claimed_by = ?
            WHERE member_did = ? AND status = 'pending' AND next_attempt_at <= ?
        ");
        $claimedRows = [];
        foreach ($candidateDids as $candDid) {
            $didStr = (string) $candDid;
            $claimStmt->execute([$nowIso, $workerToken, $didStr, $nowIso]);
            if ($claimStmt->rowCount() === 1) {
                $rowStmt = $pdo->prepare("SELECT * FROM {$queueTable} WHERE member_did = ? AND claimed_by = ?");
                $rowStmt->execute([$didStr, $workerToken]);
                $qRow = $rowStmt->fetch();
                if (is_array($qRow)) {
                    $claimedRows[] = $qRow;
                }
            }
        }

        $stats = [
            'claimed' => count($claimedRows),
            'completed' => 0,
            'notModified' => 0,
            'retried' => 0,
            'failed' => 0,
        ];

        $fetcher = $customFetcher ?? static fn (string $url, array $opts): array => $ctx->fetchJson($url, $opts);

        foreach ($claimedRows as $qRow) {
            $did = (string) $qRow['member_did'];
            try {
                $outcome = self::syncOneMember($pdo, $ctx, $extensions, $stateTable, $itemsTable, $qRow, $nowIso, $fetcher);
                $doneStmt = $pdo->prepare("
                    UPDATE {$queueTable}
                    SET status = 'completed', attempts = 0, claimed_at = NULL, claimed_by = NULL, completed_at = ?, last_error = NULL
                    WHERE member_did = ?
                ");
                $doneStmt->execute([$nowIso, $did]);
                $stats['completed']++;
                if ($outcome === 'not_modified') {
                    $stats['notModified']++;
                }
            } catch (\Throwable $err) {
                $isPermanent = ($err instanceof AppError) && in_array($err->errorCode, [
                    'entry_removed',
                    'entry_blocked',
                    'key_changed',
                    'manifest_signature_invalid',
                    'items_signature_invalid',
                    'items_hash_mismatch',
                    'rollback_detected',
                ], true);

                $attempts = ((int) ($qRow['attempts'] ?? 0)) + 1;
                $errMsg = substr($err->getMessage() ?: 'Sync failed', 0, 500);

                if ($isPermanent || $attempts >= $maxAttempts) {
                    $failStmt = $pdo->prepare("
                        UPDATE {$queueTable}
                        SET status = 'failed', attempts = ?, claimed_at = NULL, claimed_by = NULL, last_error = ?
                        WHERE member_did = ?
                    ");
                    $failStmt->execute([$attempts, $errMsg, $did]);
                    $stats['failed']++;
                } else {
                    // Exponential backoff: 60s * 2^attempts (120s, 240s, 480s, 960s)
                    $backoffSec = 60 * (1 << $attempts);
                    $nextIso = gmdate('Y-m-d\TH:i:s.000\Z', $nowTs + $backoffSec);
                    $retryStmt = $pdo->prepare("
                        UPDATE {$queueTable}
                        SET status = 'pending', attempts = ?, next_attempt_at = ?, claimed_at = NULL, claimed_by = NULL, last_error = ?
                        WHERE member_did = ?
                    ");
                    $retryStmt->execute([$attempts, $nextIso, $errMsg, $did]);
                    $stats['retried']++;
                }
            }
        }

        return $stats;
    }

    /**
     * @param array<string, mixed> $qRow
     * @param callable(string, array<string, mixed>): array{url: string, status: int, headers: array<string, string>, json: mixed, jsonObject: mixed, rawBody: string} $fetcher
     * @return 'synced'|'not_modified'
     */
    private static function syncOneMember(
        PDO $pdo,
        ExtensionContext $ctx,
        Extensions $extensions,
        string $stateTable,
        string $itemsTable,
        array $qRow,
        string $nowIso,
        callable $fetcher
    ): string {
        $did = (string) $qRow['member_did'];
        $entry = $ctx->entryByDid($did);
        if ($entry === null) {
            throw new AppError(404, 'entry_removed', 'Entry no longer exists on this hub.');
        }
        if (($entry['status'] ?? '') === 'blocked') {
            throw new AppError(403, 'entry_blocked', 'Entry is blocked on this hub.');
        }

        $siteUrl = Verify::normalizeSiteUrl((string) ($entry['site_url'] ?? ''));
        $manifestUrl = Site::resolveEndpoint($siteUrl, $qRow['manifest_url'] ?? null, '/api/manifest.json');

        $stateStmt = $pdo->prepare("SELECT * FROM {$stateTable} WHERE did = ?");
        $stateStmt->execute([$did]);
        $state = $stateStmt->fetch();
        $state = is_array($state) ? $state : null;

        $budgetMs = $ctx->fetchBudgetMs();
        $deadline = microtime(true) + ($budgetMs / 1000.0);

        $condHeaders = [];
        if ($state !== null) {
            if (!empty($state['manifest_etag']) && is_string($state['manifest_etag'])) {
                $condHeaders[] = 'If-None-Match: ' . $state['manifest_etag'];
            }
            if (!empty($state['manifest_last_modified']) && is_string($state['manifest_last_modified'])) {
                $condHeaders[] = 'If-Modified-Since: ' . $state['manifest_last_modified'];
            }
        }

        $manifestRes = $fetcher($manifestUrl, [
            'headers' => $condHeaders,
            'budgetMs' => $budgetMs,
            'deadline' => $deadline,
        ]);

        if ($manifestRes['status'] === 304) {
            return 'not_modified';
        }

        $manifest = $manifestRes['json'];
        $manifestObj = $manifestRes['jsonObject'];
        if (!is_array($manifest) || array_is_list($manifest) || !$manifestObj instanceof stdClass) {
            throw new AppError(422, 'invalid_manifest', 'Remote site did not return a valid manifest JSON object.');
        }
        if (Verify::normalizeSiteUrl((string) ($manifest['siteUrl'] ?? '')) !== $siteUrl || ($manifest['did'] ?? '') !== $did) {
            throw new AppError(422, 'manifest_mismatch', 'Manifest siteUrl or did does not match the registered entry.');
        }

        $manifestUpdatedAt = self::normalizeIsoTimestamp($manifest['updatedAt'] ?? null);
        if ($manifestUpdatedAt === null) {
            throw new AppError(422, 'invalid_manifest', 'Manifest is missing a valid ISO-8601 updatedAt timestamp.');
        }
        $prevManifestTs = is_string($state['last_manifest_updated_at'] ?? null) ? $state['last_manifest_updated_at'] : '';
        if ($prevManifestTs !== '' && $manifestUpdatedAt < $prevManifestTs) {
            throw new AppError(409, 'rollback_detected', 'Refusing older manifest updatedAt than previously verified.');
        }

        $endpoints = is_array($manifest['endpoints'] ?? null) ? $manifest['endpoints'] : [];
        $activeKeyPem = trim((string) ($entry['public_key_pem'] ?? ''));
        $keyRotated = false;
        $keyRotations = [];

        // Verify manifest signature against pinned key; if it fails, check did.json for a signed key rotation chain
        if (empty($manifest['proof']) || !Verify::verifyDocumentSignature($manifestObj, $activeKeyPem)) {
            [$servedKeyPem, $certs] = self::fetchDidDocumentKeyAndRotations($siteUrl, $did, $endpoints, $fetcher, $budgetMs, $deadline);
            if (
                $servedKeyPem === '' ||
                !Verify::verifyKeyRotationChain($activeKeyPem, $servedKeyPem, $did, $siteUrl, $certs) ||
                !Verify::verifyDocumentSignature($manifestObj, $servedKeyPem)
            ) {
                if ($servedKeyPem !== '' && Verify::spkiFingerprint($servedKeyPem) !== Verify::spkiFingerprint($activeKeyPem)) {
                    Database::recordCandidateKey($pdo, $did, $servedKeyPem);
                    throw new AppError(409, 'key_changed', 'Site signing key changed without a valid rotation chain.');
                }
                throw new AppError(422, 'manifest_signature_invalid', 'Manifest Ed25519 signature did not verify.');
            }
            $activeKeyPem = $servedKeyPem;
            $keyRotated = true;
            $keyRotations = $certs;
        }

        $docHashes = is_array($manifest['documents'] ?? null) ? $manifest['documents'] : [];
        $declaredProfileSha = is_array($docHashes['profile'] ?? null) && is_string($docHashes['profile']['sha256'] ?? null)
            ? strtolower(trim($docHashes['profile']['sha256']))
            : '';
        $declaredItemsSha = is_array($docHashes['items'] ?? null) && is_string($docHashes['items']['sha256'] ?? null)
            ? strtolower(trim($docHashes['items']['sha256']))
            : '';

        $prevProfileSha = is_string($state['profile_sha256'] ?? null) ? $state['profile_sha256'] : '';
        $prevItemsSha = is_string($state['items_sha256'] ?? null) ? $state['items_sha256'] : '';

        // 1. Differential profile.json sync (only when fingerprint changed, missing, or key rotated)
        $needProfile = $keyRotated || $declaredProfileSha === '' || $declaredProfileSha !== $prevProfileSha;
        $newProfileSha = $prevProfileSha;
        if ($needProfile) {
            $profileUrl = Site::resolveEndpoint($siteUrl, $endpoints['profile'] ?? null, '/api/profile.json');
            $profileRes = $fetcher($profileUrl, ['budgetMs' => $budgetMs, 'deadline' => $deadline]);
            $profile = $profileRes['json'];
            $profileObj = $profileRes['jsonObject'];
            if (!is_array($profile) || !$profileObj instanceof stdClass) {
                throw new AppError(422, 'invalid_profile', 'Invalid profile.json document.');
            }
            if (($profile['did'] ?? '') !== $did || Verify::normalizeSiteUrl((string) ($profile['siteUrl'] ?? '')) !== $siteUrl) {
                throw new AppError(422, 'profile_mismatch', 'profile.json did or siteUrl mismatch.');
            }
            if (!Verify::verifyDocumentSignature($profileObj, $activeKeyPem)) {
                throw new AppError(422, 'profile_signature_invalid', 'profile.json signature did not verify.');
            }
            $computedProfileSha = hash('sha256', Verify::canonicalizeJson($profileObj));
            if ($declaredProfileSha !== '' && !hash_equals($declaredProfileSha, $computedProfileSha)) {
                throw new AppError(422, 'profile_hash_mismatch', 'profile.json does not match manifest sha256.');
            }
            $newProfileSha = $computedProfileSha;

            Entries::saveVerified($pdo, $extensions, [
                'siteUrl' => $siteUrl,
                'did' => $did,
                'manifest' => $manifest,
                'profile' => $profile,
                'publicKeyPem' => $activeKeyPem,
                'keyRotations' => $keyRotations,
                'fetchedAt' => $nowIso,
            ]);
        }

        // 2. Differential items.json sync (only when fingerprint changed or not yet cached)
        $hasCachedItemsDoc = !empty($state['raw_items_doc_json']);
        $needItems = !$hasCachedItemsDoc || $declaredItemsSha === '' || $declaredItemsSha !== $prevItemsSha;
        $newItemsSha = $prevItemsSha;
        $rawItemsDocJson = is_string($state['raw_items_doc_json'] ?? null) ? $state['raw_items_doc_json'] : null;

        if ($needItems) {
            $itemsUrl = Site::resolveEndpoint($siteUrl, $endpoints['items'] ?? null, '/api/items.json');
            $itemsRes = $fetcher($itemsUrl, ['budgetMs' => $budgetMs, 'deadline' => $deadline]);
            $itemsDoc = $itemsRes['json'];
            $itemsObj = $itemsRes['jsonObject'];
            if (!is_array($itemsDoc) || !$itemsObj instanceof stdClass || !is_array($itemsDoc['items'] ?? null)) {
                throw new AppError(422, 'invalid_items', 'Invalid items.json document.');
            }
            if (($itemsDoc['did'] ?? '') !== $did || Verify::normalizeSiteUrl((string) ($itemsDoc['siteUrl'] ?? '')) !== $siteUrl) {
                throw new AppError(422, 'items_mismatch', 'items.json did or siteUrl mismatch.');
            }
            if (!Verify::verifyDocumentSignature($itemsObj, $activeKeyPem)) {
                throw new AppError(422, 'items_signature_invalid', 'items.json Ed25519 signature did not verify.');
            }
            $computedItemsSha = hash('sha256', Verify::canonicalizeJson($itemsObj));
            if ($declaredItemsSha !== '' && !hash_equals($declaredItemsSha, $computedItemsSha)) {
                throw new AppError(422, 'items_hash_mismatch', 'items.json does not match manifest sha256 fingerprint.');
            }
            $newItemsSha = $computedItemsSha;
            $rawItemsDocJson = $itemsRes['rawBody'] !== ''
                ? $itemsRes['rawBody']
                : (json_encode($itemsObj, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: null);

            self::upsertVerifiedItems($pdo, $ctx->driver(), $itemsTable, $did, $itemsDoc);
        }

        $etag = isset($manifestRes['headers']['etag']) ? substr($manifestRes['headers']['etag'], 0, 255) : null;
        $lastMod = isset($manifestRes['headers']['last-modified']) ? substr($manifestRes['headers']['last-modified'], 0, 100) : null;

        self::upsertSiteState(
            $pdo,
            $ctx->driver(),
            $stateTable,
            $did,
            $etag,
            $lastMod,
            $manifestUpdatedAt,
            $newProfileSha !== '' ? $newProfileSha : null,
            $newItemsSha !== '' ? $newItemsSha : null,
            $rawItemsDocJson,
            $nowIso
        );

        return 'synced';
    }

    /**
     * @param array<string, mixed> $endpoints
     * @param callable $fetcher
     * @return array{0: string, 1: list<stdClass>}
     */
    private static function fetchDidDocumentKeyAndRotations(
        string $siteUrl,
        string $did,
        array $endpoints,
        callable $fetcher,
        int $budgetMs,
        float $deadline
    ): array {
        $siteParts = parse_url($siteUrl);
        $sitePath = is_array($siteParts) ? ($siteParts['path'] ?? '/') : '/';
        $didUrl = Site::resolveEndpoint($siteUrl, $endpoints['did'] ?? null, '/.well-known/did.json');

        try {
            $didRes = $fetcher($didUrl, ['budgetMs' => $budgetMs, 'deadline' => $deadline]);
        } catch (AppError $err) {
            if ($err->status === 404 && $sitePath !== '' && $sitePath !== '/') {
                $didRes = $fetcher(Site::resolveEndpoint($siteUrl, 'did.json', 'did.json'), [
                    'budgetMs' => $budgetMs,
                    'deadline' => $deadline,
                ]);
            } else {
                throw $err;
            }
        }

        $didDoc = $didRes['json'];
        if (!is_array($didDoc) || ($didDoc['id'] ?? '') !== $did) {
            return ['', []];
        }
        $methods = is_array($didDoc['verificationMethod'] ?? null) ? $didDoc['verificationMethod'] : [];
        $pubPem = '';
        foreach ($methods as $m) {
            if (is_array($m) && ($m['id'] ?? '') === "{$did}#key-1" && !empty($m['publicKeyPem'])) {
                $pubPem = trim((string) $m['publicKeyPem']);
                break;
            }
        }
        if ($pubPem === '') {
            foreach ($methods as $m) {
                if (is_array($m) && !empty($m['publicKeyPem']) && is_string($m['publicKeyPem'])) {
                    $pubPem = trim($m['publicKeyPem']);
                    break;
                }
            }
        }
        $certs = [];
        $didObj = $didRes['jsonObject'] ?? null;
        if ($didObj instanceof stdClass && isset($didObj->keyRotations) && is_array($didObj->keyRotations)) {
            foreach (array_slice($didObj->keyRotations, 0, 20) as $c) {
                if ($c instanceof stdClass) {
                    $certs[] = $c;
                }
            }
        }
        return [$pubPem, $certs];
    }

    /**
     * @param array<string, mixed> $itemsDoc
     */
    private static function upsertVerifiedItems(
        PDO $pdo,
        string $driver,
        string $itemsTable,
        string $did,
        array $itemsDoc
    ): void {
        $rawItems = is_array($itemsDoc['items'] ?? null) ? $itemsDoc['items'] : [];
        $archiveSince = null;
        if (is_array($itemsDoc['archive'] ?? null) && isset($itemsDoc['archive']['since'])) {
            $archiveSince = self::normalizeIsoTimestamp($itemsDoc['archive']['since']);
        }

        $owntx = !$pdo->inTransaction();
        if ($owntx) {
            $pdo->beginTransaction();
        }
        try {
            $seenIds = [];
            $upsertSql = $driver === 'sqlite'
                ? "INSERT INTO {$itemsTable}
                     (did, item_id, canonical_url, content_type, published_at, updated_at, raw_item_json)
                   VALUES (?, ?, ?, ?, ?, ?, ?)
                   ON CONFLICT(did, item_id) DO UPDATE SET
                     canonical_url = excluded.canonical_url,
                     content_type = excluded.content_type,
                     published_at = excluded.published_at,
                     updated_at = excluded.updated_at,
                     raw_item_json = excluded.raw_item_json"
                : "INSERT INTO {$itemsTable}
                     (did, item_id, canonical_url, content_type, published_at, updated_at, raw_item_json)
                   VALUES (?, ?, ?, ?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE
                     canonical_url = VALUES(canonical_url),
                     content_type = VALUES(content_type),
                     published_at = VALUES(published_at),
                     updated_at = VALUES(updated_at),
                     raw_item_json = VALUES(raw_item_json)";

            $stmt = $pdo->prepare($upsertSql);

            foreach (array_slice($rawItems, 0, 500) as $item) {
                if (!is_array($item)) {
                    continue;
                }
                $itemId = is_string($item['id'] ?? null) ? trim($item['id']) : '';
                $canonicalUrl = is_string($item['canonicalUrl'] ?? null) ? trim($item['canonicalUrl']) : '';
                $type = is_string($item['type'] ?? null) ? trim($item['type']) : 'post';
                $publishedAt = self::normalizeIsoTimestamp($item['createdAt'] ?? ($item['publishedAt'] ?? null));
                $updatedAt = isset($item['updatedAt']) ? self::normalizeIsoTimestamp($item['updatedAt']) : null;

                if (
                    $itemId === '' ||
                    strlen($itemId) > 255 ||
                    $canonicalUrl === '' ||
                    strlen($canonicalUrl) > 500 ||
                    !in_array($type, self::ALLOWED_ITEM_TYPES, true) ||
                    $publishedAt === null
                ) {
                    continue;
                }

                $rawJson = json_encode($item, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if (!is_string($rawJson)) {
                    continue;
                }

                $stmt->execute([$did, $itemId, $canonicalUrl, $type, $publishedAt, $updatedAt, $rawJson]);
                $seenIds[] = $itemId;
            }

            // Prune items deleted by the author inside the active (non-archived) window
            if (count($seenIds) === 0) {
                if ($archiveSince !== null) {
                    $del = $pdo->prepare("DELETE FROM {$itemsTable} WHERE did = ? AND published_at >= ?");
                    $del->execute([$did, $archiveSince]);
                } else {
                    $del = $pdo->prepare("DELETE FROM {$itemsTable} WHERE did = ?");
                    $del->execute([$did]);
                }
            } else {
                $placeholders = implode(',', array_fill(0, count($seenIds), '?'));
                if ($archiveSince !== null) {
                    $del = $pdo->prepare(
                        "DELETE FROM {$itemsTable} WHERE did = ? AND published_at >= ? AND item_id NOT IN ({$placeholders})"
                    );
                    $del->execute(array_merge([$did, $archiveSince], $seenIds));
                } else {
                    $del = $pdo->prepare(
                        "DELETE FROM {$itemsTable} WHERE did = ? AND item_id NOT IN ({$placeholders})"
                    );
                    $del->execute(array_merge([$did], $seenIds));
                }
            }

            if ($owntx) {
                $pdo->commit();
            }
        } catch (\Throwable $err) {
            if ($owntx && $pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $err;
        }
    }

    private static function upsertSiteState(
        PDO $pdo,
        string $driver,
        string $stateTable,
        string $did,
        ?string $etag,
        ?string $lastModified,
        string $manifestUpdatedAt,
        ?string $profileSha256,
        ?string $itemsSha256,
        ?string $rawItemsDocJson,
        string $syncedAt
    ): void {
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("
                INSERT INTO {$stateTable}
                  (did, manifest_etag, manifest_last_modified, last_manifest_updated_at, profile_sha256, items_sha256, raw_items_doc_json, synced_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(did) DO UPDATE SET
                  manifest_etag = excluded.manifest_etag,
                  manifest_last_modified = excluded.manifest_last_modified,
                  last_manifest_updated_at = excluded.last_manifest_updated_at,
                  profile_sha256 = excluded.profile_sha256,
                  items_sha256 = excluded.items_sha256,
                  raw_items_doc_json = excluded.raw_items_doc_json,
                  synced_at = excluded.synced_at
            ");
            $stmt->execute([$did, $etag, $lastModified, $manifestUpdatedAt, $profileSha256, $itemsSha256, $rawItemsDocJson, $syncedAt]);
            return;
        }

        $stmt = $pdo->prepare("
            INSERT INTO {$stateTable}
              (did, manifest_etag, manifest_last_modified, last_manifest_updated_at, profile_sha256, items_sha256, raw_items_doc_json, synced_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              manifest_etag = VALUES(manifest_etag),
              manifest_last_modified = VALUES(manifest_last_modified),
              last_manifest_updated_at = VALUES(last_manifest_updated_at),
              profile_sha256 = VALUES(profile_sha256),
              items_sha256 = VALUES(items_sha256),
              raw_items_doc_json = VALUES(raw_items_doc_json),
              synced_at = VALUES(synced_at)
        ");
        $stmt->execute([$did, $etag, $lastModified, $manifestUpdatedAt, $profileSha256, $itemsSha256, $rawItemsDocJson, $syncedAt]);
    }

    private function createTables(PDO $pdo, string $driver): void
    {
        if ($driver === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS {$this->queueTable} (
                  member_did TEXT PRIMARY KEY,
                  manifest_url TEXT NOT NULL,
                  ping_timestamp TEXT NOT NULL,
                  status TEXT NOT NULL DEFAULT 'pending',
                  attempts INTEGER NOT NULL DEFAULT 0,
                  requested_at TEXT NOT NULL,
                  next_attempt_at TEXT NOT NULL,
                  claimed_at TEXT DEFAULT NULL,
                  claimed_by TEXT DEFAULT NULL,
                  completed_at TEXT DEFAULT NULL,
                  last_error TEXT DEFAULT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_{$this->queueTable}_due
                  ON {$this->queueTable}(status, next_attempt_at, requested_at);

                CREATE TABLE IF NOT EXISTS {$this->stateTable} (
                  did TEXT PRIMARY KEY,
                  manifest_etag TEXT DEFAULT NULL,
                  manifest_last_modified TEXT DEFAULT NULL,
                  last_manifest_updated_at TEXT DEFAULT NULL,
                  profile_sha256 TEXT DEFAULT NULL,
                  items_sha256 TEXT DEFAULT NULL,
                  raw_items_doc_json TEXT DEFAULT NULL,
                  synced_at TEXT NOT NULL
                );

                CREATE TABLE IF NOT EXISTS {$this->itemsTable} (
                  did TEXT NOT NULL,
                  item_id TEXT NOT NULL,
                  canonical_url TEXT NOT NULL,
                  content_type TEXT NOT NULL,
                  published_at TEXT NOT NULL,
                  updated_at TEXT DEFAULT NULL,
                  raw_item_json TEXT NOT NULL,
                  PRIMARY KEY (did, item_id)
                );
                CREATE INDEX IF NOT EXISTS idx_{$this->itemsTable}_cursor
                  ON {$this->itemsTable}(published_at DESC, did DESC, item_id DESC);
                CREATE INDEX IF NOT EXISTS idx_{$this->itemsTable}_type
                  ON {$this->itemsTable}(content_type, published_at DESC);
            ");
            return;
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->queueTable} (
              member_did VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
              manifest_url VARCHAR(500) NOT NULL,
              ping_timestamp VARCHAR(40) NOT NULL,
              status VARCHAR(20) NOT NULL DEFAULT 'pending',
              attempts INT NOT NULL DEFAULT 0,
              requested_at VARCHAR(40) NOT NULL,
              next_attempt_at VARCHAR(40) NOT NULL,
              claimed_at VARCHAR(40) DEFAULT NULL,
              claimed_by VARCHAR(64) DEFAULT NULL,
              completed_at VARCHAR(40) DEFAULT NULL,
              last_error VARCHAR(500) DEFAULT NULL,
              PRIMARY KEY (member_did),
              KEY idx_queue_due (status, next_attempt_at, requested_at)
            ) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->stateTable} (
              did VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
              manifest_etag VARCHAR(255) DEFAULT NULL,
              manifest_last_modified VARCHAR(100) DEFAULT NULL,
              last_manifest_updated_at VARCHAR(40) DEFAULT NULL,
              profile_sha256 CHAR(64) DEFAULT NULL,
              items_sha256 CHAR(64) DEFAULT NULL,
              raw_items_doc_json MEDIUMTEXT DEFAULT NULL,
              synced_at VARCHAR(40) NOT NULL,
              PRIMARY KEY (did)
            ) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->itemsTable} (
              did VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
              item_id VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
              canonical_url VARCHAR(500) NOT NULL,
              content_type VARCHAR(40) NOT NULL,
              published_at VARCHAR(40) NOT NULL,
              updated_at VARCHAR(40) DEFAULT NULL,
              raw_item_json MEDIUMTEXT NOT NULL,
              PRIMARY KEY (did, item_id),
              KEY idx_items_cursor (published_at, did, item_id),
              KEY idx_items_type (content_type, published_at)
            ) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}
