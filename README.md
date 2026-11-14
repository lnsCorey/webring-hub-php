# Webring Hub (PHP + MySQL Edition)

**Webring Hub** (formerly *Webring Directory*) is a searchable, opt-in index of Webring Studio sites that can grow with optional extensions. This is a zero-dependency **PHP 8.1+ & MySQL** port of the Node.js `webring-directory` reference implementation, designed for shared hosting environments such as **Hostinger Premium** (LiteSpeed / Apache + MySQL / MariaDB + hPanel Cron Jobs).

It provides 100% API, UI, and cryptographic parity with the Node.js reference implementation:

- **Ed25519 + `did:web` Verification** (`ext-sodium` / `ext-openssl`) — verifies `manifest.json`, `/.well-known/did.json`, and `profile.json` signatures identically to Webring Studio.
- **SSRF-Safe Crawler** (`ext-curl`) — blocks private/reserved IPv4 & IPv6 ranges, pins resolved DNS records via `CURLOPT_RESOLVE`, re-validates redirects, and caps responses at 2 MB.
- **MySQL InnoDB + `FULLTEXT` Search** (`ext-pdo_mysql`) — searches `display_name`, `handle`, `tagline`, `bio`, `location`, and `interests_text` with boolean full-text search and automatic `LIKE` fallback for short queries.
- **Key Pinning with Rotation Chains** — the first Ed25519 key seen for a site is pinned. A later key is accepted only through a signed rotation chain from the pinned key (`keyRotations` in the site's `did.json`, as published by Webring Studio's **Rotate Signing Key**) or when the operator accepts it (**Trust new key** on `/admin`, or `POST /api/admin/entries/:did/trust-key`). Otherwise re-verification fails with `409 key_changed` and the listing keeps the old key, so taking over a domain doesn't take over its listing or its signed self-removal.
- **Extensions** — optional modules in `extensions/<id>/`, turned on by listing them in `config.php`. They add routes under `/api/ext/<id>/`, keep their own tables and react to entry changes. The first one, `key-witness`, keeps a history of the keys seen for each site. See [docs/extensions.md](docs/extensions.md).
- **Admin Page** — `/admin` lets the operator review every listing, block, re-check or delete sites, accept a changed key, and edit the instance settings. See [Admin page](#admin-page-admin).
- **Instance Customization & W3C `did:web` Identity** — custom **Title**, **Description**, **Banner**, optional **Maintainer Badge** (off by default), and `/.well-known/did.json` with `controller` linked to the maintainer's DID.

> **Upgrading from Webring Directory:** nothing breaks. The HTTP API, JSON fields, database tables and the hub's `did:web` are unchanged. Old `config.php` files and the old `DIRECTORY_*` environment variables keep working; the new names are listed in [Configuration reference](#configuration-reference). See [Upgrading an existing install](#upgrading-an-existing-install).

## What's new

- **Renamed to Webring Hub.** The name changed in the pages, docs, PHP namespace (`WebringHub\…`) and settings (`HUB_*`, `hub_url`). The old names still work.
- **Admin page at `/admin`.** Review every listing, including blocked and stale ones; block, unblock, refresh, delete, or accept a changed key; add sites; and edit the instance settings. There's a new endpoint behind it, `GET /api/admin/entries`.
- **Extensions.** Optional modules in `extensions/<id>/`, turned on in `config.php`, with their own routes, tables and settings. **`key-witness`** is the first: a history of the keys the hub has seen for each site, used by Webring Studio's **Check with Webring Hubs**.
- **Key pinning with rotation.** A site's key can change only through a signed rotation certificate or **Trust new key** on `/admin`.
- **Fixes:**
  - Search works on SQLite. It used to return a 500 error.
  - `/.well-known/did.json` works under PHP's built-in server.
  - `scripts/sign-request.js` no longer needs any other files.

### Names kept for compatibility

These still say "directory", so existing sites, clients and data keep working. Don't rename them:

- the API paths
- the `directoryUrl` field in `/api/admin/settings`
- the `invalid_directory_url` error code
- the `#directory` service (type `WebringDirectory`) in the hub's `did.json`
- the SQLite file `data/directory.sqlite`

---

## Deploying to Hostinger Premium (Step-by-Step)

### 1. Create a MySQL Database in Hostinger hPanel

1. Log in to **Hostinger hPanel** → **Websites** → click **Manage** next to your domain.
2. In the left sidebar, open **Databases** → **Management**.
3. Under **Create a New MySQL Database And Database User**, enter:
   - **MySQL Database Name** (e.g. `u123456789_webring`)
   - **MySQL Username** (e.g. `u123456789_webring`)
   - **Password** (generate a strong password)
4. Click **Create** and keep those credentials handy.
5. *(Optional)* Tables are created automatically on first request when `'auto_migrate' => true` in `config.php`. If you prefer to import the schema manually, click **Enter phpMyAdmin** next to your database and import `sql/schema.mysql.sql`.

---

### 2. Configure `config.php`

1. Copy `config.example.php` to `config.php`:
   ```bash
   cp config.example.php config.php
   ```
2. Open `config.php` and fill in your Hostinger MySQL credentials and instance customization:
   ```php
   <?php
   return [
       'db' => [
           'driver' => 'mysql',
           'host' => '127.0.0.1',
           'port' => 3306,
           'name' => 'u123456789_webring',
           'user' => 'u123456789_webring',
           'pass' => 'YOUR_DATABASE_PASSWORD',
           'charset' => 'utf8mb4',
           'auto_migrate' => true,
       ],

       // Generate a random token (e.g. `openssl rand -hex 32`) to enable /admin and /api/admin/*
       'admin_token' => 'YOUR_SECRET_ADMIN_TOKEN',

       // Hostinger runs behind a reverse proxy / CDN
       'trust_proxy' => true,

       'instance' => [
           'title' => 'Indie Webring Hub',
           'description' => 'A curated hub of independent creators and developers.',
           'bannerUrl' => '',
           'hubUrl' => 'https://hub.yourdomain.com', // the older name 'directoryUrl' also works
           'maintainerBadge' => false, // Set to true to display the Maintainer Badge
           'maintainerName' => 'Your Name',
           'maintainerUrl' => 'https://yourdomain.com',
           'maintainerDid' => 'did:web:yourdomain.com',
       ],
   ];
   ```

> **Security Note:** `.htaccess` blocks direct HTTP access to `config.php`, `src/`, `sql/`, `scripts/`, `extensions/`, `docs/` and `data/`. `config.php` is also ignored by Git in `.gitignore`.

---

### 3. Upload Files to Hostinger

For a first install, upload all files in `webring-hub-php/` (including the hidden `.htaccess` file and your new `config.php`) to your site's document root in Hostinger. When you upgrade, don't upload `config.php` at all; see [Upgrading an existing install](#upgrading-an-existing-install).

The document root is:

- For a primary domain: `/domains/yourdomain.com/public_html/`
- For a subdomain (e.g. `hub.yourdomain.com`): `/domains/yourdomain.com/public_html/hub/` (or the subdomain folder configured in hPanel)

Verify in **hPanel → Advanced → PHP Configuration** that your site is running **PHP 8.1 or newer** (PHP 8.2 / 8.3 recommended). The required extensions (`sodium`, `openssl`, `curl`, `pdo_mysql`, `mbstring`) are enabled by default on Hostinger Premium.

---

### 4. Set Up the Background Refresh Cron Job in hPanel

To keep listed profiles fresh and automatically mark unreachable sites as `stale` after 7 consecutive failed checks:

1. In **Hostinger hPanel**, go to **Advanced** → **Cron Jobs**.
2. Select **Type: PHP** (or **Custom**), choose your schedule (e.g. **Twice a day** or **Once a day**), and set the command:
   ```bash
   /usr/bin/php /home/u123456789/domains/yourdomain.com/public_html/scripts/refresh.php
   ```
   *(Replace `u123456789` and `yourdomain.com` with your actual Hostinger home path shown on the Cron Jobs page.)*

---

### Upgrading an existing install

1. **Back up** the database (hPanel → Databases → phpMyAdmin → Export) and your server's `config.php`.
2. **Upload everything except `config.php`**, including `.htaccess`, `admin.js` and `src/templates/admin.html`. Your existing `config.php` keeps working as it is.
3. **Turn on the admin page:** add `'admin_token' => '<long random value>'` to the server's `config.php` (generate one with `openssl rand -hex 32`). Then open `/admin` and sign in.
4. **Optional: turn on key-witness:** set `'extensions' => ['key-witness']` in `config.php`. On the next request it creates its table and records the current key of each listed site as `first-seen`. Check that `/api/info` lists it and that the **Extensions** panel on `/admin` shows it loaded.
5. **Run the smoke test** on the server. It uses a temporary SQLite database and doesn't touch your data:
   - **Over SSH** (included on Premium plans; turn it on under hPanel → Advanced → SSH Access): `cd ~/domains/yourdomain.com/public_html && php scripts/smoke-extensions.php`
   - **Or with a one-off cron job:** add a cron job running `/usr/bin/php /home/u123456789/domains/yourdomain.com/public_html/scripts/smoke-extensions.php`. Read its output under **View output**, then delete the job.
6. **Check** that `/api/healthz` returns `{"ok":true}` and that a search on the public page works.

---

## Configuration reference

`config.example.php` reads these environment variables. Each instance setting reads the new `HUB_*` name first and falls back to the old `DIRECTORY_*` name.

| Setting | Environment variable (fallback) | `config.php` key |
| :--- | :--- | :--- |
| Title | `HUB_TITLE` (`DIRECTORY_TITLE`) | `title` |
| Description | `HUB_DESCRIPTION` (`DIRECTORY_DESCRIPTION`) | `description` |
| Banner image | `HUB_BANNER_URL` (`DIRECTORY_BANNER_URL`) | `banner_url` |
| This hub's canonical URL (enables `/.well-known/did.json`) | `HUB_URL` (`DIRECTORY_URL`) | `hub_url` / `hubUrl` (older: `directory_url` / `directoryUrl`) |
| Maintainer badge on/off | `HUB_MAINTAINER_BADGE` (`DIRECTORY_MAINTAINER_BADGE`) | `maintainer_badge` |
| Maintainer name / URL / DID | `HUB_MAINTAINER_NAME`, `HUB_MAINTAINER_URL`, `HUB_MAINTAINER_DID` (`DIRECTORY_MAINTAINER_*`) | `maintainer_name`, `maintainer_url`, `maintainer_did` |
| Enabled extensions (comma-separated) | `HUB_EXTENSIONS` (`DIRECTORY_EXTENSIONS`) | `extensions` |
| Admin token | `ADMIN_TOKEN` | `admin_token` |
| Database | `DB_DRIVER`, `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`, `DATABASE_PATH` (SQLite) | `db_*` or `'db' => [...]` |

The instance keys can also be nested under `'instance' => [...]` in camelCase, as in the example above. Settings saved on `/admin` (or with `POST /api/admin/settings`) are stored in the `settings` table and override `config.php`.

---

## Admin page (`/admin`)

The hub has an operator page at **`/admin`** (for example `https://hub.yourdomain.com/admin`). It isn't linked from the public page, so bookmark it. It's served with `noindex` and `Cache-Control: no-store`.

1. **Set a token.** Put a long random value in `config.php` as `'admin_token'`, or in the `ADMIN_TOKEN` environment variable. Generate one with `openssl rand -hex 32`. While no token is set, admin access is off: the page says so and every `/api/admin/*` call answers `503 admin_disabled`.
2. **Open `/admin` and sign in** with that token. It's kept only in this browser tab's `sessionStorage` and sent as an `Authorization: Bearer` header. It never goes into a URL, and it's gone when you click **Sign out** or close the tab.

What you can do there:

- **Listings**: every entry, whatever its status, with its handle, DID, status, last verification, failed checks and last error, and key fingerprint (hover for the full SHA-256). Filter by status (All / Active / Blocked / Stale), search by name, handle, site URL or DID, and page through the results. Row actions:
  - **Block** hides the site from the public listing and API, stops re-checks, and refuses re-submission. **Unblock** makes it active again.
  - **Mark stale** hides the site until its next successful refresh (the cron job does this by itself after 7 failed checks). **Mark active** shows it again.
  - **Refresh now** fetches and verifies the site again (`POST /api/entries/:did/refresh`, which counts toward the write rate limit).
  - **Trust new key** accepts the key the site publishes now when it changed without a rotation certificate. **Only do this after confirming the change with the site owner** some other way; otherwise whoever controls the domain now gets the listing.
  - **Delete** removes the entry and any extension data for it. The owner can list it again; block it instead to keep it out.
- **Add a site** verifies and lists a site by URL, like the public form.
- **Re-check due sites** re-verifies up to 25 of the sites checked longest ago (`POST /api/admin/refresh`).
- **Instance settings**: title, description, banner, hub URL and maintainer badge. Only changed fields are saved.
- **Extensions**: every enabled extension, loaded or not, with its version, schema, routes, events and load errors.

Each action shows its result or error next to it.

---

## Customizing Your Hub Instance

You can customize the instance in `config.php`, on `/admin`, or with `POST /api/admin/settings` (persisted in the `settings` table). The API field for the hub's URL keeps its original name, `directoryUrl`:

```bash
curl -X POST https://hub.yourdomain.com/api/admin/settings \
  -H "Authorization: Bearer $ADMIN_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Indie Webring Hub",
    "description": "A verified index of independent creators.",
    "bannerUrl": "https://hub.yourdomain.com/banner.jpg",
    "directoryUrl": "https://hub.yourdomain.com",
    "maintainerBadge": true,
    "maintainerName": "Alice",
    "maintainerUrl": "https://alice.example",
    "maintainerDid": "did:web:alice.example"
  }'
```

When `maintainerBadge` is `true` and the maintainer's site (`maintainerUrl` / `maintainerDid`) is an active verified entry in the hub, the header badge automatically upgrades to **Verified Maintainer** with an emerald indicator.

---

## HTTP API Reference

| Method & Path | Description |
| :--- | :--- |
| `GET /api/info` | Public instance metadata (`title`, `description`, `bannerUrl`, `did`, `maintainerBadge`, `maintainer`, `extensions`) |
| `GET /.well-known/did.json` | Instance W3C `did:web` document (when the hub URL is configured) |
| `GET /api/entries?q=&limit=24&offset=0` | Search or list active verified entries |
| `GET /api/entries/:did` | Fetch a single active entry by DID |
| `POST /api/entries` | Verify and index a site (`{"siteUrl":"https://alice.example"}`) |
| `POST /api/entries/:did/refresh` | Re-crawl and re-verify an existing entry |
| `GET /api/nonce` | Issue a single-use nonce for self-service removal (15-minute TTL) |
| `POST /api/entries/:did/remove` | Remove an entry using a payload signed by the site's Ed25519 private key |
| `GET /admin` | Operator page (see [Admin page](#admin-page-admin)); it calls the endpoints below |
| `GET /api/admin/entries?status=&q=&limit=50&offset=0` | Every entry, any status. `status`: `active`, `blocked` or `stale` (optional); `q`: case-insensitive match on name, handle, site URL or DID; `limit` up to 100. Returns `{ total, entries }`; each entry is the public shape plus `status`, `failCount`, `lastError`, `lastCheckedAt`, `createdAt` and `keyFingerprint` (SHA-256 of the SPKI key) *(Bearer `admin_token`)* |
| `POST /api/admin/settings` | Update instance customization settings *(Bearer `admin_token`)* |
| `POST /api/admin/refresh` | Trigger a batch refresh of due entries (`{"limit":10}`, up to 25) *(Bearer `admin_token`)* |
| `POST /api/admin/entries/:did/status` | Set entry status to `active`, `blocked`, or `stale` *(Bearer `admin_token`)* |
| `POST /api/admin/entries/:did/trust-key` | Re-verify a site and accept its new signing key when it changed without a rotation certificate. Confirm the change with the owner first *(Bearer `admin_token`)* |
| `DELETE /api/admin/entries/:did` | Delete an entry immediately *(Bearer `admin_token`)* |
| `GET /api/admin/extensions` | Enabled extensions: loaded or not, version, schema version, routes, events, load errors *(Bearer `admin_token`)* |
| `GET /api/ext/key-witness/v1/keys/:did` | *(key-witness extension)* The current key fingerprint for an active entry and the history of keys seen (`first-seen`, `rotated`, `operator-accepted`, `rejected`) |
| `GET /api/healthz` | Health check (`{"ok":true}`) |

---

## Extensions

Extensions are optional modules that add features without changing the core. To enable one, put it in `extensions/<id>/` and list it in `config.php`:

```php
'extensions' => ['key-witness'],   // or HUB_EXTENSIONS=key-witness (DIRECTORY_EXTENSIONS also works)
'extension_settings' => [],        // per-extension settings, keyed by id
```

Extensions run inside the hub with full access (PHP can't sandbox them), so only enable code you trust. They must follow the rules in [docs/extensions.md](docs/extensions.md): hints, not truth; data only from verified sites; their own routes and tables; delete a site's data when it leaves; no trackers.

Included:

- **`key-witness`**: records which signing key the hub saw for each listed site, and how any change was accepted. Webring Studio's **Check with Webring Hubs** button compares this with the key Studio pinned for a friend. When it's enabled, existing entries are filled in as `first-seen`.

Run `php scripts/smoke-extensions.php` to check the framework and key-witness against a temporary SQLite database.

## Self-service removal

A listed site can always remove itself. The request must be signed with the site's own Ed25519 key, so nobody else can unlist it. Webring Studio does this when you turn **List my site** off. Without Studio, use `scripts/sign-request.js`. It needs Node.js 18+ and nothing else:

```bash
# Get a nonce from the hub, sign, and print a link that opens the hub's /remove page ready to send
node scripts/sign-request.js --site-url https://you.example --key private-key.pem --dir https://hub.yourdomain.com --link

# Or print a ready-to-run curl command
node scripts/sign-request.js --site-url https://you.example --key private-key.pem --dir https://hub.yourdomain.com --curl
```

| Flag | Meaning |
| :--- | :--- |
| `--site-url` | The listed site (required) |
| `--key` | A PKCS#8 Ed25519 private key PEM file, or `-` to read it from stdin (required) |
| `--dir` | The hub's address. The script fetches a nonce from it, and `--link` needs it |
| `--nonce` | A nonce you already fetched from `GET /api/nonce` (instead of `--dir`) |
| `--did` | The site's DID, if it isn't the `did:web` derived from `--site-url` |
| `--created` | The request time (ISO 8601; defaults to now) |
| `--curl` / `--link` | Print a curl command or a `/remove` link instead of the JSON body |

Nonces are single-use and expire after 15 minutes.

## Webring Studio Integration

Webring Studio (Publish tab → **Webring Hubs**) can add a hub, search it from the Friends tab, list or unlist the persona's site (unlisting uses the same signed `remove` request as `scripts/sign-request.js`), call `POST /api/entries/:did/refresh` after a publish once the new version is live, and, when the hub runs the `key-witness` extension, compare a friend's pinned key with the one the hub has seen. Studio's MCP server offers the same through the `webring_hubs`, `webring_search_hub`, `webring_set_hub_listing` and `webring_check_key_with_hubs` tools. Studio treats hub results as hints only and always verifies sites itself. See [`docs/architecture/webring-hub.md`](https://github.com/lnsCorey/webring-studio/blob/main/docs/architecture/webring-hub.md) in the [Webring Studio repository](https://github.com/lnsCorey/webring-studio).

## Local Development

You can run the PHP port locally with PHP's built-in server (using either MySQL or the built-in SQLite driver):

```bash
DB_DRIVER=sqlite ADMIN_TOKEN=dev-token php -S 127.0.0.1:8080 index.php
```

On Windows PowerShell:

```powershell
$env:DB_DRIVER='sqlite'; $env:ADMIN_TOKEN='dev-token'; $env:HUB_EXTENSIONS='key-witness'; php -S 127.0.0.1:8080 index.php
```

Then open `http://127.0.0.1:8080/` for the public page and `http://127.0.0.1:8080/admin` (token `dev-token`) for the admin page. `http://127.0.0.1:8080/api/healthz` should return `{"ok":true}`. Type that address into a browser, not into the terminal.

**PHP extensions.** The hub needs `sodium`, `openssl`, `curl`, `mbstring`, and either `pdo_mysql` or `pdo_sqlite`. Run `php -m` to check. On Windows (for example PHP installed with winget), edit `php.ini` as follows:
- set `extension_dir` to the absolute path of PHP's `ext` folder;
- uncomment `extension=curl`, `mbstring`, `openssl`, `pdo_mysql`, `pdo_sqlite` and `sodium`;
- set `date.timezone = UTC`;
- point `curl.cainfo` and `openssl.cafile` at a downloaded [`cacert.pem`](https://curl.se/ca/cacert.pem), so HTTPS requests can be verified.

Then run `php scripts/smoke-extensions.php`.

**Testing with a local Webring Studio.** By default, the hub and Studio both refuse `http://` and local-network addresses. To list sites served from your own machine, set `ALLOW_INSECURE=1` and `ALLOW_PRIVATE_HOSTS=1` for the hub. To let Studio use a hub on `127.0.0.1`, start Studio with `WEBRING_ALLOW_PRIVATE_PEERS=1`. Keep all of these off in production.
