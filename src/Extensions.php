<?php
declare(strict_types=1);

namespace WebringHub;

use PDO;

/**
 * Extensions: optional modules an operator installs in `extensions/<id>/` and turns on by
 * listing the id in `config['extensions']`. See docs/extensions.md for the rules.
 *
 * PHP cannot sandbox code it loads, so an enabled extension can do anything the hub can.
 * The allowlist, the manifest and this host API keep extensions predictable and reviewable; they
 * are not a security boundary.
 */
interface Extension
{
    public function register(ExtensionContext $ctx): void;
}

/**
 * The host API handed to one extension. Everything an extension adds goes through here so its
 * routes, tables and settings stay inside its own namespace.
 */
final class ExtensionContext
{
    /** @var list<array{method: string, pattern: string, regex: string, handler: callable, admin: bool, rateLimited: bool}> */
    public array $routes = [];

    /** @var array<string, list<callable>> */
    public array $listeners = [];

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $config
     */
    public function __construct(
        public readonly string $id,
        public readonly array $manifest,
        private readonly PDO $pdo,
        private readonly array $config
    ) {
    }

    /**
     * Registers a GET (and HEAD) route at `/api/ext/<id>/<subPath>`. Use `{name}` for a path
     * segment; the handler receives `(array $params, ExtensionRequest $req)` and returns the
     * JSON body (status 200) or an `[int $status, array $body]` pair.
     *
     * @param array{admin?: bool, rateLimited?: bool} $opts
     */
    public function get(string $subPath, callable $handler, array $opts = []): void
    {
        $this->addRoute('GET', $subPath, $handler, $opts);
    }

    /**
     * @param array{admin?: bool, rateLimited?: bool} $opts
     */
    public function post(string $subPath, callable $handler, array $opts = []): void
    {
        $this->addRoute('POST', $subPath, $handler, $opts);
    }

    /**
     * Listens for a core event (see Extensions::EVENTS). The handler receives the payload array.
     */
    public function on(string $event, callable $handler): void
    {
        if (!in_array($event, Extensions::EVENTS, true)) {
            throw new \InvalidArgumentException("Unknown event {$event}.");
        }
        $this->listeners[$event][] = $handler;
    }

    /**
     * Runs `$up(PDO $pdo, string $driver)` once for each schema version above the one recorded
     * for this extension. Call it with increasing versions from `register()`.
     */
    public function migrate(int $version, callable $up): void
    {
        $key = "ext:{$this->id}:schema";
        $stmt = $this->pdo->prepare('SELECT `value` FROM settings WHERE `key` = ?');
        $stmt->execute([$key]);
        $current = (int) ($stmt->fetchColumn() ?: 0);
        if ($current >= $version) {
            return;
        }
        $up($this->pdo, $this->driver());
        Extensions::writeSetting($this->pdo, $key, (string) $version);
    }

    public function schemaVersion(): int
    {
        $stmt = $this->pdo->prepare('SELECT `value` FROM settings WHERE `key` = ?');
        $stmt->execute(["ext:{$this->id}:schema"]);
        return (int) ($stmt->fetchColumn() ?: 0);
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    public function driver(): string
    {
        return (string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
    }

    /**
     * This extension's settings from `config['extension_settings'][<id>]`.
     *
     * @return array<string, mixed>
     */
    public function config(): array
    {
        $all = is_array($this->config['extension_settings'] ?? null) ? $this->config['extension_settings'] : [];
        return is_array($all[$this->id] ?? null) ? $all[$this->id] : [];
    }

    /**
     * The table name for this extension: `ext_<id with - as _>_<suffix>`.
     */
    public function table(string $suffix): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,40}$/', $suffix)) {
            throw new \InvalidArgumentException('Table suffixes must be lowercase letters, digits and _.');
        }
        return 'ext_' . str_replace('-', '_', $this->id) . '_' . $suffix;
    }

    public function isoNow(): string
    {
        return Database::isoNow();
    }

    /**
     * Read-only lookup of a core entry.
     *
     * @return array<string, mixed>|null
     */
    public function entryByDid(string $did): ?array
    {
        return Database::getEntryByDid($this->pdo, $did);
    }

    /**
     * Fetches public JSON with the same guard the hub uses for sites (https only, no
     * private addresses, size and time limits).
     *
     * @return array{url: string, status: int, headers: array<string, string>, json: mixed, jsonObject: mixed}
     */
    public function fetchJson(string $url): array
    {
        return Net::safeGetJson($url, [
            'allowInsecure' => !empty($this->config['allow_insecure']),
            'allowPrivate' => !empty($this->config['allow_private_hosts']),
            'timeoutMs' => (int) ($this->config['refresh_timeout_ms'] ?? 8000),
        ]);
    }

    /**
     * @param array{admin?: bool, rateLimited?: bool} $opts
     */
    private function addRoute(string $method, string $subPath, callable $handler, array $opts): void
    {
        $subPath = trim($subPath, '/');
        if (!preg_match('#^v[0-9]+(/[A-Za-z0-9._{}-]+)*$#', $subPath)) {
            throw new \InvalidArgumentException('Extension routes must start with a version segment like v1/.');
        }
        $regex = '#^/api/ext/' . preg_quote($this->id, '#') . '/'
            . preg_replace('/\\\\\{([a-zA-Z][a-zA-Z0-9]*)\\\\\}/', '(?P<$1>[^/]+)', preg_quote($subPath, '#'))
            . '$#';
        $this->routes[] = [
            'method' => $method,
            'pattern' => $subPath,
            'regex' => $regex,
            'handler' => $handler,
            'admin' => (bool) ($opts['admin'] ?? false),
            'rateLimited' => (bool) ($opts['rateLimited'] ?? false),
        ];
    }
}

/**
 * What an extension route handler sees of the request.
 */
final class ExtensionRequest
{
    /**
     * @param array<string, mixed> $query
     * @param callable(): array<string, mixed> $readBody
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        private $readBody
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function json(): array
    {
        return ($this->readBody)();
    }
}

/**
 * Loads enabled extensions, dispatches their routes and emits core events to them.
 */
final class Extensions
{
    public const API_VERSION = 1;

    public const EVENTS = [
        'entry.verified',
        'entry.key_rejected',
        'entry.removed',
        'entry.status_changed',
        'cron.refresh',
    ];

    /** @var array<string, ExtensionContext> */
    private array $loaded = [];

    /** @var array<string, string> id => load error */
    private array $errors = [];

    /** @var list<string> */
    private array $enabled = [];

    public function __construct(private readonly string $root = '')
    {
    }

    /**
     * An instance with nothing loaded, for callers that run before or without extensions.
     */
    public static function none(): self
    {
        return new self();
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function load(array $config, PDO $pdo, ?string $root = null): self
    {
        $root ??= dirname(__DIR__) . '/extensions';
        $self = new self($root);
        $ids = is_array($config['extensions'] ?? null) ? $config['extensions'] : [];
        foreach ($ids as $id) {
            if (!is_string($id)) {
                continue;
            }
            $self->enabled[] = $id;
            try {
                $self->loadOne($id, $config, $pdo);
            } catch (\Throwable $err) {
                $self->errors[$id] = $err->getMessage() ?: 'Could not load the extension.';
                error_log("[webring-hub-php] extension {$id}: " . $self->errors[$id]);
            }
        }
        return $self;
    }

    /**
     * Extensions that loaded, for `/api/info`.
     *
     * @return list<array{id: string, version: string, apiVersion: int}>
     */
    public function publicList(): array
    {
        $out = [];
        foreach ($this->loaded as $id => $ctx) {
            $out[] = [
                'id' => $id,
                'version' => (string) ($ctx->manifest['version'] ?? '0.0.0'),
                'apiVersion' => (int) ($ctx->manifest['apiVersion'] ?? self::API_VERSION),
            ];
        }
        return $out;
    }

    /**
     * Every enabled extension, including ones that failed to load, for the admin API.
     *
     * @return list<array<string, mixed>>
     */
    public function adminList(): array
    {
        $out = [];
        foreach ($this->enabled as $id) {
            $ctx = $this->loaded[$id] ?? null;
            $out[] = [
                'id' => $id,
                'loaded' => $ctx !== null,
                'name' => $ctx ? (string) ($ctx->manifest['name'] ?? $id) : '',
                'version' => $ctx ? (string) ($ctx->manifest['version'] ?? '') : '',
                'apiVersion' => $ctx ? (int) ($ctx->manifest['apiVersion'] ?? self::API_VERSION) : 0,
                'schemaVersion' => $ctx ? $ctx->schemaVersion() : 0,
                'routes' => $ctx ? array_map(static fn ($r) => $r['method'] . ' ' . $r['pattern'], $ctx->routes) : [],
                'events' => $ctx ? array_keys($ctx->listeners) : [],
                'error' => $this->errors[$id] ?? null,
            ];
        }
        return $out;
    }

    public function isLoaded(string $id): bool
    {
        return isset($this->loaded[$id]);
    }

    /**
     * Sends an event to every listener. Runs after the core has committed, and never lets a
     * listener's failure reach the caller.
     *
     * @param array<string, mixed> $payload
     */
    public function emit(string $event, array $payload): void
    {
        foreach ($this->loaded as $id => $ctx) {
            foreach ($ctx->listeners[$event] ?? [] as $listener) {
                try {
                    $listener($payload);
                } catch (\Throwable $err) {
                    error_log("[webring-hub-php] extension {$id} failed on {$event}: " . $err->getMessage());
                }
            }
        }
    }

    /**
     * Finds the extension route for a request under `/api/ext/`.
     *
     * @return array{route: array<string, mixed>, params: array<string, string>}|null
     */
    public function match(string $method, string $pathname): ?array
    {
        if (!str_starts_with($pathname, '/api/ext/')) {
            return null;
        }
        $lookup = $method === 'HEAD' ? 'GET' : $method;
        foreach ($this->loaded as $ctx) {
            foreach ($ctx->routes as $route) {
                if ($route['method'] !== $lookup) {
                    continue;
                }
                if (preg_match($route['regex'], $pathname, $m)) {
                    $params = [];
                    foreach ($m as $k => $v) {
                        if (is_string($k)) {
                            $decoded = rawurldecode($v);
                            if ($decoded === '') {
                                throw new AppError(400, 'invalid_path', 'Malformed URL path.');
                            }
                            $params[$k] = $decoded;
                        }
                    }
                    return ['route' => $route, 'params' => $params];
                }
            }
        }
        return null;
    }

    /**
     * Calls a matched route's handler and normalises its result to `[status, body]`.
     *
     * @param array{route: array<string, mixed>, params: array<string, string>} $match
     * @return array{0: int, 1: array<string, mixed>}
     */
    public static function run(array $match, ExtensionRequest $req): array
    {
        $result = ($match['route']['handler'])($match['params'], $req);
        if (is_array($result) && array_is_list($result) && count($result) === 2 && is_int($result[0]) && is_array($result[1])) {
            return [$result[0], $result[1]];
        }
        if (!is_array($result)) {
            throw new AppError(500, 'extension_error', 'The extension returned an invalid response.');
        }
        return [200, $result];
    }

    /**
     * Writes one row in the core `settings` table (used for extension schema versions).
     */
    public static function writeSetting(PDO $pdo, string $key, string $value): void
    {
        $now = Database::isoNow();
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
     * @param array<string, mixed> $config
     */
    private function loadOne(string $id, array $config, PDO $pdo): void
    {
        if (!preg_match('/^[a-z][a-z0-9-]{1,40}$/', $id)) {
            throw new \RuntimeException('Extension ids must be lowercase letters, digits and dashes.');
        }
        $dir = $this->root . '/' . $id;
        $manifestPath = $dir . '/extension.json';
        if (!is_file($manifestPath)) {
            throw new \RuntimeException("No extension.json in extensions/{$id}.");
        }
        $manifest = json_decode((string) file_get_contents($manifestPath), true);
        if (!is_array($manifest)) {
            throw new \RuntimeException('extension.json is not a JSON object.');
        }
        if (($manifest['id'] ?? null) !== $id) {
            throw new \RuntimeException("extension.json names id \"" . (string) ($manifest['id'] ?? '') . "\", not {$id}.");
        }
        if ((int) ($manifest['apiVersion'] ?? 0) !== self::API_VERSION) {
            throw new \RuntimeException('Unsupported extension apiVersion (this hub supports ' . self::API_VERSION . ').');
        }
        $class = (string) ($manifest['class'] ?? '');
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $class)) {
            throw new \RuntimeException('extension.json must name the extension class.');
        }
        $entry = $dir . '/Extension.php';
        if (!is_file($entry)) {
            throw new \RuntimeException("No Extension.php in extensions/{$id}.");
        }
        require_once $entry;
        if (!class_exists($class)) {
            throw new \RuntimeException("Extension.php does not define {$class}.");
        }
        $instance = new $class();
        if (!$instance instanceof Extension) {
            throw new \RuntimeException("{$class} does not implement WebringHub\\Extension.");
        }
        $ctx = new ExtensionContext($id, $manifest, $pdo, $config);
        $instance->register($ctx);
        $this->loaded[$id] = $ctx;
    }
}
