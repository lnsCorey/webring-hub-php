# Hub Extensions

Extensions add features to a Webring Hub without changing its core. An extension is a folder in `extensions/<id>/` that the operator installs and turns on. It can add API routes, keep its own tables, and react to changes in the hub's entries.

This guide is for people writing extensions and operators deciding whether to install one. The first extension, [`key-witness`](#worked-example-key-witness), is a complete example.

## Trust model: read this first

**PHP can't sandbox code it loads.** An enabled extension runs inside the hub with the same access as the hub itself: the database, the config (including the admin token), the network and the filesystem.

The framework keeps extensions predictable and easy to review:
- an allowlist;
- a declared manifest;
- a narrow host API;
- the rules below.

These are conventions, not a security boundary. **Operators should enable only code they've read or trust**, as with any WordPress plugin.

## Turning an extension on

1. Copy the folder to `extensions/<id>/`. A folder on its own does nothing.
2. Add its id to `config.php`:
   ```php
   'extensions' => ['key-witness'],
   // or set the environment variable HUB_EXTENSIONS=key-witness (the older DIRECTORY_EXTENSIONS also works)
   ```
3. Optionally, pass settings to it:
   ```php
   'extension_settings' => ['key-witness' => [/* ... */]],
   ```

On the next request the extension loads and runs its migrations. Check it with `GET /api/admin/extensions` (Bearer admin token), or open the Extensions panel on `/admin`. That shows every enabled extension, whether it loaded, its schema version, routes, events and any load error. An extension that fails to load is skipped; it never takes the hub down.

`/api/info` lists the extensions that loaded:

```json
{ "title": "…", "extensions": [{ "id": "key-witness", "version": "1.0.0", "apiVersion": 1 }] }
```

Clients such as Webring Studio use this list to decide what to call.

`.htaccess` blocks direct web access to `extensions/` and `docs/`.

## Rules

Every extension must follow these rules. They carry Webring Studio's static-site, local-first model over to the hub. See `webring studio/docs/architecture/webring-hub.md` for the reasoning.

1. **Off by default.** An extension runs only when the operator lists it.
2. **Hints, not truth.** Whatever an extension serves is evidence for the person reading it. Clients show it and throw it away. They never store or republish it, and it never overrides a key a client pinned itself. Design responses so they still make sense when treated as hints.
3. **Data only from verified sources.** Learn about sites only from core events, which fire after the hub has verified the site's signed files, or from your own fetches through `$ctx->fetchJson()`, which applies the private-network guard. Never accept content uploads about a site.
   - **Members' own actions.** A signed-in member may record small actions about their *own* participation, such as a reaction or a report, through routes with `requireOptIn`. Only members who opted in to your extension can do this, and you must delete it all on `user.extension_opt_out`. Posts, comments and anything else that is content stay on the member's own signed site.
4. **Stay in your namespace.**
   - Routes go under `/api/ext/<id>/v<n>/`.
   - Tables are named `$ctx->table('…')`, which gives `ext_<id>_…`.
   - Settings come from `$ctx->config()`.
   - Read core data through `$ctx->entryByDid()` or plain `SELECT`s, and never write to core tables.
5. **The listed person's choices win.** When a site removes itself, or an admin deletes it, you get `entry.removed`. You must then delete everything you hold for that DID. Respect `status`: don't serve data about `blocked` or `stale` entries.
6. **No trackers.** Don't add third-party scripts, pixels or fonts to hub pages. Keep logs of IP addresses or requests to the minimum (the core's rate limiting is enough for most needs).
7. **Clients never run your code.** Webring Studio has built-in clients for specific `id` and `apiVersion` pairs and ignores any extension it doesn't know. Keep responses plain JSON, and treat the routes and fields under a version prefix as a contract.
8. **Don't break the core.** Event handlers run after the core has committed its change. An exception in a handler is logged and ignored, so handlers can't undo or block a core change. Make them idempotent and cheap: they run inside the request that caused the change.
9. **Safe UI surfaces.** Public extension pages follow the no-trackers rule and send a strict CSP (no third-party scripts, fonts or pixels). Admin panels are declared as JSON in `extension.json`, point only at your own `admin: true` routes, and are rendered by the hub's admin page as plain text (`textContent`) so extension code never runs in `/admin`.

## User and admin surfaces

An extension can declare an optional `ui` object in `extension.json` (`apiVersion` stays `1`). That determines the extension's **kind**:

| `ui` declares | Kind | Where it appears |
| --- | --- | --- |
| `public` only | `public` (**user-facing**) | Home-page carousel and `/api/info` card fields |
| `admin` only | `admin` (**admin**) | Declarative panels on `/admin` |
| both | `both` | Home-page carousel and `/admin` panels |
| neither | `headless` (**API only**) | Listed in `/admin` and `/api/info` without UI surfaces |

- **`ui.public`:** `{ title, tagline, href, cta, art, accent }`. `href` must stay under `/ext/<id>/`, `art` is a relative image file (`svg`, `png`, `jpg`, `webp`) served under `/ext/<id>/`, and `accent` is one of `magenta`, `cyan`, `yellow`, `green`, `violet`, `orange` (mapped to CSS classes so the home page's `style-src 'self'` CSP stays strict).
- **`ui.admin.panels`:** up to 8 declarative panels (`stats`, `table`, or `form`), each with at most 12 columns/fields and 6 actions. Every `source` and action `path` must match an `admin: true` route registered by the same extension. Column renderers are `text`, `code`, `time`, and `badge`; nothing renders HTML.
- If any `ui` validation rule fails, the `ui` block is ignored (`kind` falls back to `headless`), the extension still loads, and `/admin` displays `uiError`.
- `/api/admin/extensions` also returns `available`: installed folders in `extensions/` that are not enabled, read from `extension.json` only without executing any PHP.

## Layout

```
extensions/
  my-extension/
    extension.json
    Extension.php
```

### `extension.json`

```json
{
  "id": "my-extension",
  "name": "My Extension",
  "version": "1.0.0",
  "apiVersion": 1,
  "class": "WebringHub\\Ext\\MyExtension\\MyExtension",
  "description": "One sentence about what it does.",
  "author": "",
  "homepage": "",
  "routes": ["GET v1/things/{did}"],
  "events": ["entry.verified", "entry.removed"],
  "tables": ["things"]
}
```

| Field | Notes |
| --- | --- |
| `id` | `^[a-z][a-z0-9-]{1,40}$`. Must match the folder name. |
| `version` | Your own release version, shown in `/api/info`. |
| `apiVersion` | The host API version you target. This hub supports `1`. |
| `class` | Fully qualified class in `Extension.php` that implements `WebringHub\Extension`. |
| `routes`, `events`, `tables` | Declarations for reviewers and operators. The routes and listeners actually registered appear in `GET /api/admin/extensions`. |
| `reads` | Optional list of other extension ids whose tables you read (for example `["webhook-sync"]`). The hub loads those first, whatever the config order, and refuses to load yours (with a message naming the missing one on `/admin`) if they aren't enabled. Use `$ctx->readTable()` to get their table names. |
| `userData` | Optional `{ "optInRequired": true, "summary": "…" }` when you store data under a member's DID. The hub lists it in the member's **My Data** dialog, where they opt in or out. |

### `Extension.php`

```php
<?php
declare(strict_types=1);

namespace WebringHub\Ext\MyExtension;

use WebringHub\Extension;
use WebringHub\ExtensionContext;

final class MyExtension implements Extension
{
    public function register(ExtensionContext $ctx): void
    {
        $table = $ctx->table('things');
        $ctx->migrate(1, function (\PDO $pdo, string $driver) use ($table): void {
            // Create $table for 'sqlite' or 'mysql'
        });
        $ctx->on('entry.removed', function (array $e) use ($ctx, $table): void {
            $ctx->pdo()->prepare("DELETE FROM {$table} WHERE did = ?")->execute([$e['did']]);
        });
        $ctx->get('v1/things/{did}', fn (array $params) => ['did' => $params['did']]);
    }
}
```

## Host API (`ExtensionContext`, apiVersion 1)

| Method | What it does |
| --- | --- |
| `get(string $subPath, callable $handler, array $opts = [])` | Adds a GET (and HEAD) route at `/api/ext/<id>/<subPath>`. `$subPath` must start with a version segment such as `v1/`. `{name}` matches one path segment, URL-decoded. Options: `admin` (needs the Bearer admin token), `rateLimited` (counts toward the write rate limit), `userAuth` (needs a signed-in member; POST/DELETE also need the `X-Hub-Csrf` header) and `requireOptIn` (as `userAuth`, and the member must have opted in to your extension). |
| `post(...)` | The same, for POST. Read the body with `$req->json()` (64 KB limit, JSON object). |
| `delete(...)` | The same, for DELETE. |
| `serveFiles(string $prefix, string $dir, array $opts = [])` | Serves static files inside `$dir` (which must resolve under the extension folder) at `/ext/<id>/<prefix>/...` with an explicit web-safe MIME allowlist, ETag/304 support, traversal/dotfile blocking, optional `cors`, `csp`, and `frameable` headers. |
| `on(string $event, callable $handler)` | Listens for a core event (below), or for `ext.<id>.<name>` emitted by another extension. |
| `emit(string $name, array $payload)` | Broadcasts `ext.<your id>.<name>` to every listener. Call it after your own changes are committed; listeners run inline and their failures are logged and ignored. |
| `readTable(string $extId, string $suffix)` | Another extension's table name, for an extension listed in your `reads`. Read it only; never write to another extension's tables. |
| `migrate(int $version, callable $up)` | Runs `$up(PDO $pdo, string $driver)` once when the stored schema version is lower. Call it with increasing versions. The version is kept in `settings` under `ext:<id>:schema`. Support both `sqlite` and `mysql`. |
| `table(string $suffix)` | Your table name: `ext_<id with - as _>_<suffix>`. |
| `pdo()`, `driver()` | The shared connection, and `'sqlite'` or `'mysql'`. |
| `config()` | `config['extension_settings'][<id>]`, or `[]`. |
| `entryByDid(string $did)` | Read-only core entry row, or `null`. |
| `fetchJson(string $url)` | `Net::safeGetJson` with the hub's guard settings: https only, no private addresses, size and time limits. |
| `isoNow()` | The core's timestamp format. |

A route handler receives `(array $params, ExtensionRequest $req)`. `$req` has `method`, `path`, `query`, `json()` (the request body, read on demand) and `clientKey`: a salted hash that identifies the caller's IP address without revealing it, for your own per-client limits. Never store raw IPs. `$req->userDid` is the signed-in member's DID (or `null`) and `$req->userOptedIn` says whether they opted in to your extension. On a POST or DELETE to a route without `userAuth`/`requireOptIn`, `userDid` is only set when the request also passes the CSRF check, so a cross-site form can't act as the member. The handler returns either an array (sent as JSON with status 200) or `[int $status, array $body]`. Throw `WebringHub\AppError` for error responses, for example `new AppError(404, 'not_found', 'No such entry.')`; they use the core's `{error, message}` shape.

Extension routes are matched only after every core route, so they can never shadow one.

## Events

| Event | Payload | When |
| --- | --- | --- |
| `entry.verified` | `site` (verified site from `Site::verifySite`, including `publicKeyPem` and `keyRotations`), `row` (the saved entry), `inserted`, `keyRotated`, `keyAcceptedBy` (`'chain'`, `'operator'` or `null`), `previousKeyPem` | After a submit, refresh, admin refresh, cron refresh or operator trust-key saves an entry. |
| `entry.key_rejected` | `site`, `row` | The site presented a key that's different from the pinned one, with no valid rotation chain. The core refused it (409 `key_changed`) and kept the old key. |
| `entry.removed` | `did`, `siteUrl`, `reason` (`'self'` or `'admin'`) | After a signed self-removal or an admin delete. **Delete your data for this DID.** |
| `entry.status_changed` | `did`, `status` | An admin set `active`, `blocked` or `stale`. (A cron sweep marking an entry stale after repeated failures does not fire this; check `status` when serving.) |
| `cron.refresh` | `checked`, `updated`, `failed` | At the end of `scripts/refresh.php`. A good place for periodic work. |
| `user.extension_opt_out` | `did`, `extensionId` | A member opted out of an extension in **My Data**. Sent to every extension: check `extensionId` and, when it's yours, delete everything you hold for that DID. |

Extensions can also emit their own events, named `ext.<id>.<name>`:

| Event | Payload | When |
| --- | --- | --- |
| `ext.webhook-sync.items_changed` | `did`, `upserted` (item ids), `removed` (item ids), `removedAll` (bool) | After `webhook-sync` stores a member's verified `items.json`, or deletes all their items (removal, block, opt-out). |

## Versioning

- **Host API** (`apiVersion`): the hub refuses to load an extension whose `apiVersion` it doesn't support. New host methods may be added within a version; nothing is removed or changed.
- **Your routes:** put the version in the path (`v1/`). Add fields freely, but make breaking changes under `v2/` and keep `v1/` working for a while.
- **Clients:** Webring Studio's `KNOWN_WEBRING_HUB_EXTENSIONS` maps each id to the `apiVersion` it speaks.

## Worked example: `key-witness`

`extensions/key-witness/` records which signing key the hub saw for each listed site, when, and how a change was accepted. Webring Studio uses it to compare the key it pinned for a friend with an independent observer. This is the "second witness" from the Tier 2 roadmap.

- **Table `ext_key_witness_observations`:** one row per (DID, key fingerprint, outcome), with first-seen and last-seen times. Outcomes:
  - `first-seen`: the first key the hub saw;
  - `rotated`: accepted through a signed rotation chain; the certificates are kept;
  - `operator-accepted`: accepted by the operator with trust-key;
  - `rejected`: presented, but refused by the core.

  When it's turned on, existing entries are filled in as `first-seen`.
- **Fingerprint:** `Verify::spkiFingerprint`, SHA-256 hex over the SPKI DER bytes. It doesn't depend on PEM wrapping, and it matches Studio's `publicKeyFingerprint`. Both sides check the same test vector (`scripts/smoke-extensions.php` and Studio's `tests/federation.test.ts`).
- **Events:**
  - `entry.verified` records or bumps the current key;
  - `entry.key_rejected` records the refused key;
  - `entry.removed` deletes the site's whole history.
- **Route `GET /api/ext/key-witness/v1/keys/{did}`:** returns 404 unless the entry is `active`.

  ```json
  {
    "did": "did:web:alice.example",
    "siteUrl": "https://alice.example",
    "current": { "fingerprint": "324b…", "since": "2026-01-01T00:00:00.000Z" },
    "history": [
      { "fingerprint": "324b…", "outcome": "first-seen", "firstSeenAt": "…", "lastSeenAt": "…" },
      { "fingerprint": "9f1c…", "outcome": "rejected", "firstSeenAt": "…", "lastSeenAt": "…" }
    ],
    "observedAt": "…"
  }
  ```

  History holds up to 50 items, oldest first. Responses aren't signed yet; signing them with the hub's own did:web key is planned together with the signed snapshot.

## Testing

`php scripts/smoke-extensions.php` runs the loader and key-witness against a temporary SQLite database. It covers loading and refusing extensions, routes, every outcome, removal and a failing listener. Run it after changing the framework or the extension.

## Included: `social`

`extensions/social/` (Conversations & Reactions) is the first extension to build on another one. It declares `"reads": ["webhook-sync"]`, listens for `ext.webhook-sync.items_changed` to index mentions, and stores reactions and reports only for members who opted in. See [social.md](social.md).

## Planned extensions

| Id | What it would do |
| --- | --- |
| `site-moves` | Keep signed `movedTo` statements (signed with the old site's key) so friends and rings can follow a site to a new domain after the old one is gone. |
| `ring-index` | Index webrings from verified `connections.json` files and hosted ring hubs, so rings can be browsed from Studio. |
| `snapshot` | Publish signed static `entries.json` documents under the hub's did:web, so clients can read the hub like any other peer. |

A delegated inbox (holding messages for people who are offline) is deliberately not planned. See the Studio docs.
