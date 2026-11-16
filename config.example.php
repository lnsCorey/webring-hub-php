<?php
declare(strict_types=1);

use WebringHub\Config;

/**
 * Webring Hub (PHP / MySQL Edition for Hostinger Premium)
 *
 * Copy this file to `config.php` and fill in your Hostinger MySQL credentials
 * from hPanel -> Databases -> Management.
 *
 * Every setting below can also be provided via environment variables. The instance settings read
 * the HUB_* variables first and fall back to the older DIRECTORY_* names, so existing installs
 * keep working.
 */
return [
    // Database connection: 'mysql' (recommended for Hostinger) or 'sqlite' (for local testing)
    'db_driver' => getenv('DB_DRIVER') ?: 'mysql',

    // Hostinger MySQL settings (hPanel -> Databases -> MySQL Databases)
    'db_host' => getenv('DB_HOST') ?: '127.0.0.1',
    'db_port' => (int) (getenv('DB_PORT') ?: 3306),
    'db_name' => getenv('DB_NAME') ?: 'u123456789_webring',
    'db_user' => getenv('DB_USER') ?: 'u123456789_webring',
    'db_pass' => getenv('DB_PASS') ?: 'change-me',

    // Optional SQLite path (only used when db_driver === 'sqlite')
    'sqlite_path' => getenv('DATABASE_PATH') ?: __DIR__ . '/data/directory.sqlite',

    // Automatically create tables on first request if they do not exist yet
    'auto_migrate' => true,

    // Long random token for /admin and the /api/admin/* endpoints (leave empty to disable admin)
    // Generate one with: openssl rand -hex 32
    'admin_token' => getenv('ADMIN_TOKEN') ?: '',

    // Reverse proxy / rate limiting
    'trust_proxy' => (getenv('TRUST_PROXY') ?: '0') === '1',
    'trusted_proxy_hops' => max(1, (int) (getenv('TRUSTED_PROXY_HOPS') ?: 1)),
    'rate_limit_enabled' => (getenv('RATE_LIMIT') ?: '1') !== '0',
    'write_rate_limit_window_seconds' => 3600,
    'write_rate_limit_max' => 30,
    'global_crawl_rate_limit_window_seconds' => 60,
    'global_crawl_rate_limit_max' => (int) (getenv('GLOBAL_CRAWL_RATE_LIMIT_MAX') ?: 20),

    // Remote site verification guards (keep false in production!)
    'allow_insecure' => (getenv('ALLOW_INSECURE') ?: '0') === '1',
    'allow_private' => (getenv('ALLOW_PRIVATE_HOSTS') ?: '0') === '1',
    'refresh_timeout_ms' => (int) (getenv('REFRESH_TIMEOUT_MS') ?: 8000),

    // Instance Customization (can also be updated live on /admin or via POST /api/admin/settings)
    'title' => Config::env('HUB_TITLE', 'DIRECTORY_TITLE') ?: 'Webring Hub',
    'description' => Config::env('HUB_DESCRIPTION', 'DIRECTORY_DESCRIPTION') ?: 'A searchable, opt-in index of Webring Studio profiles. Every entry is verified against the Ed25519 key published by the site itself.',
    'banner_url' => Config::env('HUB_BANNER_URL', 'DIRECTORY_BANNER_URL'),

    // Optional canonical URL of this hub (enables /.well-known/did.json).
    // The older key name 'directory_url' is still accepted.
    'hub_url' => Config::env('HUB_URL', 'DIRECTORY_URL'),

    // Optional Maintainer Badge (defaults to off / false)
    'maintainer_badge' => in_array(strtolower(Config::env('HUB_MAINTAINER_BADGE', 'DIRECTORY_MAINTAINER_BADGE')), ['1', 'true', 'yes', 'on'], true),
    'maintainer_name' => Config::env('HUB_MAINTAINER_NAME', 'DIRECTORY_MAINTAINER_NAME'),
    'maintainer_url' => Config::env('HUB_MAINTAINER_URL', 'DIRECTORY_MAINTAINER_URL'),
    'maintainer_did' => Config::env('HUB_MAINTAINER_DID', 'DIRECTORY_MAINTAINER_DID'),

    // Extensions (see docs/extensions.md). Each one lives in extensions/<id>/ and runs only when
    // its id is listed here. Extensions run inside the hub with full access, so only enable
    // code you trust. Example: ['key-witness']
    'extensions' => array_values(array_filter(array_map('trim', explode(',', Config::env('HUB_EXTENSIONS', 'DIRECTORY_EXTENSIONS'))))),

    // Per-extension settings, keyed by extension id.
    'extension_settings' => [],
];
