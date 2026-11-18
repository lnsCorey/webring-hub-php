<?php
declare(strict_types=1);

namespace WebringHub;

final class Config
{
    /**
     * Loads configuration from `config.php` if it exists, falling back to `config.example.php`,
     * merges any runtime overrides, and normalizes both flat and nested configuration keys.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function load(array $overrides = []): array
    {
        $root = dirname(__DIR__);
        $configFile = $root . '/config.php';
        $exampleFile = $root . '/config.example.php';

        $base = [];
        if (is_file($configFile)) {
            $loaded = require $configFile;
            if (is_array($loaded)) {
                $base = $loaded;
            }
        } elseif (is_file($exampleFile)) {
            $loaded = require $exampleFile;
            if (is_array($loaded)) {
                $base = $loaded;
            }
        }

        $cfg = array_replace_recursive($base, $overrides);
        $dbSub = is_array($cfg['db'] ?? null) ? $cfg['db'] : [];
        $instSub = is_array($cfg['instance'] ?? null) ? $cfg['instance'] : [];
        $rateSub = is_array($cfg['write_rate_limit'] ?? null) ? $cfg['write_rate_limit'] : [];

        $cfg['db'] = [
            'driver' => (string) ($dbSub['driver'] ?? $cfg['db_driver'] ?? 'mysql'),
            'host' => (string) ($dbSub['host'] ?? $cfg['db_host'] ?? '127.0.0.1'),
            'port' => (int) ($dbSub['port'] ?? $cfg['db_port'] ?? 3306),
            'name' => (string) ($dbSub['name'] ?? $cfg['db_name'] ?? ''),
            'user' => (string) ($dbSub['user'] ?? $cfg['db_user'] ?? ''),
            'pass' => (string) ($dbSub['pass'] ?? $cfg['db_pass'] ?? ''),
            'charset' => (string) ($dbSub['charset'] ?? $cfg['db_charset'] ?? 'utf8mb4'),
            'sqlite_path' => (string) ($dbSub['sqlite_path'] ?? $cfg['sqlite_path'] ?? ($root . '/data/directory.sqlite')),
            'auto_migrate' => (bool) ($dbSub['auto_migrate'] ?? $cfg['auto_migrate'] ?? true),
        ];

        $cfg['instance'] = [
            'title' => (string) ($instSub['title'] ?? $cfg['title'] ?? 'Webring Hub'),
            'description' => (string) ($instSub['description'] ?? $cfg['description'] ?? ''),
            'bannerUrl' => (string) ($instSub['bannerUrl'] ?? $instSub['banner_url'] ?? $cfg['banner_url'] ?? $cfg['bannerUrl'] ?? ''),
            // This hub's canonical URL. `hub_url` / `hubUrl` are the current names; the older
            // `directory_url` / `directoryUrl` still work. It is normalized to `directoryUrl`, the
            // instance-meta key that is also stored in `settings` and accepted by the settings API.
            'directoryUrl' => (string) self::firstNonEmpty([
                $instSub['hubUrl'] ?? null,
                $instSub['hub_url'] ?? null,
                $cfg['hub_url'] ?? null,
                $cfg['hubUrl'] ?? null,
                $instSub['directoryUrl'] ?? null,
                $instSub['directory_url'] ?? null,
                $cfg['directory_url'] ?? null,
                $cfg['directoryUrl'] ?? null,
            ]),
            'maintainerBadge' => (bool) ($instSub['maintainerBadge'] ?? $instSub['maintainer_badge'] ?? $cfg['maintainer_badge'] ?? $cfg['maintainerBadge'] ?? false),
            'maintainerName' => (string) ($instSub['maintainerName'] ?? $instSub['maintainer_name'] ?? $cfg['maintainer_name'] ?? $cfg['maintainerName'] ?? ''),
            'maintainerUrl' => (string) ($instSub['maintainerUrl'] ?? $instSub['maintainer_url'] ?? $cfg['maintainer_url'] ?? $cfg['maintainerUrl'] ?? ''),
            'maintainerDid' => (string) ($instSub['maintainerDid'] ?? $instSub['maintainer_did'] ?? $cfg['maintainer_did'] ?? $cfg['maintainerDid'] ?? ''),
        ];

        $cfg['write_rate_limit'] = [
            'window_seconds' => (int) ($rateSub['window_seconds'] ?? $cfg['write_rate_limit_window_seconds'] ?? 3600),
            'max' => (int) ($rateSub['max'] ?? $cfg['write_rate_limit_max'] ?? 30),
        ];

        $globalCrawlSub = is_array($cfg['global_crawl_rate_limit'] ?? null) ? $cfg['global_crawl_rate_limit'] : [];
        $cfg['global_crawl_rate_limit'] = [
            'window_seconds' => max(1, (int) ($globalCrawlSub['window_seconds'] ?? $cfg['global_crawl_rate_limit_window_seconds'] ?? 60)),
            'max' => max(1, (int) ($globalCrawlSub['max'] ?? $cfg['global_crawl_rate_limit_max'] ?? 20)),
        ];

        $cfg['trusted_proxy_hops'] = max(1, (int) ($cfg['trusted_proxy_hops'] ?? 1));
        $cfg['allow_private_hosts'] = (bool) ($cfg['allow_private_hosts'] ?? $cfg['allow_private'] ?? false);
        if (empty($cfg['extensions']) || !is_array($cfg['extensions'])) {
            $cfg['extensions'] = array_values(array_filter(array_map('trim', explode(',', self::env('HUB_EXTENSIONS', 'DIRECTORY_EXTENSIONS')))));
        }

        return $cfg;
    }

    /**
     * The first value that is a non-empty string, or ''.
     *
     * @param list<mixed> $values
     */
    private static function firstNonEmpty(array $values): string
    {
        foreach ($values as $value) {
            if (is_string($value) && trim($value) !== '') {
                return $value;
            }
        }
        return '';
    }

    /**
     * Reads the first environment variable in `$names` that is set and not empty, so config files
     * can prefer the current `HUB_*` names and fall back to the older `DIRECTORY_*` ones.
     */
    public static function env(string ...$names): string
    {
        foreach ($names as $name) {
            $value = getenv($name);
            if (is_string($value) && $value !== '') {
                return $value;
            }
        }
        return '';
    }
}
