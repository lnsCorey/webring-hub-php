<?php
declare(strict_types=1);

namespace WebringHub;

use PDO;

require_once __DIR__ . '/PublicSuffix.php';

/**
 * PDO database layer for Webring Hub (MySQL / MariaDB for Hostinger Premium,
 * with SQLite fallback support).
 * Mirrors `webring-directory/src/db.js`.
 */
final class Database
{
    /**
     * `directoryUrl` is this hub's own canonical URL. The key keeps its original name because it
     * is also the `settings` row key and the field `POST /api/admin/settings` accepts.
     */
    public const DEFAULT_INSTANCE_META = [
        'title' => 'Webring Hub',
        'description' => 'A searchable, opt-in index of Webring Studio profiles. Every entry is verified against the Ed25519 key published by the site itself.',
        'bannerUrl' => '',
        'directoryUrl' => '',
        'maintainerBadge' => false,
        'maintainerName' => '',
        'maintainerUrl' => '',
        'maintainerDid' => '',
    ];

    private const META_SETTING_KEYS = [
        'title',
        'description',
        'bannerUrl',
        'directoryUrl',
        'maintainerBadge',
        'maintainerName',
        'maintainerUrl',
        'maintainerDid',
    ];

    /**
     * Opens a PDO connection using the resolved configuration and optionally runs schema migrations.
     *
     * @param array<string, mixed> $config
     */
    public static function open(array $config): PDO
    {
        $dbCfg = is_array($config['db'] ?? null) ? $config['db'] : [];
        $driver = strtolower((string) ($dbCfg['driver'] ?? $config['db_driver'] ?? 'mysql'));

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        if ($driver === 'sqlite') {
            $sqlitePath = (string) ($dbCfg['sqlite_path'] ?? $config['sqlite_path'] ?? (__DIR__ . '/../data/directory.sqlite'));
            if ($sqlitePath !== ':memory:') {
                $dir = dirname($sqlitePath);
                if (!is_dir($dir)) {
                    @mkdir($dir, 0750, true);
                }
            }
            $pdo = new PDO('sqlite:' . $sqlitePath, null, null, $options);
            $pdo->exec('PRAGMA journal_mode = WAL');
            $pdo->exec('PRAGMA synchronous = NORMAL');
            $pdo->exec('PRAGMA busy_timeout = 5000');
        } else {
            $host = (string) ($dbCfg['host'] ?? $config['db_host'] ?? '127.0.0.1');
            $port = (int) ($dbCfg['port'] ?? $config['db_port'] ?? 3306);
            $name = (string) ($dbCfg['name'] ?? $config['db_name'] ?? '');
            $user = (string) ($dbCfg['user'] ?? $config['db_user'] ?? '');
            $pass = (string) ($dbCfg['pass'] ?? $config['db_pass'] ?? '');
            $charset = (string) ($dbCfg['charset'] ?? $config['db_charset'] ?? 'utf8mb4');
            $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";
            try {
                $pdo = new PDO($dsn, $user, $pass, $options);
            } catch (\PDOException $e) {
                $fallbackHost = $host === '127.0.0.1' ? 'localhost' : ($host === 'localhost' ? '127.0.0.1' : '');
                if ($fallbackHost !== '') {
                    $fallbackDsn = "mysql:host={$fallbackHost};port={$port};dbname={$name};charset={$charset}";
                    $pdo = new PDO($fallbackDsn, $user, $pass, $options);
                } else {
                    throw $e;
                }
            }
        }

        self::configureIpHashSalt((string) ($config['ip_hash_salt'] ?? ''));

        $autoMigrate = (bool) ($dbCfg['auto_migrate'] ?? $config['auto_migrate'] ?? true);
        if ($autoMigrate) {
            self::migrate($pdo);
        }

        return $pdo;
    }

    /**
     * Ensures all required tables exist.
     */
    public static function migrate(PDO $pdo): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS entries (
                  rowid INTEGER PRIMARY KEY AUTOINCREMENT,
                  id TEXT NOT NULL UNIQUE,
                  site_url TEXT NOT NULL UNIQUE,
                  did TEXT NOT NULL UNIQUE,
                  handle TEXT NOT NULL DEFAULT '',
                  display_name TEXT NOT NULL,
                  tagline TEXT NOT NULL DEFAULT '',
                  bio TEXT NOT NULL DEFAULT '',
                  location TEXT NOT NULL DEFAULT '',
                  avatar_url TEXT NOT NULL DEFAULT '',
                  banner_url TEXT NOT NULL DEFAULT '',
                  interests_json TEXT NOT NULL DEFAULT '[]',
                  interests_text TEXT NOT NULL DEFAULT '',
                  links_json TEXT NOT NULL DEFAULT '[]',
                  public_key_pem TEXT NOT NULL,
                  profile_updated_at TEXT NOT NULL,
                  verified_at TEXT NOT NULL,
                  last_checked_at TEXT NOT NULL,
                  fail_count INTEGER NOT NULL DEFAULT 0,
                  last_error TEXT,
                  status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('active', 'stale', 'blocked')),
                  created_at TEXT NOT NULL,
                  updated_at TEXT NOT NULL
                );
                CREATE INDEX IF NOT EXISTS idx_entries_status ON entries(status);
                CREATE INDEX IF NOT EXISTS idx_entries_checked ON entries(last_checked_at);
                CREATE TABLE IF NOT EXISTS nonces (
                  nonce TEXT PRIMARY KEY,
                  created_at TEXT NOT NULL,
                  used_at TEXT
                );
                CREATE TABLE IF NOT EXISTS settings (
                  `key` TEXT PRIMARY KEY,
                  `value` TEXT NOT NULL,
                  updated_at TEXT NOT NULL
                );
                CREATE TABLE IF NOT EXISTS rate_limits (
                  ip_key TEXT PRIMARY KEY,
                  window_start INTEGER NOT NULL,
                  hits INTEGER NOT NULL DEFAULT 1
                );
                CREATE TABLE IF NOT EXISTS schema_migrations (
                  id TEXT PRIMARY KEY,
                  applied_at TEXT NOT NULL
                );
                CREATE TABLE IF NOT EXISTS key_candidates (
                  did TEXT NOT NULL PRIMARY KEY,
                  public_key_pem TEXT NOT NULL,
                  fingerprint TEXT NOT NULL,
                  first_seen_at TEXT NOT NULL,
                  last_seen_at TEXT NOT NULL,
                  conflict INTEGER NOT NULL DEFAULT 0
                );
            ");
            self::runMigrations($pdo, $driver);
            return;
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS entries (
              rowid BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              id VARCHAR(255) NOT NULL,
              site_url VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
              did VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
              handle VARCHAR(100) NOT NULL DEFAULT '',
              display_name VARCHAR(200) NOT NULL,
              tagline VARCHAR(400) NOT NULL DEFAULT '',
              bio TEXT NOT NULL,
              location VARCHAR(200) NOT NULL DEFAULT '',
              avatar_url TEXT NOT NULL,
              banner_url TEXT NOT NULL,
              interests_json MEDIUMTEXT NOT NULL,
              interests_text TEXT NOT NULL,
              links_json MEDIUMTEXT NOT NULL,
              public_key_pem TEXT NOT NULL,
              profile_updated_at VARCHAR(40) NOT NULL,
              verified_at VARCHAR(40) NOT NULL,
              last_checked_at VARCHAR(40) NOT NULL,
              fail_count INT NOT NULL DEFAULT 0,
              last_error VARCHAR(500) DEFAULT NULL,
              status ENUM('active', 'stale', 'blocked') NOT NULL DEFAULT 'active',
              created_at VARCHAR(40) NOT NULL,
              updated_at VARCHAR(40) NOT NULL,
              PRIMARY KEY (rowid),
              UNIQUE KEY uq_entries_id (id),
              UNIQUE KEY uq_entries_site_url (site_url),
              UNIQUE KEY uq_entries_did (did),
              KEY idx_entries_status (status),
              KEY idx_entries_checked (last_checked_at),
              FULLTEXT KEY fts_entries (display_name, handle, tagline, bio, location, interests_text)
            ) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS nonces (
              nonce VARCHAR(64) NOT NULL,
              created_at VARCHAR(40) NOT NULL,
              used_at VARCHAR(40) DEFAULT NULL,
              PRIMARY KEY (nonce),
              KEY idx_nonces_created (created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
              `key` VARCHAR(100) NOT NULL,
              `value` MEDIUMTEXT NOT NULL,
              updated_at VARCHAR(40) NOT NULL,
              PRIMARY KEY (`key`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS rate_limits (
              ip_key VARCHAR(128) NOT NULL,
              window_start BIGINT NOT NULL,
              hits INT NOT NULL DEFAULT 1,
              PRIMARY KEY (ip_key)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS schema_migrations (
              id VARCHAR(100) NOT NULL,
              applied_at VARCHAR(40) NOT NULL,
              PRIMARY KEY (id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS key_candidates (
              did VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
              public_key_pem TEXT NOT NULL,
              fingerprint CHAR(64) NOT NULL,
              first_seen_at VARCHAR(40) NOT NULL,
              last_seen_at VARCHAR(40) NOT NULL,
              conflict TINYINT(1) NOT NULL DEFAULT 0,
              PRIMARY KEY (did)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        self::runMigrations($pdo, $driver);
    }

    /** Setting-key prefix under which a failed migration's error is kept for the admin page. */
    private const MIGRATION_ERROR_PREFIX = 'migration_error:';

    /** Setting key holding look-alike rows that block the MySQL collation migration. */
    public const COLLATION_CONFLICTS_KEY = 'maintenance:collation_conflicts';

    /**
     * Runs the versioned schema upgrades in order. Each runs once and is recorded in
     * `schema_migrations`. A failure is stored (and shown on the admin page) instead of being
     * swallowed, and is not retried on every request: the operator retries it from the admin page
     * (`POST /api/admin/migrations/retry`) once the cause is fixed.
     */
    public static function runMigrations(PDO $pdo, ?string $driver = null, bool $retryFailed = false): void
    {
        $driver ??= (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $applied = [];
        foreach ($pdo->query('SELECT id FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $applied[(string) $id] = true;
        }
        $failed = [];
        $stmt = $pdo->prepare('SELECT `key` FROM settings WHERE `key` LIKE ?');
        $stmt->execute([self::MIGRATION_ERROR_PREFIX . '%']);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $key) {
            $failed[substr((string) $key, strlen(self::MIGRATION_ERROR_PREFIX))] = true;
        }

        foreach (self::migrations($driver) as $id => $up) {
            if (isset($applied[$id]) || (isset($failed[$id]) && !$retryFailed)) {
                continue;
            }
            self::runMigration($pdo, $id, $up);
        }
    }

    /**
     * Runs one migration and records the outcome. Returns true when it is applied.
     *
     * @param callable(PDO): void $up
     */
    public static function runMigration(PDO $pdo, string $id, callable $up): bool
    {
        $done = $pdo->prepare('SELECT 1 FROM schema_migrations WHERE id = ?');
        $done->execute([$id]);
        if ($done->fetchColumn() !== false) {
            return true;
        }
        try {
            $up($pdo);
            $ins = $pdo->prepare('INSERT INTO schema_migrations (id, applied_at) VALUES (?, ?)');
            $ins->execute([$id, self::isoNow()]);
            $clear = $pdo->prepare('DELETE FROM settings WHERE `key` = ?');
            $clear->execute([self::MIGRATION_ERROR_PREFIX . $id]);
            return true;
        } catch (\Throwable $err) {
            error_log("[webring-hub-php] migration {$id} failed: " . $err->getMessage());
            self::upsertSetting($pdo, self::MIGRATION_ERROR_PREFIX . $id, self::cleanSingleLine($err->getMessage(), 500) ?: 'Unknown error', self::isoNow());
            return false;
        }
    }

    /**
     * The ordered schema upgrades. Every step checks the current schema first, so it is safe on
     * installs that were created from `sql/schema.mysql.sql` or already have the change.
     *
     * @return array<string, callable(PDO): void>
     */
    private static function migrations(string $driver): array
    {
        $isSqlite = $driver === 'sqlite';
        $steps = [];

        if (!$isSqlite) {
            // Installs that imported an early schema.mysql.sql used different column names.
            $steps['2026_10_legacy_column_names'] = static function (PDO $pdo): void {
                $cols = $pdo->query("SHOW COLUMNS FROM settings LIKE 'setting_key'")->fetchAll();
                if (count($cols) > 0) {
                    $pdo->exec("ALTER TABLE settings CHANGE COLUMN setting_key `key` VARCHAR(100) NOT NULL, CHANGE COLUMN setting_value `value` MEDIUMTEXT NOT NULL");
                }
                $rlCols = $pdo->query("SHOW COLUMNS FROM rate_limits LIKE 'hit_count'")->fetchAll();
                if (count($rlCols) > 0) {
                    $pdo->exec("DROP TABLE rate_limits");
                    $pdo->exec("
                        CREATE TABLE rate_limits (
                          ip_key VARCHAR(128) NOT NULL,
                          window_start BIGINT NOT NULL,
                          hits INT NOT NULL DEFAULT 1,
                          PRIMARY KEY (ip_key)
                        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                    ");
                }
            };
        }

        // Phase 4 kept candidate keys in `settings`, inside a transaction that was rolled back, so
        // most were never saved. Move any that were into the dedicated table.
        $steps['2026_11_key_candidates_table'] = static function (PDO $pdo): void {
            $stmt = $pdo->prepare('SELECT `key`, `value`, updated_at FROM settings WHERE `key` LIKE ?');
            $stmt->execute(['candidate_key:%']);
            foreach ($stmt->fetchAll() as $row) {
                $did = substr((string) $row['key'], strlen('candidate_key:'));
                $pem = (string) $row['value'];
                if ($did !== '' && Verify::spkiFingerprint($pem) !== '') {
                    self::recordCandidateKey($pdo, $did, $pem, (string) $row['updated_at']);
                }
            }
            $del = $pdo->prepare('DELETE FROM settings WHERE `key` LIKE ?');
            $del->execute(['candidate_key:%']);
        };

        // Indexed registrable domain for the per-domain listing cap (filled in from site_url).
        $steps['2026_11_registrable_domain'] = static function (PDO $pdo) use ($isSqlite): void {
            if (!self::columnExists($pdo, 'entries', 'registrable_domain')) {
                $pdo->exec($isSqlite
                    ? "ALTER TABLE entries ADD COLUMN registrable_domain TEXT NOT NULL DEFAULT ''"
                    : "ALTER TABLE entries ADD COLUMN registrable_domain VARCHAR(255) NOT NULL DEFAULT '' AFTER did");
                $pdo->exec($isSqlite
                    ? 'CREATE INDEX IF NOT EXISTS idx_entries_domain ON entries(registrable_domain)'
                    : 'ALTER TABLE entries ADD KEY idx_entries_domain (registrable_domain)');
            }
            self::backfillRegistrableDomains($pdo);
        };

        if (!$isSqlite) {
            // site_url and did must compare byte for byte, so `Example.com` and `example.com`
            // (or accented look-alikes) can't be two listings or match each other. Tables created
            // before phase 4 used the case- and accent-insensitive default collation.
            $steps['2026_11_collation_bin'] = static function (PDO $pdo): void {
                $conflicts = [];
                foreach (['site_url', 'did'] as $col) {
                    $rows = $pdo->query("
                        SELECT GROUP_CONCAT({$col} SEPARATOR '\n') AS vals, COUNT(*) AS n
                        FROM entries
                        GROUP BY {$col} COLLATE utf8mb4_unicode_ci
                        HAVING COUNT(*) > 1
                    ")->fetchAll();
                    foreach ($rows as $row) {
                        $conflicts[] = ['column' => $col, 'values' => explode("\n", (string) $row['vals'])];
                    }
                }
                if (count($conflicts) > 0) {
                    self::upsertSetting($pdo, self::COLLATION_CONFLICTS_KEY, (string) json_encode($conflicts, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), self::isoNow());
                    throw new \RuntimeException(count($conflicts) . ' group(s) of listings differ only by letter case or accents. Remove the duplicates, then retry.');
                }
                $pdo->exec("
                    ALTER TABLE entries
                      MODIFY site_url VARCHAR(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL,
                      MODIFY did VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL
                ");
                $clear = $pdo->prepare('DELETE FROM settings WHERE `key` = ?');
                $clear->execute([self::COLLATION_CONFLICTS_KEY]);
            };
        }

        return $steps;
    }

    private static function columnExists(PDO $pdo, string $table, string $column): bool
    {
        if ((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite') {
            foreach ($pdo->query("PRAGMA table_info({$table})")->fetchAll() as $col) {
                if (($col['name'] ?? '') === $column) {
                    return true;
                }
            }
            return false;
        }
        $stmt = $pdo->prepare("SHOW COLUMNS FROM {$table} LIKE ?");
        $stmt->execute([$column]);
        return count($stmt->fetchAll()) > 0;
    }

    /** Fills `registrable_domain` for rows that don't have it yet. Returns how many were set. */
    public static function backfillRegistrableDomains(PDO $pdo): int
    {
        $rows = $pdo->query("SELECT rowid, site_url FROM entries WHERE registrable_domain = ''")->fetchAll();
        $upd = $pdo->prepare('UPDATE entries SET registrable_domain = ? WHERE rowid = ?');
        foreach ($rows as $row) {
            $upd->execute([self::registrableDomain((string) $row['site_url']), $row['rowid']]);
        }
        return count($rows);
    }

    /**
     * Operator-facing health notes for the admin page: failed migrations and the look-alike rows
     * that block the collation migration.
     *
     * @return array{migrationErrors: list<array{id: string, message: string}>, collationConflicts: list<array{column: string, values: list<string>}>, pendingMigrations: list<string>}
     */
    public static function maintenanceStatus(PDO $pdo): array
    {
        $errors = [];
        $stmt = $pdo->prepare('SELECT `key`, `value` FROM settings WHERE `key` LIKE ? ORDER BY `key`');
        $stmt->execute([self::MIGRATION_ERROR_PREFIX . '%']);
        foreach ($stmt->fetchAll() as $row) {
            $errors[] = [
                'id' => substr((string) $row['key'], strlen(self::MIGRATION_ERROR_PREFIX)),
                'message' => (string) $row['value'],
            ];
        }
        $conflicts = [];
        $c = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = ?');
        $c->execute([self::COLLATION_CONFLICTS_KEY]);
        $raw = $c->fetchColumn();
        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $group) {
                    if (is_array($group) && is_array($group['values'] ?? null)) {
                        $conflicts[] = [
                            'column' => (string) ($group['column'] ?? ''),
                            'values' => array_values(array_map('strval', $group['values'])),
                        ];
                    }
                }
            }
        }
        // Upgrades that have neither run nor failed: normal while auto_migrate is off after a deploy,
        // and the code that needs them (e.g. registrable_domain) fails until they run.
        try {
            $applied = array_flip(array_map('strval', $pdo->query('SELECT id FROM schema_migrations')->fetchAll(PDO::FETCH_COLUMN)));
        } catch (\Throwable) {
            $applied = []; // the table itself doesn't exist yet: every upgrade is pending
        }
        $failedIds = array_flip(array_column($errors, 'id'));
        $pending = [];
        foreach (array_keys(self::migrations((string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME))) as $id) {
            if (!isset($applied[$id]) && !isset($failedIds[$id])) {
                $pending[] = $id;
            }
        }
        return ['migrationErrors' => $errors, 'collationConflicts' => $conflicts, 'pendingMigrations' => $pending];
    }

    private static function substrLen(string $str, int $max): string
    {
        return function_exists('mb_substr') ? mb_substr($str, 0, $max, 'UTF-8') : substr($str, 0, $max);
    }

    public static function cleanSingleLine(mixed $value, int $max): string
    {
        if (!is_string($value)) {
            return '';
        }
        $cleaned = preg_replace('/[\x00-\x1F\x7F]/u', ' ', $value) ?? '';
        $cleaned = preg_replace('/\s+/u', ' ', $cleaned) ?? '';
        return self::substrLen(trim($cleaned), $max);
    }

    public static function cleanMultiline(mixed $value, int $max): string
    {
        if (!is_string($value)) {
            return '';
        }
        $cleaned = str_replace("\r\n", "\n", $value);
        $cleaned = preg_replace('/[\x00-\x09\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $cleaned) ?? '';
        $cleaned = preg_replace('/\n{3,}/u', "\n\n", $cleaned) ?? '';
        return self::substrLen(trim($cleaned), $max);
    }

    public static function cleanUrl(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $trimmed = trim($value);
        if ($trimmed === '') {
            return '';
        }
        $parts = parse_url($trimmed);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $scheme = strtolower($parts['scheme']);
        return ($scheme === 'https' || $scheme === 'http') ? $trimmed : '';
    }

    public static function cleanBannerUrl(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $trimmed = trim($value);
        if ($trimmed === '' || strlen($trimmed) > 2048 || preg_match('/[\x00-\x1F\x7F]/', $trimmed)) {
            return '';
        }
        if (str_starts_with($trimmed, '/') && !str_starts_with($trimmed, '//')) {
            return $trimmed;
        }
        return self::cleanUrl($trimmed);
    }

    public static function cleanDid(mixed $value): string
    {
        if (!is_string($value)) {
            return '';
        }
        $trimmed = trim($value);
        if ($trimmed === '' || strlen($trimmed) > 256) {
            return '';
        }
        return preg_match('/^did:[a-z0-9]+:[A-Za-z0-9._:%-]+$/', $trimmed) ? $trimmed : '';
    }

    public static function parseBooleanFlag(mixed $value, bool $fallback = false): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            $lower = strtolower(trim($value));
            if (in_array($lower, ['1', 'true', 'yes', 'on'], true)) {
                return true;
            }
            if (in_array($lower, ['0', 'false', 'no', 'off', ''], true)) {
                return false;
            }
        }
        return $fallback;
    }

    /**
     * @return list<string>
     */
    private static function cleanStringArray(mixed $value, int $limit, int $maxLength): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($value as $item) {
            $text = self::cleanSingleLine($item, $maxLength);
            if ($text === '' || isset($seen[$text])) {
                continue;
            }
            $seen[$text] = true;
            $out[] = $text;
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * @return list<array{label: string, url: string}>
     */
    private static function cleanLinks(mixed $value, int $limit): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            if (!is_array($item)) {
                continue;
            }
            $url = self::cleanUrl($item['url'] ?? '');
            if ($url === '') {
                continue;
            }
            $host = (string) (parse_url($url, PHP_URL_HOST) ?: $url);
            $label = self::cleanSingleLine($item['label'] ?? '', 80) ?: $host;
            $out[] = ['label' => $label, 'url' => $url];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $site
     * @return array<string, mixed>
     */
    private static function entryFields(array $site): array
    {
        $profile = is_array($site['profile'] ?? null) ? $site['profile'] : [];
        $siteUrl = (string) ($site['siteUrl'] ?? '');
        $host = (string) (parse_url($siteUrl, PHP_URL_HOST) ?: $siteUrl);
        $interests = self::cleanStringArray($profile['interests'] ?? [], 50, 80);
        $handle = self::cleanSingleLine($profile['handle'] ?? '', 100);
        $displayName = self::cleanSingleLine($profile['displayName'] ?? '', 200) ?: $handle ?: $host;

        return [
            'id' => (string) $site['did'],
            'siteUrl' => $siteUrl,
            'did' => (string) $site['did'],
            'handle' => $handle,
            'displayName' => $displayName,
            'tagline' => self::cleanSingleLine($profile['tagline'] ?? '', 400),
            'bio' => self::cleanMultiline($profile['bio'] ?? '', 8000),
            'location' => self::cleanSingleLine($profile['location'] ?? '', 200),
            'avatarUrl' => self::cleanUrl($profile['avatarUrl'] ?? ''),
            'bannerUrl' => self::cleanUrl($profile['bannerUrl'] ?? ''),
            'interests' => $interests,
            'interestsText' => implode(' ', $interests),
            'links' => self::cleanLinks($profile['customLinks'] ?? [], 50),
            'publicKeyPem' => (string) ($site['publicKeyPem'] ?? ''),
            'profileUpdatedAt' => self::cleanSingleLine($profile['updatedAt'] ?? '', 100)
                ?: (string) ($site['fetchedAt'] ?? self::isoNow()),
        ];
    }

    public static function isoNow(): string
    {
        return gmdate('Y-m-d\TH:i:s.000\Z');
    }

    /**
     * Inserts or refreshes the indexed copy of a verified site.
     *
     * The site's signing key is pinned on first listing (trust on first use, as in Webring Studio).
     * A later, different key is accepted only through a verified rotation chain from the pinned
     * key (`keyRotations` in did.json), or when the operator explicitly accepts it
     * (`$acceptKeyChange`, from `POST /api/admin/entries/:did/trust-key`). Otherwise this throws
     * `key_changed` and the listing keeps the old key, so whoever takes over a domain can't take
     * over its listing or its signed self-removal.
     *
     * @param array<string, mixed> $site
     * @return array{inserted: bool, keyRotated: bool, keyAcceptedBy: 'chain'|'operator'|null, previousKeyPem: string|null}
     */
    public static function upsertEntry(PDO $pdo, array $site, ?string $now = null, bool $acceptKeyChange = false): array
    {
        $now ??= self::isoNow();
        $fields = self::entryFields($site);
        $registrableDomain = self::registrableDomain($fields['siteUrl']);
        // A refused key is recorded only after the transaction is rolled back (below); writing it
        // inside the transaction would undo it along with everything else.
        $pendingCandidatePem = null;

        $pdo->beginTransaction();
        try {
            $stmtDid = $pdo->prepare('SELECT rowid, public_key_pem FROM entries WHERE did = ?');
            $stmtDid->execute([$fields['did']]);
            $byDid = $stmtDid->fetch();

            $keyRotated = false;
            $keyAcceptedBy = null;
            $previousKeyPem = null;
            if ($byDid) {
                $pinned = trim((string) $byDid['public_key_pem']);
                $presented = trim($fields['publicKeyPem']);
                $pinnedSpki = Verify::spkiFingerprint($pinned);
                $presentedSpki = Verify::spkiFingerprint($presented);
                $keysDiffer = $pinned !== '' && (
                    ($pinnedSpki !== '' && $presentedSpki !== '')
                        ? $pinnedSpki !== $presentedSpki
                        : $pinned !== $presented
                );
                if ($keysDiffer) {
                    $certificates = is_array($site['keyRotations'] ?? null) ? $site['keyRotations'] : [];
                    $chainOk = Verify::verifyKeyRotationChain(
                        $pinned,
                        $presented,
                        $fields['did'],
                        $fields['siteUrl'],
                        $certificates
                    );
                    if (!$chainOk && !$acceptKeyChange) {
                        $pendingCandidatePem = $presented;
                        throw new AppError(
                            409,
                            'key_changed',
                            'The site now publishes a different signing key without a rotation certificate from the '
                            . 'key this hub pinned. The listing keeps the old key until the operator accepts the new one.'
                        );
                    }
                    $keyRotated = true;
                    $keyAcceptedBy = $chainOk ? 'chain' : 'operator';
                    $previousKeyPem = $pinned;
                }
                // The site serves the pinned key again (or a chain/operator accepted a new one), so
                // any candidate under review no longer applies.
                self::clearCandidateKey($pdo, $fields['did']);
            }

            $stmtSite = $pdo->prepare('SELECT rowid FROM entries WHERE site_url = ?');
            $stmtSite->execute([$fields['siteUrl']]);
            $bySite = $stmtSite->fetch();

            if ($bySite && (!$byDid || (int) $bySite['rowid'] !== (int) $byDid['rowid'])) {
                throw new AppError(409, 'site_conflict', 'Another entry already owns this site URL.');
            }

            $interestsJson = json_encode($fields['interests'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';
            $linksJson = json_encode($fields['links'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]';

            if ($byDid) {
                $upd = $pdo->prepare("
                    UPDATE entries SET
                      site_url = ?, registrable_domain = ?, handle = ?, display_name = ?, tagline = ?, bio = ?, location = ?,
                      avatar_url = ?, banner_url = ?, interests_json = ?, interests_text = ?, links_json = ?,
                      public_key_pem = ?, profile_updated_at = ?, verified_at = ?, last_checked_at = ?,
                      fail_count = 0, last_error = NULL,
                      status = CASE WHEN status = 'blocked' THEN 'blocked' ELSE 'active' END,
                      updated_at = ?
                    WHERE rowid = ?
                ");
                $upd->execute([
                    $fields['siteUrl'],
                    $registrableDomain,
                    $fields['handle'],
                    $fields['displayName'],
                    $fields['tagline'],
                    $fields['bio'],
                    $fields['location'],
                    $fields['avatarUrl'],
                    $fields['bannerUrl'],
                    $interestsJson,
                    $fields['interestsText'],
                    $linksJson,
                    $fields['publicKeyPem'],
                    $fields['profileUpdatedAt'],
                    $now,
                    $now,
                    $now,
                    $byDid['rowid'],
                ]);
                $pdo->commit();
                return ['inserted' => false, 'keyRotated' => $keyRotated, 'keyAcceptedBy' => $keyAcceptedBy, 'previousKeyPem' => $previousKeyPem];
            }

            $ins = $pdo->prepare("
                INSERT INTO entries (
                  id, site_url, did, registrable_domain, handle, display_name, tagline, bio, location, avatar_url, banner_url,
                  interests_json, interests_text, links_json, public_key_pem, profile_updated_at,
                  verified_at, last_checked_at, fail_count, last_error, status, created_at, updated_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NULL, 'active', ?, ?)
            ");
            $ins->execute([
                $fields['id'],
                $fields['siteUrl'],
                $fields['did'],
                $registrableDomain,
                $fields['handle'],
                $fields['displayName'],
                $fields['tagline'],
                $fields['bio'],
                $fields['location'],
                $fields['avatarUrl'],
                $fields['bannerUrl'],
                $interestsJson,
                $fields['interestsText'],
                $linksJson,
                $fields['publicKeyPem'],
                $fields['profileUpdatedAt'],
                $now,
                $now,
                $now,
                $now,
            ]);
            $pdo->commit();
            return ['inserted' => true, 'keyRotated' => false, 'keyAcceptedBy' => null, 'previousKeyPem' => null];
        } catch (\Throwable $err) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($pendingCandidatePem !== null && $err instanceof AppError && $err->errorCode === 'key_changed') {
                self::recordCandidateKey($pdo, $fields['did'], $pendingCandidatePem, $now);
            }
            throw $err;
        }
    }

    /**
     * Records a check that failed without counting it toward marking the entry stale. Used for
     * refreshes anyone can trigger, so a visitor can't push a briefly unreachable site to stale;
     * `last_checked_at` still moves forward, which starts the refresh cooldown.
     *
     * @param array<string, mixed> $row
     */
    public static function recordCheckAttempt(PDO $pdo, array $row, string $message, ?string $now = null): void
    {
        $now ??= self::isoNow();
        $error = self::cleanSingleLine($message, 500) ?: 'Unknown error';
        $stmt = $pdo->prepare('UPDATE entries SET last_checked_at = ?, last_error = ? WHERE rowid = ?');
        $stmt->execute([$now, $error, $row['rowid']]);
    }

    /**
     * Records a failed crawl and hides the entry after a week of failures.
     *
     * @param array<string, mixed> $row
     */
    public static function recordFailure(PDO $pdo, array $row, string $message, ?string $now = null): void
    {
        $now ??= self::isoNow();
        $error = self::cleanSingleLine($message, 500) ?: 'Unknown error';
        $stmt = $pdo->prepare("
            UPDATE entries SET
              fail_count = fail_count + 1,
              last_checked_at = ?,
              last_error = ?,
              status = CASE
                WHEN status = 'blocked' THEN 'blocked'
                WHEN fail_count + 1 >= 7 THEN 'stale'
                ELSE status
              END
            WHERE rowid = ?
        ");
        $stmt->execute([$now, $error, $row['rowid']]);
    }

    /**
     * @return list<string>
     */
    private static function extractSearchTokens(string $rawQuery): array
    {
        if (!preg_match_all('/[\p{L}\p{N}]+/u', strtolower($rawQuery), $matches)) {
            return [];
        }
        return array_slice($matches[0], 0, 8);
    }

    /**
     * @return array{total: int, entries: list<array<string, mixed>>}
     */
    private static function searchLike(PDO $pdo, string $q, int $limit, int $offset): array
    {
        // '!' as the escape character reads the same in MySQL and SQLite ('\\' is one character
        // in MySQL but two in SQLite, which rejects it).
        $escaped = preg_replace('/([!%_])/', '!$1', $q) ?? $q;
        $like = "%{$escaped}%";
        $where = "status = 'active' AND (
            display_name LIKE ? ESCAPE '!'
            OR handle LIKE ? ESCAPE '!'
            OR tagline LIKE ? ESCAPE '!'
            OR bio LIKE ? ESCAPE '!'
            OR location LIKE ? ESCAPE '!'
            OR interests_text LIKE ? ESCAPE '!'
        )";
        $args = [$like, $like, $like, $like, $like, $like];

        $countStmt = $pdo->prepare("SELECT COUNT(*) AS n FROM entries WHERE {$where}");
        $countStmt->execute($args);
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $sql = "SELECT * FROM entries WHERE {$where} ORDER BY LOWER(display_name) ASC LIMIT ? OFFSET ?";
        $stmt = $pdo->prepare($sql);
        foreach ($args as $idx => $val) {
            $stmt->bindValue($idx + 1, $val, PDO::PARAM_STR);
        }
        $stmt->bindValue(7, $limit, PDO::PARAM_INT);
        $stmt->bindValue(8, $offset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'total' => $total,
            'entries' => $stmt->fetchAll(),
        ];
    }

    /**
     * Public search over active entries; uses MySQL FULLTEXT (IN BOOLEAN MODE) when eligible,
     * with a seamless LIKE fallback for short (<3 char) or substring queries.
     *
     * @param array{q?: string, limit?: int|string, offset?: int|string} $params
     * @return array{total: int, entries: list<array<string, mixed>>}
     */
    public static function listEntries(PDO $pdo, array $params = []): array
    {
        $rawLimit = isset($params['limit']) ? (int) $params['limit'] : 24;
        $safeLimit = min(max($rawLimit > 0 ? $rawLimit : 24, 1), 100);
        $safeOffset = max(isset($params['offset']) ? (int) $params['offset'] : 0, 0);
        $query = trim((string) ($params['q'] ?? ''));

        if ($query === '') {
            $total = (int) ($pdo->query("SELECT COUNT(*) FROM entries WHERE status = 'active'")->fetchColumn() ?: 0);
            $stmt = $pdo->prepare("SELECT * FROM entries WHERE status = 'active' ORDER BY LOWER(display_name) ASC LIMIT ? OFFSET ?");
            $stmt->bindValue(1, $safeLimit, PDO::PARAM_INT);
            $stmt->bindValue(2, $safeOffset, PDO::PARAM_INT);
            $stmt->execute();
            return [
                'total' => $total,
                'entries' => $stmt->fetchAll(),
            ];
        }

        $tokens = self::extractSearchTokens($query);
        if (count($tokens) === 0) {
            return ['total' => 0, 'entries' => []];
        }

        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $allMinThree = true;
        foreach ($tokens as $tok) {
            if (strlen($tok) < 3) {
                $allMinThree = false;
                break;
            }
        }

        if ($driver === 'mysql' && $allMinThree) {
            try {
                $booleanQuery = implode(' ', array_map(static fn(string $t): string => '+' . $t . '*', $tokens));
                $countStmt = $pdo->prepare("
                    SELECT COUNT(*) FROM entries
                    WHERE status = 'active'
                      AND MATCH(display_name, handle, tagline, bio, location, interests_text) AGAINST(? IN BOOLEAN MODE)
                ");
                $countStmt->execute([$booleanQuery]);
                $total = (int) ($countStmt->fetchColumn() ?: 0);

                if ($total > 0) {
                    $stmt = $pdo->prepare("
                        SELECT *, MATCH(display_name, handle, tagline, bio, location, interests_text) AGAINST(? IN BOOLEAN MODE) AS score
                        FROM entries
                        WHERE status = 'active'
                          AND MATCH(display_name, handle, tagline, bio, location, interests_text) AGAINST(? IN BOOLEAN MODE)
                        ORDER BY score DESC, LOWER(display_name) ASC
                        LIMIT ? OFFSET ?
                    ");
                    $stmt->bindValue(1, $booleanQuery, PDO::PARAM_STR);
                    $stmt->bindValue(2, $booleanQuery, PDO::PARAM_STR);
                    $stmt->bindValue(3, $safeLimit, PDO::PARAM_INT);
                    $stmt->bindValue(4, $safeOffset, PDO::PARAM_INT);
                    $stmt->execute();
                    return [
                        'total' => $total,
                        'entries' => $stmt->fetchAll(),
                    ];
                }
            } catch (\Throwable) {
                // Fall back to LIKE search below
            }
        }

        return self::searchLike($pdo, $query, $safeLimit, $safeOffset);
    }

    /**
     * Admin listing: entries of any status, optionally filtered by status and by a simple
     * case-insensitive substring match on display name, handle, site URL or DID.
     *
     * @param array{status?: string, q?: string, limit?: int|string, offset?: int|string} $params
     * @return array{total: int, entries: list<array<string, mixed>>}
     */
    public static function listEntriesForAdmin(PDO $pdo, array $params = []): array
    {
        $rawLimit = isset($params['limit']) ? (int) $params['limit'] : 50;
        $safeLimit = min(max($rawLimit > 0 ? $rawLimit : 50, 1), 100);
        $safeOffset = max(isset($params['offset']) ? (int) $params['offset'] : 0, 0);
        $status = (string) ($params['status'] ?? '');
        $query = self::cleanSingleLine($params['q'] ?? '', 200);

        $conditions = [];
        $args = [];
        if (in_array($status, ['active', 'blocked', 'stale'], true)) {
            $conditions[] = 'status = ?';
            $args[] = $status;
        }
        if ($query !== '') {
            // '!' as the escape character works in both MySQL and SQLite (see searchLike).
            $escaped = preg_replace('/([!%_])/', '!$1', strtolower($query)) ?? strtolower($query);
            $like = "%{$escaped}%";
            $conditions[] = "(
                LOWER(display_name) LIKE ? ESCAPE '!'
                OR LOWER(handle) LIKE ? ESCAPE '!'
                OR LOWER(site_url) LIKE ? ESCAPE '!'
                OR LOWER(did) LIKE ? ESCAPE '!'
            )";
            array_push($args, $like, $like, $like, $like);
        }
        $where = $conditions !== [] ? 'WHERE ' . implode(' AND ', $conditions) : '';

        $countStmt = $pdo->prepare("SELECT COUNT(*) FROM entries {$where}");
        $countStmt->execute($args);
        $total = (int) ($countStmt->fetchColumn() ?: 0);

        $stmt = $pdo->prepare("SELECT * FROM entries {$where} ORDER BY LOWER(display_name) ASC, rowid ASC LIMIT ? OFFSET ?");
        $position = 1;
        foreach ($args as $val) {
            $stmt->bindValue($position++, $val, PDO::PARAM_STR);
        }
        $stmt->bindValue($position++, $safeLimit, PDO::PARAM_INT);
        $stmt->bindValue($position, $safeOffset, PDO::PARAM_INT);
        $stmt->execute();

        return [
            'total' => $total,
            'entries' => $stmt->fetchAll(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getEntryByDid(PDO $pdo, string $did): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM entries WHERE did = ?');
        $stmt->execute([$did]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    /**
     * The registrable domain of a site URL, using the Public Suffix List, so `alice.github.io`
     * and `bob.github.io` are different domains while `a.example.com` and `b.example.com` are
     * the same one. IP literals and single labels are returned as they are.
     */
    public static function registrableDomain(string $siteUrl): string
    {
        $host = strtolower((string) parse_url($siteUrl, PHP_URL_HOST));
        return PublicSuffix::registrableDomain($host);
    }

    /**
     * Counts listed entries (any status) that share $siteUrl's registrable domain.
     */
    public static function countEntriesByRegistrableDomain(PDO $pdo, string $siteUrl, string $excludeDid = ''): int
    {
        $target = self::registrableDomain($siteUrl);
        if ($target === '') {
            return 0;
        }
        // Rows saved before the column existed (or inserted by hand) are filled in first.
        self::backfillRegistrableDomains($pdo);
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM entries WHERE registrable_domain = ? AND did <> ?');
        $stmt->execute([$target, $excludeDid]);
        return (int) $stmt->fetchColumn();
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getEntryBySiteUrl(PDO $pdo, string $siteUrl): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM entries WHERE site_url = ?');
        $stmt->execute([$siteUrl]);
        $row = $stmt->fetch();
        return is_array($row) ? $row : null;
    }

    public static function deleteEntry(PDO $pdo, string $did): bool
    {
        $stmt = $pdo->prepare('DELETE FROM entries WHERE did = ?');
        $stmt->execute([$did]);
        return $stmt->rowCount() > 0;
    }

    public static function setEntryStatus(PDO $pdo, string $did, string $status, ?string $now = null): bool
    {
        $now ??= self::isoNow();
        $stmt = $pdo->prepare('UPDATE entries SET status = ?, updated_at = ? WHERE did = ?');
        $stmt->execute([$status, $now, $did]);
        return $stmt->rowCount() > 0;
    }

    /**
     * Rows to check on the next refresh sweep (oldest first, never blocked).
     *
     * @return list<array<string, mixed>>
     */
    public static function listForRefresh(PDO $pdo, int $limit = 25): array
    {
        $safeLimit = min(max($limit > 0 ? $limit : 25, 1), 200);
        $stmt = $pdo->prepare("SELECT * FROM entries WHERE status != 'blocked' ORDER BY last_checked_at ASC LIMIT ?");
        $stmt->bindValue(1, $safeLimit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll();
    }

    /**
     * Every crawlable row, for a full cron sweep.
     *
     * @return list<array<string, mixed>>
     */
    public static function listAllForRefresh(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT * FROM entries WHERE status != 'blocked' ORDER BY last_checked_at ASC");
        return $stmt ? $stmt->fetchAll() : [];
    }

    public static function createNonce(PDO $pdo, ?string $now = null): string
    {
        $now ??= self::isoNow();
        $nonce = bin2hex(random_bytes(16));
        $cutoff = gmdate('Y-m-d\TH:i:s.000\Z', time() - 3600);
        $del = $pdo->prepare('DELETE FROM nonces WHERE created_at < ?');
        $del->execute([$cutoff]);

        $ins = $pdo->prepare('INSERT INTO nonces (nonce, created_at) VALUES (?, ?)');
        $ins->execute([$nonce, $now]);
        return $nonce;
    }

    /** Atomically consumes a nonce if it exists, is unused, and is < 15 minutes old. */
    public static function consumeNonce(PDO $pdo, mixed $nonce, ?string $now = null): bool
    {
        if (!is_string($nonce) || $nonce === '') {
            return false;
        }
        $now ??= self::isoNow();
        $stmt = $pdo->prepare('SELECT created_at, used_at FROM nonces WHERE nonce = ?');
        $stmt->execute([$nonce]);
        $row = $stmt->fetch();
        if (!is_array($row) || !empty($row['used_at'])) {
            return false;
        }
        $nowTs = strtotime($now);
        $createdTs = strtotime((string) $row['created_at']);
        if ($nowTs === false || $createdTs === false || ($nowTs - $createdTs) > 15 * 60) {
            return false;
        }
        $upd = $pdo->prepare('UPDATE nonces SET used_at = ? WHERE nonce = ? AND used_at IS NULL');
        $upd->execute([$now, $nonce]);
        return $upd->rowCount() === 1;
    }

    /** Salt from config (`ip_hash_salt` / IP_HASH_SALT); empty means the database copy is used. */
    private static string $configuredIpSalt = '';

    /**
     * Uses a salt kept outside the database for client-IP hashes, so a leaked database doesn't
     * let anyone brute-force the IPv4 addresses behind the stored hashes. Shorter than 32
     * characters is ignored.
     */
    public static function configureIpHashSalt(string $salt): void
    {
        self::$configuredIpSalt = strlen($salt) >= 32 ? $salt : '';
    }

    /** True while client-IP hashes use the salt stored in the database itself. */
    public static function ipSaltInDatabase(): bool
    {
        return self::$configuredIpSalt === '';
    }

    /**
     * A stable, salted hash identifying a client (by IP) without storing the IP. Extensions use
     * this for their own per-client limits.
     */
    public static function clientKeyHash(PDO $pdo, string $ip): string
    {
        return self::hashRateLimitKey($pdo, 'client:' . $ip);
    }

    private static function rateLimitSalt(PDO $pdo): string
    {
        if (self::$configuredIpSalt !== '') {
            return self::$configuredIpSalt;
        }
        $row = $pdo->query("SELECT `value` FROM settings WHERE `key` = 'rate_limit_salt'")->fetch();
        if (is_array($row) && !empty($row['value']) && is_string($row['value'])) {
            return $row['value'];
        }
        $salt = bin2hex(random_bytes(16));
        $now = gmdate('c');
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("INSERT OR IGNORE INTO settings (`key`, `value`, updated_at) VALUES ('rate_limit_salt', ?, ?)");
        } else {
            $stmt = $pdo->prepare("INSERT IGNORE INTO settings (`key`, `value`, updated_at) VALUES ('rate_limit_salt', ?, ?)");
        }
        $stmt->execute([$salt, $now]);
        $again = $pdo->query("SELECT `value` FROM settings WHERE `key` = 'rate_limit_salt'")->fetch();
        return (is_array($again) && !empty($again['value'])) ? (string) $again['value'] : $salt;
    }

    private static function hashRateLimitKey(PDO $pdo, string $ipKey): string
    {
        return hash_hmac('sha256', $ipKey, self::rateLimitSalt($pdo));
    }

    public static function purgeExpiredRateLimits(PDO $pdo, int $maxAgeSeconds = 3600): int
    {
        $cutoff = time() - max(60, $maxAgeSeconds);
        $stmt = $pdo->prepare('DELETE FROM rate_limits WHERE window_start < ?');
        $stmt->execute([$cutoff]);
        return $stmt->rowCount();
    }

    /**
     * Records a new signing key a listed site served without a rotation certificate, for the
     * operator to review. The first such key is kept: a later, different key never replaces it
     * (that would let whoever controls the site swap the key under review); it only sets
     * `conflict`, which the admin page shows as a warning.
     */
    public static function recordCandidateKey(PDO $pdo, string $did, string $publicKeyPem, ?string $now = null): void
    {
        $fingerprint = Verify::spkiFingerprint($publicKeyPem);
        if ($did === '' || $fingerprint === '') {
            return;
        }
        $now ??= self::isoNow();
        $existing = self::getCandidateKey($pdo, $did);
        if ($existing === null) {
            $ins = $pdo->prepare('
                INSERT INTO key_candidates (did, public_key_pem, fingerprint, first_seen_at, last_seen_at, conflict)
                VALUES (?, ?, ?, ?, ?, 0)
            ');
            $ins->execute([$did, trim($publicKeyPem), $fingerprint, $now, $now]);
            return;
        }
        $conflict = $existing['fingerprint'] !== $fingerprint || $existing['conflict'];
        $upd = $pdo->prepare('UPDATE key_candidates SET last_seen_at = ?, conflict = ? WHERE did = ?');
        $upd->execute([$now, $conflict ? 1 : 0, $did]);
    }

    /**
     * @return array{did: string, publicKeyPem: string, fingerprint: string, firstSeenAt: string, lastSeenAt: string, conflict: bool}|null
     */
    public static function getCandidateKey(PDO $pdo, string $did): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM key_candidates WHERE did = ?');
        $stmt->execute([$did]);
        $row = $stmt->fetch();
        return is_array($row) ? self::candidateFromRow($row) : null;
    }

    /**
     * Candidates for several DIDs at once (for the admin listing), keyed by DID.
     *
     * @param list<string> $dids
     * @return array<string, array{did: string, publicKeyPem: string, fingerprint: string, firstSeenAt: string, lastSeenAt: string, conflict: bool}>
     */
    public static function getCandidateKeys(PDO $pdo, array $dids): array
    {
        $dids = array_values(array_unique(array_filter($dids, 'is_string')));
        if (count($dids) === 0) {
            return [];
        }
        $placeholders = implode(',', array_fill(0, count($dids), '?'));
        $stmt = $pdo->prepare("SELECT * FROM key_candidates WHERE did IN ({$placeholders})");
        $stmt->execute($dids);
        $out = [];
        foreach ($stmt->fetchAll() as $row) {
            $candidate = self::candidateFromRow($row);
            $out[$candidate['did']] = $candidate;
        }
        return $out;
    }

    /**
     * @param array<string, mixed> $row
     * @return array{did: string, publicKeyPem: string, fingerprint: string, firstSeenAt: string, lastSeenAt: string, conflict: bool}
     */
    private static function candidateFromRow(array $row): array
    {
        return [
            'did' => (string) $row['did'],
            'publicKeyPem' => (string) $row['public_key_pem'],
            'fingerprint' => (string) $row['fingerprint'],
            'firstSeenAt' => (string) $row['first_seen_at'],
            'lastSeenAt' => (string) $row['last_seen_at'],
            'conflict' => !empty($row['conflict']),
        ];
    }

    public static function clearCandidateKey(PDO $pdo, string $did): void
    {
        $stmt = $pdo->prepare('DELETE FROM key_candidates WHERE did = ?');
        $stmt->execute([$did]);
    }

    /**
     * Checks and updates the sliding-window rate limit for a client IP key.
     * Keys are stored as a salted HMAC so raw client IPs are never persisted.
     * Returns true if allowed, false if rate-limited.
     */
    public static function checkRateLimit(PDO $pdo, string $ipKey, int $windowSeconds, int $maxHits): bool
    {
        $hashedKey = self::hashRateLimitKey($pdo, $ipKey);
        $nowSec = time();
        $cutoff = $nowSec - $windowSeconds;

        // Occasional cleanup of expired rate limit rows (use at least 3600s so shorter windows don't purge hourly keys)
        if (random_int(1, 25) === 1) {
            $cleanupCutoff = $nowSec - max($windowSeconds, 3600);
            $del = $pdo->prepare('DELETE FROM rate_limits WHERE window_start < ?');
            $del->execute([$cleanupCutoff]);
        }

        $stmt = $pdo->prepare('SELECT window_start, hits FROM rate_limits WHERE ip_key = ?');
        $stmt->execute([$hashedKey]);
        $row = $stmt->fetch();

        if (!is_array($row) || (int) $row['window_start'] <= $cutoff) {
            self::upsertRateLimit($pdo, $hashedKey, $nowSec, 1);
            return true;
        }

        $hits = (int) $row['hits'];
        if ($hits >= $maxHits) {
            return false;
        }

        $upd = $pdo->prepare('UPDATE rate_limits SET hits = hits + 1 WHERE ip_key = ?');
        $upd->execute([$hashedKey]);
        return true;
    }

    /**
     * True when `$ipKey` has already used `$maxHits` in the current window. Unlike
     * `checkRateLimit` it doesn't count as a hit.
     */
    public static function isRateLimited(PDO $pdo, string $ipKey, int $windowSeconds, int $maxHits): bool
    {
        $stmt = $pdo->prepare('SELECT window_start, hits FROM rate_limits WHERE ip_key = ?');
        $stmt->execute([self::hashRateLimitKey($pdo, $ipKey)]);
        $row = $stmt->fetch();
        if (!is_array($row) || (int) $row['window_start'] <= time() - $windowSeconds) {
            return false;
        }
        return (int) $row['hits'] >= $maxHits;
    }

    private static function upsertRateLimit(PDO $pdo, string $ipKey, int $windowStart, int $hits): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("
                INSERT INTO rate_limits (ip_key, window_start, hits) VALUES (?, ?, ?)
                ON CONFLICT(ip_key) DO UPDATE SET window_start = excluded.window_start, hits = excluded.hits
            ");
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO rate_limits (ip_key, window_start, hits) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE window_start = VALUES(window_start), hits = VALUES(hits)
            ");
        }
        $stmt->execute([$ipKey, $windowStart, $hits]);
    }

    /**
     * @return list<mixed>
     */
    private static function parseJsonArray(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }
        $parsed = json_decode($value, true);
        return is_array($parsed) && array_is_list($parsed) ? $parsed : [];
    }

    /**
     * The subset safe to expose on the public API and page.
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function toPublicEntry(array $row): array
    {
        return [
            'id' => (string) $row['did'],
            'siteUrl' => (string) $row['site_url'],
            'did' => (string) $row['did'],
            'handle' => (string) $row['handle'],
            'displayName' => (string) $row['display_name'],
            'tagline' => (string) $row['tagline'],
            'bio' => (string) $row['bio'],
            'location' => (string) $row['location'],
            'avatarUrl' => (string) $row['avatar_url'],
            'bannerUrl' => (string) $row['banner_url'],
            'interests' => self::parseJsonArray($row['interests_json'] ?? '[]'),
            'links' => self::parseJsonArray($row['links_json'] ?? '[]'),
            'profileUpdatedAt' => (string) $row['profile_updated_at'],
            'verifiedAt' => (string) $row['verified_at'],
            'updatedAt' => (string) $row['updated_at'],
        ];
    }

    /**
     * The public shape plus operator-only bookkeeping. Nothing secret: the key is reduced to its
     * SPKI fingerprint (the same value key-witness and Webring Studio use).
     *
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    public static function toAdminEntry(array $row, ?array $candidate = null): array
    {
        return array_merge(self::toPublicEntry($row), [
            'status' => (string) $row['status'],
            'failCount' => (int) $row['fail_count'],
            'lastError' => (string) ($row['last_error'] ?? ''),
            'lastCheckedAt' => (string) $row['last_checked_at'],
            'createdAt' => (string) ($row['created_at'] ?? ''),
            'keyFingerprint' => Verify::spkiFingerprint((string) ($row['public_key_pem'] ?? '')),
            // Set while the site serves a new key that the operator hasn't reviewed yet.
            'keyChanged' => $candidate !== null,
            'candidateFingerprint' => $candidate['fingerprint'] ?? '',
            'candidateFirstSeenAt' => $candidate['firstSeenAt'] ?? '',
            'candidateConflict' => (bool) ($candidate['conflict'] ?? false),
        ]);
    }

    /**
     * Admin rows for a page of entries, with their pending candidate keys loaded in one query.
     *
     * @param list<array<string, mixed>> $rows
     * @return list<array<string, mixed>>
     */
    public static function toAdminEntries(PDO $pdo, array $rows): array
    {
        $candidates = self::getCandidateKeys($pdo, array_map(static fn (array $r): string => (string) $r['did'], $rows));
        return array_map(
            static fn (array $r): array => self::toAdminEntry($r, $candidates[(string) $r['did']] ?? null),
            $rows
        );
    }

    /**
     * @param array<string, mixed> $input
     * @param array<string, mixed> $fallback
     * @return array{title: string, description: string, bannerUrl: string, directoryUrl: string, maintainerBadge: bool, maintainerName: string, maintainerUrl: string, maintainerDid: string}
     */
    public static function normalizeInstanceMeta(array $input = [], array $fallback = self::DEFAULT_INSTANCE_META): array
    {
        $title = self::cleanSingleLine($input['title'] ?? null, 120)
            ?: (string) ($fallback['title'] ?? self::DEFAULT_INSTANCE_META['title']);
        $description = self::cleanSingleLine($input['description'] ?? null, 600)
            ?: (string) ($fallback['description'] ?? self::DEFAULT_INSTANCE_META['description']);
        $bannerUrl = array_key_exists('bannerUrl', $input) && is_string($input['bannerUrl'])
            ? self::cleanBannerUrl($input['bannerUrl'])
            : (string) ($fallback['bannerUrl'] ?? '');
        $directoryUrl = array_key_exists('directoryUrl', $input) && is_string($input['directoryUrl'])
            ? Verify::normalizeSiteUrl($input['directoryUrl'])
            : (string) ($fallback['directoryUrl'] ?? '');
        $maintainerBadge = array_key_exists('maintainerBadge', $input)
            ? self::parseBooleanFlag($input['maintainerBadge'], (bool) ($fallback['maintainerBadge'] ?? false))
            : (bool) ($fallback['maintainerBadge'] ?? false);
        $maintainerName = array_key_exists('maintainerName', $input) && is_string($input['maintainerName'])
            ? self::cleanSingleLine($input['maintainerName'], 120)
            : (string) ($fallback['maintainerName'] ?? '');
        $maintainerUrl = array_key_exists('maintainerUrl', $input) && is_string($input['maintainerUrl'])
            ? Verify::normalizeSiteUrl($input['maintainerUrl'])
            : (string) ($fallback['maintainerUrl'] ?? '');
        $maintainerDid = array_key_exists('maintainerDid', $input) && is_string($input['maintainerDid'])
            ? self::cleanDid($input['maintainerDid'])
            : (string) ($fallback['maintainerDid'] ?? '');

        return [
            'title' => $title,
            'description' => $description,
            'bannerUrl' => $bannerUrl,
            'directoryUrl' => $directoryUrl,
            'maintainerBadge' => $maintainerBadge,
            'maintainerName' => $maintainerName,
            'maintainerUrl' => $maintainerUrl,
            'maintainerDid' => $maintainerDid,
        ];
    }

    /**
     * @param array<string, mixed> $defaults
     * @return array{title: string, description: string, bannerUrl: string, directoryUrl: string, maintainerBadge: bool, maintainerName: string, maintainerUrl: string, maintainerDid: string}
     */
    public static function getInstanceMeta(PDO $pdo, array $defaults = self::DEFAULT_INSTANCE_META): array
    {
        $base = self::normalizeInstanceMeta($defaults, self::DEFAULT_INSTANCE_META);
        $placeholders = implode(', ', array_fill(0, count(self::META_SETTING_KEYS), '?'));
        $stmt = $pdo->prepare("SELECT `key`, `value` FROM settings WHERE `key` IN ({$placeholders})");
        $stmt->execute(self::META_SETTING_KEYS);
        $overrides = [];
        foreach ($stmt->fetchAll() as $row) {
            $overrides[(string) $row['key']] = (string) $row['value'];
        }

        return [
            'title' => array_key_exists('title', $overrides)
                ? (self::cleanSingleLine($overrides['title'], 120) ?: $base['title'])
                : $base['title'],
            'description' => array_key_exists('description', $overrides)
                ? (self::cleanSingleLine($overrides['description'], 600) ?: $base['description'])
                : $base['description'],
            'bannerUrl' => array_key_exists('bannerUrl', $overrides)
                ? self::cleanBannerUrl($overrides['bannerUrl'])
                : $base['bannerUrl'],
            'directoryUrl' => array_key_exists('directoryUrl', $overrides)
                ? Verify::normalizeSiteUrl($overrides['directoryUrl'])
                : $base['directoryUrl'],
            'maintainerBadge' => array_key_exists('maintainerBadge', $overrides)
                ? self::parseBooleanFlag($overrides['maintainerBadge'], false)
                : $base['maintainerBadge'],
            'maintainerName' => array_key_exists('maintainerName', $overrides)
                ? self::cleanSingleLine($overrides['maintainerName'], 120)
                : $base['maintainerName'],
            'maintainerUrl' => array_key_exists('maintainerUrl', $overrides)
                ? Verify::normalizeSiteUrl($overrides['maintainerUrl'])
                : $base['maintainerUrl'],
            'maintainerDid' => array_key_exists('maintainerDid', $overrides)
                ? self::cleanDid($overrides['maintainerDid'])
                : $base['maintainerDid'],
        ];
    }

    private static function upsertSetting(PDO $pdo, string $key, string $value, string $now): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare("
                INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, ?)
                ON CONFLICT(`key`) DO UPDATE SET `value` = excluded.`value`, updated_at = excluded.updated_at
            ");
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE `value` = VALUES(`value`), updated_at = VALUES(updated_at)
            ");
        }
        $stmt->execute([$key, $value, $now]);
    }

    /**
     * @param array<string, mixed> $patch
     */
    public static function setInstanceMeta(PDO $pdo, array $patch = [], ?string $now = null): void
    {
        $now ??= self::isoNow();
        $remove = $pdo->prepare('DELETE FROM settings WHERE `key` = ?');

        $pdo->beginTransaction();
        try {
            foreach (self::META_SETTING_KEYS as $key) {
                if (!array_key_exists($key, $patch)) {
                    continue;
                }
                $raw = $patch[$key];
                if ($raw === null) {
                    $remove->execute([$key]);
                    continue;
                }
                if ($key === 'title') {
                    $cleaned = self::cleanSingleLine($raw, 120);
                    if ($cleaned === '') {
                        $remove->execute([$key]);
                    } else {
                        self::upsertSetting($pdo, $key, $cleaned, $now);
                    }
                } elseif ($key === 'description') {
                    $cleaned = self::cleanSingleLine($raw, 600);
                    if ($cleaned === '') {
                        $remove->execute([$key]);
                    } else {
                        self::upsertSetting($pdo, $key, $cleaned, $now);
                    }
                } elseif ($key === 'bannerUrl') {
                    $trimmed = is_string($raw) ? trim($raw) : '';
                    self::upsertSetting($pdo, $key, $trimmed !== '' ? self::cleanBannerUrl($trimmed) : '', $now);
                } elseif ($key === 'directoryUrl') {
                    $trimmed = is_string($raw) ? trim($raw) : '';
                    self::upsertSetting($pdo, $key, $trimmed !== '' ? Verify::normalizeSiteUrl($trimmed) : '', $now);
                } elseif ($key === 'maintainerBadge') {
                    self::upsertSetting($pdo, $key, self::parseBooleanFlag($raw, false) ? '1' : '0', $now);
                } elseif ($key === 'maintainerName') {
                    self::upsertSetting($pdo, $key, self::cleanSingleLine($raw, 120), $now);
                } elseif ($key === 'maintainerUrl') {
                    $trimmed = is_string($raw) ? trim($raw) : '';
                    self::upsertSetting($pdo, $key, $trimmed !== '' ? Verify::normalizeSiteUrl($trimmed) : '', $now);
                } elseif ($key === 'maintainerDid') {
                    $trimmed = is_string($raw) ? trim($raw) : '';
                    self::upsertSetting($pdo, $key, $trimmed !== '' ? self::cleanDid($trimmed) : '', $now);
                }
            }
            $pdo->commit();
        } catch (\Throwable $err) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $err;
        }
    }

    /**
     * @param array<string, mixed> $meta
     * @return array{title: string, description: string, bannerUrl: string, did: string, maintainerBadge: bool, maintainer: array{name: string, url: string, did: string, verified: bool}|null}
     */
    public static function resolvePublicInfo(?PDO $pdo, array $meta = self::DEFAULT_INSTANCE_META): array
    {
        $safe = self::normalizeInstanceMeta($meta, self::DEFAULT_INSTANCE_META);
        $did = $safe['directoryUrl'] !== '' ? Verify::siteUrlToDidWeb($safe['directoryUrl']) : '';

        $maintainer = null;
        if ($safe['maintainerBadge']) {
            $url = $safe['maintainerUrl'];
            $derivedDid = $safe['maintainerDid'] !== ''
                ? $safe['maintainerDid']
                : ($url !== '' ? Verify::siteUrlToDidWeb($url) : '');

            $row = null;
            if ($pdo !== null) {
                if ($derivedDid !== '') {
                    $row = self::getEntryByDid($pdo, $derivedDid);
                } elseif ($url !== '') {
                    $row = self::getEntryBySiteUrl($pdo, $url);
                }
            }

            $verified = is_array($row) && ($row['status'] ?? '') === 'active';
            $resolvedUrl = $url !== '' ? $url : ($verified ? (string) $row['site_url'] : '');
            $resolvedDid = $derivedDid !== '' ? $derivedDid : ($verified ? (string) $row['did'] : '');
            $urlHost = $resolvedUrl !== '' ? (string) (parse_url($resolvedUrl, PHP_URL_HOST) ?: '') : '';
            $resolvedName = $safe['maintainerName'] !== ''
                ? $safe['maintainerName']
                : (($verified && !empty($row['display_name']))
                    ? (string) $row['display_name']
                    : ($urlHost !== '' ? $urlHost : $resolvedDid));

            if ($resolvedName !== '' || $resolvedUrl !== '' || $resolvedDid !== '') {
                $maintainer = [
                    'name' => $resolvedName,
                    'url' => $resolvedUrl,
                    'did' => $resolvedDid,
                    'verified' => $verified,
                ];
            }
        }

        return [
            'title' => $safe['title'],
            'description' => $safe['description'],
            'bannerUrl' => $safe['bannerUrl'],
            'did' => $did,
            'maintainerBadge' => $maintainer !== null,
            'maintainer' => $maintainer,
        ];
    }

    /**
     * @return array{publicKeyPem: string, privateKeyPem: string}
     */
    public static function getOrCreateInstanceKeys(PDO $pdo, ?string $now = null): array
    {
        $now ??= self::isoNow();
        $stmt = $pdo->query("SELECT `key`, `value` FROM settings WHERE `key` IN ('instance_public_key_pem', 'instance_private_key_pem')");
        $rows = [];
        if ($stmt) {
            foreach ($stmt->fetchAll() as $row) {
                $rows[(string) $row['key']] = (string) $row['value'];
            }
        }
        if (
            isset($rows['instance_public_key_pem'], $rows['instance_private_key_pem'])
            && str_contains($rows['instance_public_key_pem'], 'BEGIN PUBLIC KEY')
        ) {
            return [
                'publicKeyPem' => $rows['instance_public_key_pem'],
                'privateKeyPem' => $rows['instance_private_key_pem'],
            ];
        }

        $keys = Verify::generateKeyPair();
        $pdo->beginTransaction();
        try {
            self::upsertSetting($pdo, 'instance_public_key_pem', $keys['publicKeyPem'], $now);
            self::upsertSetting($pdo, 'instance_private_key_pem', $keys['privateKeyPem'], $now);
            $pdo->commit();
        } catch (\Throwable $err) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $err;
        }
        return $keys;
    }

    /**
     * Builds the W3C `did:web` document for this hub instance, linking `controller`
     * to the creator / maintainer's DID when configured.
     *
     * @param array<string, mixed> $meta
     * @return array<string, mixed>|null
     */
    public static function buildInstanceDidDocument(PDO $pdo, array $meta = self::DEFAULT_INSTANCE_META): ?array
    {
        $info = self::resolvePublicInfo($pdo, $meta);
        if ($info['did'] === '') {
            return null;
        }
        $safe = self::normalizeInstanceMeta($meta, self::DEFAULT_INSTANCE_META);
        $keys = self::getOrCreateInstanceKeys($pdo);
        $controllerDid = ($info['maintainer'] !== null && $info['maintainer']['did'] !== '')
            ? $info['maintainer']['did']
            : '';

        $doc = [
            '@context' => [
                'https://www.w3.org/ns/did/v1',
                'https://w3id.org/security/suites/ed25519-2020/v1',
            ],
            'id' => $info['did'],
        ];
        if ($controllerDid !== '') {
            $doc['controller'] = $controllerDid;
        }
        $doc['verificationMethod'] = [
            [
                'id' => $info['did'] . '#key-1',
                'type' => 'Ed25519VerificationKey2020',
                'controller' => $controllerDid !== '' ? $controllerDid : $info['did'],
                'publicKeyPem' => $keys['publicKeyPem'],
            ],
        ];
        // The service id and type keep their original "directory" names: clients and published
        // did.json documents already rely on them.
        $services = [
            [
                'id' => $info['did'] . '#directory',
                'type' => 'WebringDirectory',
                'serviceEndpoint' => $safe['directoryUrl'] . '/api/entries',
            ],
        ];
        if ($info['maintainer'] !== null && $info['maintainer']['url'] !== '') {
            $services[] = [
                'id' => $info['did'] . '#maintainer',
                'type' => 'WebringMaintainer',
                'serviceEndpoint' => $info['maintainer']['url'],
            ];
        }
        $doc['service'] = $services;

        return $doc;
    }
}
