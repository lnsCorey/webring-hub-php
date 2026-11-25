# Webhook Sync & Feed Relay Specification (`webhook-sync`)

This document specifies the HTTP API, canonical Ed25519 signing rules, queue state machine, conditional sync protocol, and SQL schema for the `webhook-sync` extension (`apiVersion: 1`). Both `webring-hub-php` and `webring-hub-node` MUST implement this contract identically.

---

## 1. Discovery & Configuration

When enabled (`'extensions' => ['webhook-sync']` in `config.php` or `HUB_EXTENSIONS=webhook-sync`), `/api/info` advertises:

```json
{
  "id": "webhook-sync",
  "version": "1.0.0",
  "apiVersion": 1
}
```

Optional operator settings (`config['extension_settings']['webhook-sync']`):

| Setting | Default | Description |
| --- | --- | --- |
| `batch_size` | `15` | Max pending queue rows claimed per `sync-worker` invocation |
| `max_attempts` | `5` | Max consecutive transient failures before a queue row is marked `failed` |

---

## 2. HTTP Endpoints

All JSON responses from `webhook-sync` MUST emit:
```http
Cache-Control: no-store, no-cache, must-revalidate
Pragma: no-cache
Content-Type: application/json; charset=utf-8
```

### 2.1 `POST /api/ext/webhook-sync/v1/ping`

Fast-acknowledge (<50 ms, **zero network I/O**) webhook endpoint called by Webring Studio after a publish goes live.

#### Request Body
```json
{
  "did": "did:web:alice.example",
  "manifest_url": "https://alice.example/api/manifest.json",
  "timestamp": "2026-11-25T12:00:00.000Z",
  "signature": "<base64url-ed25519-signature>",
  "publicKeyPem": "-----BEGIN PUBLIC KEY-----\n...\n-----END PUBLIC KEY-----",
  "keyRotations": []
}
```
- `did` *(required)*: `did:web:...` of an `active` listed entry on the Hub.
- `manifest_url` *(optional)*: If omitted, defaults to `<entry.siteUrl>/api/manifest.json`. If provided, MUST resolve on the exact same origin and path prefix as `entry.siteUrl` without credentials, query string, or fragment.
- `timestamp` *(required)*: ISO-8601 UTC timestamp (`YYYY-MM-DDTHH:mm:ss.sssZ`, max +300s clock skew). Must be **strictly greater than** `last_manifest_updated_at` in `ext_webhook_sync_state` (if previously synced).
- `signature` *(required)*: Detached base64url Ed25519 signature (64 raw bytes).
- `publicKeyPem` & `keyRotations` *(optional)*: Included only when the site rotated its Ed25519 key since the Hub last synced; verified via `Verify::verifyKeyRotationChain` starting from the Hub's pinned key.

#### Canonical Signing String
1. Take the parsed request JSON object and remove top-level `signature` and `proof` keys.
2. Serialize via Webring Studio's `canonicalizeJson`:
   - Object keys sorted lexicographically (`SORT_STRING` / UTF-16 code units for ASCII keys) recursively at all object depths.
   - Any nested `proof` key on objects dropped.
   - Arrays (lists) preserve element order.
   - Compact JSON with unescaped `/` and unescaped UTF-8 (`JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE`).
3. Sign/verify raw UTF-8 bytes with Ed25519 (`crypto.sign(null, bytes, key)` in Node.js / `Verify::verifyPayloadSignature` in PHP), base64url-encoded without `=` padding.

#### Responses
- **`202 Accepted`**:
  ```json
  {
    "status": "queued",
    "did": "did:web:alice.example",
    "manifestUrl": "https://alice.example/api/manifest.json",
    "queuedAt": "2026-11-25T12:00:01.120Z"
  }
  ```
- Error codes:
  - `400`: `invalid_request`, `invalid_did`, `invalid_timestamp`, `future_timestamp`, `invalid_manifest_url`
  - `401`: `missing_signature`, `invalid_signature`
  - `403`: `entry_blocked`, `entry_inactive`
  - `404`: `not_found`
  - `409`: `stale_timestamp`
  - `429`: `rate_limited`

---

### 2.2 `GET /api/ext/webhook-sync/v1/feed`

Cursor-paginated read endpoint returning verified items from `active` Hub entries alongside raw signed `items.json` document envelopes for end-to-end client verification.

#### Query Parameters
- `limit` *(optional, default `50`, clamped `1..100`)*
- `before` *(optional)*: Either an ISO-8601 timestamp (`2026-11-25T12:00:00.000Z`) or an opaque compound cursor `publishedAt|did|itemId` returned as `nextCursor` from the previous page.
- `did` *(optional)*: Filter to a single author `did:web:...`.
- `type` *(optional)*: Filter by `contentType` (`post`, `like`, `share`, `recommendation`, `comment`).

#### Response (`200 OK`)
```json
{
  "items": [
    {
      "did": "did:web:alice.example",
      "siteUrl": "https://alice.example",
      "itemId": "post-1",
      "canonicalUrl": "https://alice.example/api/items.json#post-1",
      "contentType": "post",
      "publishedAt": "2026-11-25T11:58:00.000Z",
      "updatedAt": null,
      "item": {
        "id": "post-1",
        "canonicalUrl": "https://alice.example/api/items.json#post-1",
        "type": "post",
        "title": "Hello Webring",
        "targetUrl": "https://alice.example/api/items.json#post-1",
        "category": "general",
        "commentary": "First post!",
        "mediaUrl": "",
        "tags": ["indieweb"],
        "createdAt": "2026-11-25T11:58:00.000Z"
      }
    }
  ],
  "documents": {
    "did:web:alice.example": {
      "schemaVersion": "1.0.0",
      "siteUrl": "https://alice.example",
      "did": "did:web:alice.example",
      "updatedAt": "2026-11-25T11:58:05.000Z",
      "items": [ ... ],
      "proof": {
        "type": "Ed25519Signature2020",
        "created": "2026-11-25T11:58:05.000Z",
        "verificationMethod": "did:web:alice.example#key-1",
        "signatureValue": "..."
      }
    }
  },
  "nextCursor": "2026-11-25T11:58:00.000Z|did:web:alice.example|post-1"
}
```
*(When there are no further pages, `nextCursor` is `null`.)*

---

## 3. CLI Batch Worker (`scripts/sync-worker.php`)

Cron schedule (Hostinger hPanel):
```cron
* * * * * /usr/bin/php /path/to/webring-hub-php/scripts/sync-worker.php
```

### Execution Flow
1. **Stale Claim Recovery**: Rows stuck in `status = 'processing'` with `claimed_at <= NOW() - 300s` are reset to `status = 'pending'`.
2. **Atomic Claim**: Up to `batch_size` (`15`) rows with `status = 'pending' AND next_attempt_at <= NOW()` (ordered by `next_attempt_at ASC, requested_at ASC`) are claimed atomically via `UPDATE ... WHERE member_did = ? AND status = 'pending'`.
3. **Conditional Manifest Fetch**:
   - Uses SSRF-safe crawler (`CURLOPT_RESOLVE` IP pinning, public-IP guard, 2 MB cap, shared `fetch_budget_ms` deadline).
   - Sends `If-None-Match` and `If-Modified-Since` from `ext_webhook_sync_state`.
   - On **`304 Not Modified`**: marks queue row `completed` immediately without fetching `profile.json` or `items.json`.
4. **Differential Verification (`200 OK`)**:
   - Verifies `manifest.json` `did`, `siteUrl`, monotonic `updatedAt >= last_manifest_updated_at`, and Ed25519 `proof` (checking `did.json` + `verifyKeyRotationChain` if rotated).
   - Compares `manifest.documents.profile.sha256` against `state.profile_sha256`; fetches/verifies `profile.json` and updates core `entries` only when changed or rotated.
   - Compares `manifest.documents.items.sha256` against `state.items_sha256`; fetches/verifies `items.json` (`Verify::verifyDocumentSignature` + SHA-256 over `canonicalizeJson(itemsDoc)`) only when changed.
   - Upserts items into `ext_webhook_sync_items` and prunes items deleted within the active window (preserving items older than `itemsDoc.archive.since`).
5. **Exponential Backoff**:
   - On transient error: `attempts = attempts + 1`.
   - If `attempts >= 5` (or permanent verification error such as `key_changed` / `rollback_detected`): `status = 'failed'`.
   - Otherwise: `status = 'pending'`, `next_attempt_at = now + (60 * 2^attempts)` seconds (`120s`, `240s`, `480s`, `960s`).

---

## 4. Database Schema (`mysql` & `sqlite`)

All timestamps are stored as normalized UTC ISO-8601 strings (`YYYY-MM-DDTHH:mm:ss.sssZ`, 24 chars) so ordering and cursor comparisons are byte-identical on MySQL and SQLite. MySQL `did` / `member_did` / `item_id` columns use `utf8mb4_bin` collation.

### `ext_webhook_sync_queue`
| Column | MySQL | SQLite | Notes |
| --- | --- | --- | --- |
| `member_did` | `VARCHAR(255) COLLATE utf8mb4_bin PK` | `TEXT PRIMARY KEY` | Deduplicates rapid pings per member |
| `manifest_url` | `VARCHAR(500) NOT NULL` | `TEXT NOT NULL` | Validated on-site manifest URL |
| `ping_timestamp` | `VARCHAR(40) NOT NULL` | `TEXT NOT NULL` | Normalized UTC ISO-8601 |
| `status` | `VARCHAR(20) NOT NULL` | `TEXT NOT NULL` | `pending`, `processing`, `completed`, `failed` |
| `attempts` | `INT NOT NULL DEFAULT 0` | `INTEGER NOT NULL DEFAULT 0` | Consecutive failure count |
| `requested_at` | `VARCHAR(40) NOT NULL` | `TEXT NOT NULL` | Latest ping arrival time |
| `next_attempt_at` | `VARCHAR(40) NOT NULL` | `TEXT NOT NULL` | Next eligible worker run time |
| `claimed_at` | `VARCHAR(40) NULL` | `TEXT NULL` | Worker claim timestamp |
| `claimed_by` | `VARCHAR(64) NULL` | `TEXT NULL` | Random hex worker token |
| `completed_at` | `VARCHAR(40) NULL` | `TEXT NULL` | Last completion timestamp |
| `last_error` | `VARCHAR(500) NULL` | `TEXT NULL` | Last error message |

### `ext_webhook_sync_state`
| Column | MySQL | SQLite | Notes |
| --- | --- | --- | --- |
| `did` | `VARCHAR(255) COLLATE utf8mb4_bin PK` | `TEXT PRIMARY KEY` | Member DID |
| `manifest_etag` | `VARCHAR(255) NULL` | `TEXT NULL` | Stored `ETag` header |
| `manifest_last_modified` | `VARCHAR(100) NULL` | `TEXT NULL` | Stored `Last-Modified` header |
| `last_manifest_updated_at` | `VARCHAR(40) NULL` | `TEXT NULL` | Monotonic manifest watermark |
| `profile_sha256` | `CHAR(64) NULL` | `TEXT NULL` | `manifest.documents.profile.sha256` |
| `items_sha256` | `CHAR(64) NULL` | `TEXT NULL` | `manifest.documents.items.sha256` |
| `raw_items_doc_json` | `MEDIUMTEXT NULL` | `TEXT NULL` | Raw signed `items.json` envelope |
| `synced_at` | `VARCHAR(40) NOT NULL` | `TEXT NOT NULL` | Last sync completion time |

### `ext_webhook_sync_items`
| Column | MySQL | SQLite | Notes |
| --- | --- | --- | --- |
| `did` | `VARCHAR(255) COLLATE utf8mb4_bin` | `TEXT NOT NULL` | Composite PK `(did, item_id)` |
| `item_id` | `VARCHAR(255) COLLATE utf8mb4_bin` | `TEXT NOT NULL` | Item `id` from `items.json` |
| `canonical_url` | `VARCHAR(500) NOT NULL` | `TEXT NOT NULL` | Item `canonicalUrl` |
| `content_type` | `VARCHAR(40) NOT NULL` | `TEXT NOT NULL` | `post`, `like`, `share`, `recommendation`, `comment` |
| `published_at` | `VARCHAR(40) NOT NULL` | `TEXT NOT NULL` | Normalized UTC `createdAt` |
| `updated_at` | `VARCHAR(40) NULL` | `TEXT NULL` | Optional edit timestamp |
| `raw_item_json` | `MEDIUMTEXT NOT NULL` | `TEXT NOT NULL` | Raw JSON object of the item |
