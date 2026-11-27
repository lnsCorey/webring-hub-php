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
 * A non-JSON response from an extension route (a calendar or a feed). Only a short allowlist of
 * text types is accepted, so an extension can't serve HTML or scripts from an API route.
 */
final class ExtensionRawResponse
{
    public const CONTENT_TYPES = [
        'text/calendar' => 'text/calendar; charset=utf-8',
        'application/atom+xml' => 'application/atom+xml; charset=utf-8',
        'text/plain' => 'text/plain; charset=utf-8',
        'text/csv' => 'text/csv; charset=utf-8',
    ];

    public readonly string $contentType;

    /**
     * @param array<string, string> $headers extra headers such as Content-Disposition
     */
    public function __construct(
        public readonly int $status,
        public readonly string $body,
        string $contentType,
        public readonly array $headers = []
    ) {
        if (!isset(self::CONTENT_TYPES[$contentType])) {
            throw new \InvalidArgumentException("Extensions may not send {$contentType}.");
        }
        $this->contentType = self::CONTENT_TYPES[$contentType];
    }
}

/**
 * Thrown while loading when an extension's `reads` dependency has not loaded yet; the loader
 * retries it after the others.
 */
final class ExtensionDependencyPending extends \RuntimeException
{
}

/**
 * The host API handed to one extension. Everything an extension adds goes through here so its
 * routes, tables and settings stay inside its own namespace.
 */
final class ExtensionContext
{
    /** @var list<array{method: string, pattern: string, regex: string, handler: callable, admin: bool, rateLimited: bool, userAuth: bool, requireOptIn: bool}> */
    public array $routes = [];

    /** @var list<array{mountPath: string, realDir: string, index: string, cache: int, cors: bool, csp: string, frameable: bool, headers: mixed, allowedExtensions: array<string, string>}> */
    public array $publicRoutes = [];

    /** @var array<string, list<callable>> */
    public array $listeners = [];

    /**
     * Set by Extensions when the context is loaded; broadcasts `ext.<id>.<name>` events.
     *
     * @var (\Closure(string, array<string, mixed>): void)|null
     */
    public ?\Closure $emitter = null;

    /**
     * Loaded contexts of the extensions this one declared in `reads`, set by Extensions.
     *
     * @var array<string, ExtensionContext>
     */
    public array $readable = [];

    /** @var array{kind: string, public: ?array<string, mixed>, panels: list<array<string, mixed>>, error: ?string} */
    public array $ui = [
        'kind' => 'headless',
        'public' => null,
        'panels' => [],
        'error' => null,
    ];

    /**
     * @param array<string, mixed> $manifest
     * @param array<string, mixed> $config
     */
    public function __construct(
        public readonly string $id,
        public readonly array $manifest,
        private readonly PDO $pdo,
        private readonly array $config,
        public readonly string $dir = ''
    ) {
    }

    /**
     * Registers a GET (and HEAD) route at `/api/ext/<id>/<subPath>`. Use `{name}` for a path
     * segment; the handler receives `(array $params, ExtensionRequest $req)` and returns the
     * JSON body (status 200) or an `[int $status, array $body]` pair.
     *
     * @param array{admin?: bool, rateLimited?: bool, userAuth?: bool, requireOptIn?: bool} $opts
     */
    public function get(string $subPath, callable $handler, array $opts = []): void
    {
        $this->addRoute('GET', $subPath, $handler, $opts);
    }

    /**
     * @param array{admin?: bool, rateLimited?: bool, userAuth?: bool, requireOptIn?: bool} $opts
     */
    public function post(string $subPath, callable $handler, array $opts = []): void
    {
        $this->addRoute('POST', $subPath, $handler, $opts);
    }

    /**
     * @param array{admin?: bool, rateLimited?: bool, userAuth?: bool, requireOptIn?: bool} $opts
     */
    public function delete(string $subPath, callable $handler, array $opts = []): void
    {
        $this->addRoute('DELETE', $subPath, $handler, $opts);
    }

    /**
     * Serves static files under $dir at `/ext/<id>/<prefix>/...`.
     * The directory must resolve inside the extension's own folder.
     *
     * @param array{index?: string, cache?: int, cors?: bool, csp?: string, frameable?: bool, headers?: mixed, extensions?: list<string>} $opts
     */
    public function serveFiles(string $prefix, string $dir, array $opts = []): void
    {
        if ($this->dir === '') {
            throw new \RuntimeException('Extension directory is not set.');
        }
        $extReal = realpath($this->dir);
        $candidateDir = str_starts_with($dir, '/') || preg_match('/^[A-Za-z]:[\\\\\/]/', $dir)
            ? $dir
            : $this->dir . '/' . ltrim($dir, '/\\');
        $targetReal = realpath($candidateDir);
        if ($extReal === false || $targetReal === false || !is_dir($targetReal)) {
            throw new \InvalidArgumentException("Static directory does not exist: {$dir}");
        }
        $extPrefix = rtrim($extReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if ($targetReal !== $extReal && !str_starts_with($targetReal, $extPrefix)) {
            throw new \InvalidArgumentException('Static directory must stay inside the extension folder.');
        }

        $cleanPrefix = trim($prefix, '/');
        if ($cleanPrefix !== '' && !preg_match('#^[a-z0-9_-]+(/[a-z0-9_-]+)*$#i', $cleanPrefix)) {
            throw new \InvalidArgumentException("Invalid static prefix: {$prefix}");
        }
        $mountPath = '/ext/' . $this->id . ($cleanPrefix !== '' ? '/' . $cleanPrefix : '');

        $allowedMap = Extensions::PUBLIC_MIME_TYPES;
        if (isset($opts['extensions']) && is_array($opts['extensions'])) {
            $filtered = [];
            foreach ($opts['extensions'] as $ext) {
                $k = strtolower(ltrim((string) $ext, '.'));
                if (isset(Extensions::PUBLIC_MIME_TYPES[$k])) {
                    $filtered[$k] = Extensions::PUBLIC_MIME_TYPES[$k];
                }
            }
            $allowedMap = $filtered;
        }

        $defaultCsp = "default-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; font-src 'self'; style-src 'self' 'unsafe-inline'; script-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'self'";
        $this->publicRoutes[] = [
            'mountPath' => $mountPath,
            'realDir' => $targetReal,
            'index' => (string) ($opts['index'] ?? 'index.html'),
            'cache' => max(0, (int) ($opts['cache'] ?? 300)),
            'cors' => !empty($opts['cors']),
            'csp' => (string) ($opts['csp'] ?? $defaultCsp),
            'frameable' => !empty($opts['frameable']),
            'headers' => $opts['headers'] ?? [],
            'allowedExtensions' => $allowedMap,
        ];
        // Sort longest mountPath first so specific sub-mounts (e.g. /ext/arcade/games) win over /ext/arcade
        usort($this->publicRoutes, static fn (array $a, array $b): int => strlen($b['mountPath']) <=> strlen($a['mountPath']));
    }

    /**
     * Listens for a core event (see Extensions::EVENTS), or for an event another extension emits,
     * named `ext.<id>.<name>`. The handler receives the payload array.
     */
    public function on(string $event, callable $handler): void
    {
        if (!in_array($event, Extensions::EVENTS, true) && !preg_match(Extensions::EXT_EVENT_PATTERN, $event)) {
            throw new \InvalidArgumentException("Unknown event {$event}.");
        }
        $this->listeners[$event][] = $handler;
    }

    /**
     * Broadcasts `ext.<this id>.<name>` to every listener. Call it only after your own changes are
     * committed: like core events, listeners run inline and their failures are logged and ignored.
     *
     * @param array<string, mixed> $payload
     */
    public function emit(string $name, array $payload): void
    {
        if (!preg_match('/^[a-z][a-z0-9_]{0,40}$/', $name)) {
            throw new \InvalidArgumentException('Event names must be lowercase letters, digits and _.');
        }
        if ($this->emitter !== null) {
            ($this->emitter)("ext.{$this->id}.{$name}", $payload);
        }
    }

    /**
     * The table name of another extension this one declared in its manifest's `reads` list.
     * Read it only: never write to another extension's tables.
     */
    public function readTable(string $extId, string $suffix): string
    {
        $other = $this->readable[$extId] ?? null;
        if ($other === null) {
            throw new \RuntimeException("Extension {$this->id} did not declare \"reads\": [\"{$extId}\"], or {$extId} is not loaded.");
        }
        return $other->table($suffix);
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

    /**
     * This hub's canonical URL (from `hub_url` in config.php), without a trailing slash, or ''.
     */
    public function hubUrl(): string
    {
        $inst = is_array($this->config['instance'] ?? null) ? $this->config['instance'] : [];
        return rtrim((string) ($inst['directoryUrl'] ?? ''), '/');
    }

    /**
     * This hub's display title (from admin settings, config.php, or 'Webring Hub').
     */
    public function hubTitle(): string
    {
        $inst = is_array($this->config['instance'] ?? null) ? $this->config['instance'] : [];
        $meta = Database::getInstanceMeta($this->pdo, Database::normalizeInstanceMeta($inst, Database::DEFAULT_INSTANCE_META));
        $title = trim((string) ($meta['title'] ?? ''));
        return $title !== '' ? $title : Database::DEFAULT_INSTANCE_META['title'];
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
     * private addresses, size and time limits). Optional conditional request headers,
     * budgetMs, and deadline may be supplied; security flags always come from hub config.
     *
     * @param array{headers?: list<string>, budgetMs?: int, deadline?: float, maxBytes?: int} $options
     * @return array{url: string, status: int, headers: array<string, string>, json: mixed, jsonObject: mixed, rawBody: string}
     */
    public function fetchJson(string $url, array $options = []): array
    {
        $merged = [
            'allowInsecure' => !empty($this->config['allow_insecure']),
            'allowPrivate' => !empty($this->config['allow_private_hosts']),
            'timeoutMs' => (int) ($this->config['refresh_timeout_ms'] ?? 8000),
            'budgetMs' => max(1000, (int) ($options['budgetMs'] ?? $this->config['fetch_budget_ms'] ?? 15000)),
            'maxBytes' => min(Site::MAX_JSON_BYTES, max(1024, (int) ($options['maxBytes'] ?? Site::MAX_JSON_BYTES))),
        ];
        if (isset($options['headers']) && is_array($options['headers'])) {
            $merged['headers'] = $options['headers'];
        }
        if (isset($options['deadline']) && is_float($options['deadline'])) {
            $merged['deadline'] = $options['deadline'];
        }
        return Net::safeGetJson($url, $merged);
    }

    public function fetchBudgetMs(): int
    {
        return max(1000, (int) ($this->config['fetch_budget_ms'] ?? Site::DEFAULT_BUDGET_MS));
    }

    /**
     * @param array{admin?: bool, rateLimited?: bool, userAuth?: bool, requireOptIn?: bool} $opts
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
            'userAuth' => (bool) ($opts['userAuth'] ?? false),
            'requireOptIn' => (bool) ($opts['requireOptIn'] ?? false),
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
     * @param string $clientKey a salted hash identifying the caller's IP (never the IP itself),
     *                          for per-client limits; '' when unknown
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query,
        private $readBody,
        public readonly string $clientKey = '',
        public readonly ?string $userDid = null,
        public readonly bool $userOptedIn = false
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

    public const PUBLIC_MIME_TYPES = [
        'html' => 'text/html; charset=utf-8',
        'htm' => 'text/html; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'mjs' => 'text/javascript; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'avif' => 'image/avif',
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
        'mp3' => 'audio/mpeg',
        'ogg' => 'audio/ogg',
        'wav' => 'audio/wav',
        'm4a' => 'audio/mp4',
        'wasm' => 'application/wasm',
        'glb' => 'model/gltf-binary',
        'gltf' => 'model/gltf+json',
        'bin' => 'application/octet-stream',
        'txt' => 'text/plain; charset=utf-8',
        'map' => 'application/json; charset=utf-8',
    ];

    public const EVENTS = [
        'entry.verified',
        'entry.key_rejected',
        'entry.removed',
        'entry.status_changed',
        'cron.refresh',
        'user.extension_opt_out',
    ];

    /** Events extensions emit themselves: `ext.<id>.<name>`. */
    public const EXT_EVENT_PATTERN = '/^ext\.[a-z][a-z0-9-]{1,40}\.[a-z][a-z0-9_]{0,40}$/';

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
        $pending = [];
        foreach ($ids as $id) {
            if (is_string($id) && !in_array($id, $self->enabled, true)) {
                $self->enabled[] = $id;
                $pending[] = $id;
            }
        }
        // Extensions that declare `reads` load after the ones they read, whatever the config order.
        while ($pending !== []) {
            $deferred = [];
            foreach ($pending as $id) {
                try {
                    $self->loadOne($id, $config, $pdo);
                    unset($self->errors[$id]);
                } catch (ExtensionDependencyPending $wait) {
                    $deferred[] = $id;
                    $self->errors[$id] = $wait->getMessage();
                } catch (\Throwable $err) {
                    $self->errors[$id] = $err->getMessage() ?: 'Could not load the extension.';
                }
            }
            if (count($deferred) === count($pending)) {
                break; // no progress: a dependency failed to load
            }
            $pending = $deferred;
        }
        foreach ($self->errors as $id => $message) {
            error_log("[webring-hub-php] extension {$id}: " . $message);
        }
        return $self;
    }

    /**
     * Extensions that loaded, for `/api/info`.
     *
     * @return list<array<string, mixed>>
     */
    public function publicList(): array
    {
        $out = [];
        foreach ($this->loaded as $id => $ctx) {
            $entry = [
                'id' => $id,
                'version' => (string) ($ctx->manifest['version'] ?? '0.0.0'),
                'apiVersion' => (int) ($ctx->manifest['apiVersion'] ?? self::API_VERSION),
                'kind' => $ctx->ui['kind'],
            ];
            if ($ctx->ui['public'] !== null) {
                $entry['ui'] = ['public' => $ctx->ui['public']];
            }
            $ud = $ctx->manifest['userData'] ?? null;
            if (is_array($ud) && !empty($ud['optInRequired'])) {
                $entry['userData'] = [
                    'optInRequired' => true,
                    'summary' => mb_substr(trim((string) ($ud['summary'] ?? 'Stores user-specific data under your DID.')), 0, 240),
                ];
            }
            $out[] = $entry;
        }
        return $out;
    }

    /**
     * Loaded extensions that declare `userData.optInRequired: true`.
     *
     * @return list<array{id: string, name: string, summary: string}>
     */
    public function userDataExtensions(): array
    {
        $out = [];
        foreach ($this->loaded as $id => $ctx) {
            $ud = $ctx->manifest['userData'] ?? null;
            if (is_array($ud) && !empty($ud['optInRequired'])) {
                $out[] = [
                    'id' => $id,
                    'name' => (string) ($ctx->manifest['name'] ?? $id),
                    'summary' => mb_substr(trim((string) ($ud['summary'] ?? 'Stores user-specific data under your DID.')), 0, 240),
                ];
            }
        }
        return $out;
    }

    /**
     * Public carousel cards for enabled user-facing extensions (server-side home page rendering).
     *
     * @return list<array{id: string, title: string, tagline: string, href: string, cta: string, art: string, accent: string}>
     */
    public function publicCards(): array
    {
        $cards = [];
        foreach ($this->loaded as $id => $ctx) {
            if ($ctx->ui['public'] !== null) {
                $pub = $ctx->ui['public'];
                $cards[] = [
                    'id' => $id,
                    'title' => (string) $pub['title'],
                    'tagline' => (string) $pub['tagline'],
                    'href' => (string) $pub['href'],
                    'cta' => (string) $pub['cta'],
                    'art' => (string) $pub['art'],
                    'accent' => (string) $pub['accent'],
                ];
            }
        }
        return $cards;
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
                'kind' => $ctx ? $ctx->ui['kind'] : 'headless',
                'panels' => $ctx ? $ctx->ui['panels'] : [],
                'uiError' => $ctx ? $ctx->ui['error'] : null,
                'routes' => $ctx ? array_map(static fn ($r) => $r['method'] . ' ' . $r['pattern'], $ctx->routes) : [],
                'public' => $ctx ? array_values(array_map(static fn ($p) => $p['mountPath'] . '/', $ctx->publicRoutes)) : [],
                'events' => $ctx ? array_keys($ctx->listeners) : [],
                'error' => $this->errors[$id] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Scans `extensions/<id>/extension.json` for installed extensions that are not currently
     * enabled. Reads JSON only and never loads or executes any PHP.
     *
     * @return list<array{id: string, name: string, version: string, description: string, kind: string, configLine: string, problem: ?string}>
     */
    public function availableList(): array
    {
        if ($this->root === '' || !is_dir($this->root)) {
            return [];
        }
        $entries = @scandir($this->root);
        if (!is_array($entries)) {
            return [];
        }
        $enabledSet = array_flip($this->enabled);
        $out = [];
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            $dir = $this->root . '/' . $entry;
            if (!is_dir($dir)) {
                continue;
            }
            if (isset($enabledSet[$entry])) {
                continue;
            }
            $manifestPath = $dir . '/extension.json';
            if (!is_file($manifestPath)) {
                continue;
            }

            $problem = null;
            $name = $entry;
            $version = '';
            $description = '';
            $kind = 'headless';

            if (!preg_match('/^[a-z][a-z0-9-]{1,40}$/', $entry)) {
                $problem = 'Folder name is not a valid extension id.';
            } else {
                $raw = @file_get_contents($manifestPath);
                $manifest = is_string($raw) ? json_decode($raw, true) : null;
                if (!is_array($manifest)) {
                    $problem = 'extension.json is not a valid JSON object.';
                } else {
                    if (($manifest['id'] ?? null) !== $entry) {
                        $problem = 'extension.json id does not match folder name.';
                    } elseif ((int) ($manifest['apiVersion'] ?? 0) !== self::API_VERSION) {
                        $problem = 'Unsupported apiVersion (this hub supports ' . self::API_VERSION . ').';
                    } elseif (!is_string($manifest['class'] ?? null) || !preg_match('/^[A-Za-z_][A-Za-z0-9_\\\\]*$/', $manifest['class'])) {
                        $problem = 'extension.json must name the extension class.';
                    } elseif (!is_file($dir . '/Extension.php')) {
                        $problem = 'Missing Extension.php.';
                    }
                    $name = is_string($manifest['name'] ?? null) && $manifest['name'] !== '' ? $manifest['name'] : $entry;
                    $version = is_string($manifest['version'] ?? null) ? $manifest['version'] : '';
                    $description = is_string($manifest['description'] ?? null) ? $manifest['description'] : '';
                    $kind = ExtensionUi::inferKindFromManifest($manifest);
                }
            }

            $enabledWithThis = array_values(array_unique(array_merge($this->enabled, [$entry])));
            $quoted = array_map(static fn (string $x): string => "'" . addslashes($x) . "'", $enabledWithThis);
            $configLine = "'extensions' => [" . implode(', ', $quoted) . "],";

            $out[] = [
                'id' => $entry,
                'name' => $name,
                'version' => $version,
                'description' => $description,
                'kind' => $kind,
                'configLine' => $configLine,
                'problem' => $problem,
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['id'], $b['id']));
        return $out;
    }

    public function isLoaded(string $id): bool
    {
        return isset($this->loaded[$id]);
    }

    public function context(string $id): ?ExtensionContext
    {
        return $this->loaded[$id] ?? null;
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
                    return ['route' => $route, 'params' => $params, 'extensionId' => $ctx->id];
                }
            }
        }
        return null;
    }

    /**
     * Resolves a GET/HEAD request under `/ext/<id>/...` to a validated static file inside an
     * enabled extension's registered `serveFiles()` directory.
     *
     * @return array{filePath: string, mimeType: string, etag: string, headers: array<string, string>, frameable: bool}|null
     */
    public function resolvePublicFile(string $pathname): ?array
    {
        if (!str_starts_with($pathname, '/ext/')) {
            return null;
        }
        if (
            str_contains($pathname, "\0") ||
            preg_match('/%(?:2e|2f|5c|00)/i', $pathname) ||
            str_contains($pathname, '\\')
        ) {
            throw new AppError(400, 'invalid_path', 'Malformed URL path.');
        }

        $decoded = rawurldecode($pathname);
        if (str_contains($decoded, "\0") || str_contains($decoded, '\\')) {
            throw new AppError(400, 'invalid_path', 'Malformed URL path.');
        }
        foreach (explode('/', $decoded) as $seg) {
            if ($seg === '..' || ($seg !== '' && str_starts_with($seg, '.'))) {
                throw new AppError(403, 'forbidden', 'Forbidden path.');
            }
        }

        foreach ($this->loaded as $ctx) {
            foreach ($ctx->publicRoutes as $route) {
                $mount = $route['mountPath'];
                if ($decoded !== $mount && !str_starts_with($decoded, $mount . '/')) {
                    continue;
                }
                $rel = ltrim(substr($decoded, strlen($mount)), '/');
                if ($rel === '' || str_ends_with($decoded, '/')) {
                    $rel = ($rel === '' ? '' : rtrim($rel, '/') . '/') . $route['index'];
                }
                $candidate = $route['realDir'] . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
                if (is_dir($candidate)) {
                    $candidate = rtrim($candidate, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $route['index'];
                    $rel = rtrim($rel, '/') . '/' . $route['index'];
                }
                $realFile = realpath($candidate);
                $realRootPrefix = rtrim($route['realDir'], DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
                if ($realFile === false || !is_file($realFile) || !str_starts_with($realFile, $realRootPrefix)) {
                    throw new AppError(404, 'not_found', 'Not found.');
                }
                // Check every segment inside realDir for hidden dotfiles
                $insideRel = str_replace(DIRECTORY_SEPARATOR, '/', substr($realFile, strlen($realRootPrefix)));
                foreach (explode('/', $insideRel) as $part) {
                    if ($part !== '' && str_starts_with($part, '.')) {
                        throw new AppError(403, 'forbidden', 'Forbidden path.');
                    }
                }
                $ext = strtolower(pathinfo($realFile, PATHINFO_EXTENSION));
                $mime = $route['allowedExtensions'][$ext] ?? null;
                if ($mime === null) {
                    throw new AppError(403, 'forbidden', 'File type is not allowed.');
                }

                $mtime = (int) @filemtime($realFile);
                $size = (int) @filesize($realFile);
                $etag = '"' . substr(hash('sha256', $realFile . ':' . $mtime . ':' . $size), 0, 32) . '"';

                $headers = [
                    'Cache-Control' => $route['cache'] > 0 ? "public, max-age={$route['cache']}" : 'no-cache',
                    'ETag' => $etag,
                ];
                if ($route['cors']) {
                    $headers['Access-Control-Allow-Origin'] = '*';
                }
                if ($ext === 'html' || $ext === 'htm') {
                    $headers['Cache-Control'] = 'no-cache';
                    if ($route['csp'] !== '') {
                        $headers['Content-Security-Policy'] = $route['csp'];
                    }
                } elseif ($ext === 'svg') {
                    $headers['Content-Security-Policy'] = "default-src 'none'; style-src 'unsafe-inline'";
                }

                if (is_callable($route['headers'])) {
                    $extra = ($route['headers'])($insideRel, $mime);
                    if (is_array($extra)) {
                        foreach ($extra as $k => $v) {
                            $headers[(string) $k] = (string) $v;
                        }
                    }
                } elseif (is_array($route['headers'])) {
                    foreach ($route['headers'] as $k => $v) {
                        $headers[(string) $k] = (string) $v;
                    }
                }

                return [
                    'filePath' => $realFile,
                    'mimeType' => $mime,
                    'etag' => $etag,
                    'headers' => $headers,
                    'frameable' => $route['frameable'],
                ];
            }
        }

        return null;
    }

    /**
     * Calls a matched route's handler and normalises its result to `[status, body]`
     * (or `[status, body, headers]` when explicit response headers are supplied).
     *
     * @param array{route: array<string, mixed>, params: array<string, string>} $match
     * A handler may also return an ExtensionRawResponse, passed back as the fourth element.
     *
     * @return array{0: int, 1: array<string, mixed>, 2?: array<string, string>, 3?: ExtensionRawResponse}
     */
    public static function run(array $match, ExtensionRequest $req): array
    {
        $result = ($match['route']['handler'])($match['params'], $req);
        if ($result instanceof ExtensionRawResponse) {
            return [$result->status, [], [], $result];
        }
        if (is_array($result) && array_is_list($result)) {
            if (count($result) === 2 && is_int($result[0]) && is_array($result[1])) {
                return [$result[0], $result[1]];
            }
            if (count($result) === 3 && is_int($result[0]) && is_array($result[1]) && is_array($result[2])) {
                $headers = [];
                foreach ($result[2] as $k => $v) {
                    $headers[(string) $k] = (string) $v;
                }
                return [$result[0], $result[1], $headers];
            }
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
        $reads = $manifest['reads'] ?? [];
        if (!is_array($reads) || !array_is_list($reads)) {
            throw new \RuntimeException('extension.json "reads" must be a list of extension ids.');
        }
        $readable = [];
        foreach ($reads as $dep) {
            if (!is_string($dep) || !preg_match('/^[a-z][a-z0-9-]{1,40}$/', $dep) || $dep === $id) {
                throw new \RuntimeException('extension.json "reads" must list other extension ids.');
            }
            if (!in_array($dep, $this->enabled, true)) {
                throw new \RuntimeException("{$id} needs the {$dep} extension: add '{$dep}' to 'extensions' in config.php.");
            }
            if (!isset($this->loaded[$dep])) {
                throw new ExtensionDependencyPending("{$id} needs the {$dep} extension, which did not load.");
            }
            $readable[$dep] = $this->loaded[$dep];
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
        $ctx = new ExtensionContext($id, $manifest, $pdo, $config, $dir);
        $ctx->readable = $readable;
        $ctx->emitter = function (string $event, array $payload): void {
            $this->emit($event, $payload);
        };
        $instance->register($ctx);
        $ctx->ui = ExtensionUi::normalise($manifest, $ctx);
        $this->loaded[$id] = $ctx;
    }
}

/**
 * Validates and normalises the optional `ui` block in an extension's `extension.json`.
 * Any validation error causes the entire `ui` block to be ignored (returning kind `headless`
 * and an `error` message for `/admin`) while still allowing the extension itself to load.
 */
final class ExtensionUi
{
    public const ACCENTS = ['magenta', 'cyan', 'yellow', 'green', 'violet', 'orange'];
    public const ART_EXTENSIONS = ['svg', 'png', 'jpg', 'jpeg', 'webp'];
    public const PANEL_TYPES = ['stats', 'table', 'form'];
    public const COLUMN_RENDERERS = ['text', 'code', 'time', 'badge'];
    public const ACTION_METHODS = ['POST', 'DELETE'];

    public const MAX_PANELS = 12;
    public const MAX_COLUMNS = 12;
    public const MAX_ACTIONS = 6;
    public const MAX_FIELDS = 12;

    /**
     * Lightweight kind inference for un-loaded manifests (e.g. `availableList()`).
     *
     * @param array<string, mixed> $manifest
     */
    public static function inferKindFromManifest(array $manifest): string
    {
        $ui = $manifest['ui'] ?? null;
        if (!is_array($ui)) {
            return 'headless';
        }
        $hasPublic = is_array($ui['public'] ?? null);
        $hasAdmin = is_array($ui['admin'] ?? null) && is_array($ui['admin']['panels'] ?? null) && count($ui['admin']['panels']) > 0;
        if ($hasPublic && $hasAdmin) {
            return 'both';
        }
        if ($hasPublic) {
            return 'public';
        }
        if ($hasAdmin) {
            return 'admin';
        }
        return 'headless';
    }

    /**
     * @param array<string, mixed> $manifest
     * @return array{kind: string, public: ?array<string, mixed>, panels: list<array<string, mixed>>, error: ?string}
     */
    public static function normalise(array $manifest, ExtensionContext $ctx): array
    {
        if (!array_key_exists('ui', $manifest) || $manifest['ui'] === null) {
            return ['kind' => 'headless', 'public' => null, 'panels' => [], 'error' => null];
        }
        $ui = $manifest['ui'];
        if (!is_array($ui) || (count($ui) > 0 && array_is_list($ui))) {
            return self::fail('ui must be a JSON object.');
        }

        $publicCard = null;
        if (array_key_exists('public', $ui) && $ui['public'] !== null) {
            if (!is_array($ui['public'])) {
                return self::fail('ui.public must be a JSON object.');
            }
            $res = self::normalisePublic($ui['public'], $ctx);
            if (is_string($res)) {
                return self::fail($res);
            }
            $publicCard = $res;
        }

        $panels = [];
        if (array_key_exists('admin', $ui) && $ui['admin'] !== null) {
            if (!is_array($ui['admin'])) {
                return self::fail('ui.admin must be a JSON object.');
            }
            $res = self::normaliseAdmin($ui['admin'], $ctx);
            if (is_string($res)) {
                return self::fail($res);
            }
            $panels = $res;
        }

        $hasPublic = $publicCard !== null;
        $hasAdmin = count($panels) > 0;
        $kind = ($hasPublic && $hasAdmin)
            ? 'both'
            : ($hasPublic ? 'public' : ($hasAdmin ? 'admin' : 'headless'));

        return [
            'kind' => $kind,
            'public' => $publicCard,
            'panels' => $panels,
            'error' => null,
        ];
    }

    /**
     * @return array{kind: string, public: null, panels: list<array<string, mixed>>, error: string}
     */
    private static function fail(string $reason): array
    {
        return [
            'kind' => 'headless',
            'public' => null,
            'panels' => [],
            'error' => $reason,
        ];
    }

    /**
     * @param array<string, mixed> $pub
     * @return array{title: string, tagline: string, href: string, cta: string, art: string, accent: string}|string
     */
    private static function normalisePublic(array $pub, ExtensionContext $ctx): array|string
    {
        $title = trim((string) ($pub['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 60) {
            return 'ui.public.title must be 1–60 characters.';
        }
        $tagline = trim((string) ($pub['tagline'] ?? ''));
        if (mb_strlen($tagline) > 160) {
            return 'ui.public.tagline must be at most 160 characters.';
        }
        $cta = trim((string) ($pub['cta'] ?? 'Open'));
        if ($cta === '' || mb_strlen($cta) > 32) {
            return 'ui.public.cta must be 1–32 characters.';
        }

        $href = (string) ($pub['href'] ?? '');
        $hrefPrefix = '/ext/' . $ctx->id . '/';
        if (
            !str_starts_with($href, $hrefPrefix)
            || !preg_match('#^/ext/' . preg_quote($ctx->id, '#') . '/[A-Za-z0-9._/-]*$#', $href)
            || str_contains($href, '..')
            || str_contains($href, '//')
            || mb_strlen($href) > 160
        ) {
            return "ui.public.href must stay under {$hrefPrefix}.";
        }
        foreach (explode('/', substr($href, strlen($hrefPrefix))) as $seg) {
            if ($seg !== '' && str_starts_with($seg, '.')) {
                return 'ui.public.href must not reference hidden files.';
            }
        }

        $accent = (string) ($pub['accent'] ?? 'cyan');
        if (!in_array($accent, self::ACCENTS, true)) {
            return 'ui.public.accent must be one of: ' . implode(', ', self::ACCENTS) . '.';
        }

        $artUrl = '';
        if (isset($pub['art']) && $pub['art'] !== '') {
            if (!is_string($pub['art'])) {
                return 'ui.public.art must be a relative file path.';
            }
            $art = $pub['art'];
            if (
                strlen($art) > 120
                || str_starts_with($art, '/')
                || str_contains($art, '..')
                || str_contains($art, '\\')
                || str_contains($art, "\0")
                || str_contains($art, '://')
                || !preg_match('#^[A-Za-z0-9][A-Za-z0-9._/-]*$#', $art)
            ) {
                return 'ui.public.art must be a relative path inside the extension public folder.';
            }
            foreach (explode('/', $art) as $seg) {
                if ($seg === '' || str_starts_with($seg, '.')) {
                    return 'ui.public.art must not contain empty or hidden path segments.';
                }
            }
            $ext = strtolower(pathinfo($art, PATHINFO_EXTENSION));
            if (!in_array($ext, self::ART_EXTENSIONS, true)) {
                return 'ui.public.art must be an image file (' . implode(', ', self::ART_EXTENSIONS) . ').';
            }
            $artUrl = '/ext/' . $ctx->id . '/' . $art;
        }

        return [
            'title' => $title,
            'tagline' => $tagline,
            'href' => $href,
            'cta' => $cta,
            'art' => $artUrl,
            'accent' => $accent,
        ];
    }

    /**
     * @param array<string, mixed> $admin
     * @return list<array<string, mixed>>|string
     */
    private static function normaliseAdmin(array $admin, ExtensionContext $ctx): array|string
    {
        $rawPanels = $admin['panels'] ?? null;
        if (!is_array($rawPanels) || !array_is_list($rawPanels) || count($rawPanels) === 0) {
            return 'ui.admin.panels must be a non-empty list.';
        }
        if (count($rawPanels) > self::MAX_PANELS) {
            return 'ui.admin.panels exceeds the maximum of ' . self::MAX_PANELS . ' panels.';
        }

        $seenIds = [];
        $panels = [];
        foreach ($rawPanels as $idx => $p) {
            if (!is_array($p)) {
                return "ui.admin.panels[{$idx}] must be an object.";
            }
            $pid = (string) ($p['id'] ?? '');
            if (!preg_match('/^[a-z][a-z0-9_-]{0,40}$/', $pid)) {
                return "ui.admin.panels[{$idx}].id is invalid.";
            }
            if (isset($seenIds[$pid])) {
                return "Duplicate panel id \"{$pid}\".";
            }
            $seenIds[$pid] = true;

            $title = trim((string) ($p['title'] ?? ''));
            if ($title === '' || mb_strlen($title) > 80) {
                return "Panel \"{$pid}\" title must be 1–80 characters.";
            }
            $description = trim((string) ($p['description'] ?? ''));
            if (mb_strlen($description) > 240) {
                return "Panel \"{$pid}\" description must be at most 240 characters.";
            }

            $type = (string) ($p['type'] ?? '');
            if (!in_array($type, self::PANEL_TYPES, true)) {
                return "Panel \"{$pid}\" has unsupported type \"{$type}\".";
            }

            if ($type === 'stats') {
                $panel = self::normaliseStatsPanel($pid, $title, $description, $p, $ctx);
            } elseif ($type === 'table') {
                $panel = self::normaliseTablePanel($pid, $title, $description, $p, $ctx);
            } else {
                $panel = self::normaliseFormPanel($pid, $title, $description, $p, $ctx);
            }
            if (is_string($panel)) {
                return $panel;
            }
            $panels[] = $panel;
        }

        return $panels;
    }

    /**
     * @param array<string, mixed> $p
     * @return array<string, mixed>|string
     */
    private static function normaliseStatsPanel(string $pid, string $title, string $description, array $p, ExtensionContext $ctx): array|string
    {
        $source = self::validateAdminRouteRef((string) ($p['source'] ?? ''), 'GET', $ctx, true);
        if ($source === null) {
            return "Panel \"{$pid}\" source must match an admin GET route registered by this extension.";
        }
        $items = [];
        if (isset($p['items'])) {
            if (!is_array($p['items']) || !array_is_list($p['items']) || count($p['items']) > self::MAX_COLUMNS) {
                return "Panel \"{$pid}\" items must be a list of at most " . self::MAX_COLUMNS . ' entries.';
            }
            foreach ($p['items'] as $item) {
                $col = self::normaliseColumn($item);
                if (is_string($col)) {
                    return "Panel \"{$pid}\": {$col}";
                }
                $items[] = $col;
            }
        }
        return [
            'id' => $pid,
            'title' => $title,
            'description' => $description,
            'type' => 'stats',
            'source' => $source,
            'items' => $items,
        ];
    }

    /**
     * @param array<string, mixed> $p
     * @return array<string, mixed>|string
     */
    private static function normaliseTablePanel(string $pid, string $title, string $description, array $p, ExtensionContext $ctx): array|string
    {
        $source = self::validateAdminRouteRef((string) ($p['source'] ?? ''), 'GET', $ctx, true);
        if ($source === null) {
            return "Panel \"{$pid}\" source must match an admin GET route registered by this extension.";
        }
        $rawCols = $p['columns'] ?? null;
        if (!is_array($rawCols) || !array_is_list($rawCols) || count($rawCols) === 0 || count($rawCols) > self::MAX_COLUMNS) {
            return "Panel \"{$pid}\" columns must contain 1–" . self::MAX_COLUMNS . ' entries.';
        }
        $columns = [];
        foreach ($rawCols as $c) {
            $col = self::normaliseColumn($c);
            if (is_string($col)) {
                return "Panel \"{$pid}\": {$col}";
            }
            $columns[] = $col;
        }

        $itemsKey = (string) ($p['itemsKey'] ?? 'items');
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,39}$/', $itemsKey)) {
            return "Panel \"{$pid}\" itemsKey is invalid.";
        }
        $rowKey = (string) ($p['rowKey'] ?? '');
        if ($rowKey !== '' && !preg_match('/^[a-zA-Z][a-zA-Z0-9_]{0,39}$/', $rowKey)) {
            return "Panel \"{$pid}\" rowKey is invalid.";
        }
        $emptyMessage = trim((string) ($p['emptyMessage'] ?? 'No items.'));
        if (mb_strlen($emptyMessage) > 160) {
            return "Panel \"{$pid}\" emptyMessage must be at most 160 characters.";
        }

        $actions = [];
        if (isset($p['actions'])) {
            if (!is_array($p['actions']) || !array_is_list($p['actions']) || count($p['actions']) > self::MAX_ACTIONS) {
                return "Panel \"{$pid}\" actions must be a list of at most " . self::MAX_ACTIONS . ' actions.';
            }
            foreach ($p['actions'] as $a) {
                $act = self::normaliseAction($a, $ctx);
                if (is_string($act)) {
                    return "Panel \"{$pid}\": {$act}";
                }
                $actions[] = $act;
            }
        }

        return [
            'id' => $pid,
            'title' => $title,
            'description' => $description,
            'type' => 'table',
            'source' => $source,
            'itemsKey' => $itemsKey,
            'rowKey' => $rowKey,
            'emptyMessage' => $emptyMessage,
            'columns' => $columns,
            'actions' => $actions,
        ];
    }

    /**
     * @param array<string, mixed> $p
     * @return array<string, mixed>|string
     */
    private static function normaliseFormPanel(string $pid, string $title, string $description, array $p, ExtensionContext $ctx): array|string
    {
        $method = strtoupper((string) ($p['method'] ?? 'POST'));
        if (!in_array($method, self::ACTION_METHODS, true)) {
            return "Panel \"{$pid}\" form method must be POST or DELETE.";
        }
        $path = self::validateAdminRouteRef((string) ($p['path'] ?? ''), $method, $ctx, false);
        if ($path === null) {
            return "Panel \"{$pid}\" path must match an admin {$method} route registered by this extension.";
        }
        $submitLabel = trim((string) ($p['submitLabel'] ?? 'Submit'));
        if ($submitLabel === '' || mb_strlen($submitLabel) > 40) {
            return "Panel \"{$pid}\" submitLabel must be 1–40 characters.";
        }
        $confirm = trim((string) ($p['confirm'] ?? ''));
        if (mb_strlen($confirm) > 200) {
            return "Panel \"{$pid}\" confirm must be at most 200 characters.";
        }
        $rawFields = $p['fields'] ?? null;
        if (!is_array($rawFields) || !array_is_list($rawFields) || count($rawFields) === 0 || count($rawFields) > self::MAX_FIELDS) {
            return "Panel \"{$pid}\" fields must contain 1–" . self::MAX_FIELDS . ' entries.';
        }
        $fields = [];
        foreach ($rawFields as $f) {
            if (!is_array($f)) {
                return "Panel \"{$pid}\" field must be an object.";
            }
            $fname = (string) ($f['name'] ?? '');
            if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,40}$/', $fname)) {
                return "Panel \"{$pid}\" has invalid field name.";
            }
            $flabel = trim((string) ($f['label'] ?? ''));
            if ($flabel === '' || mb_strlen($flabel) > 60) {
                return "Panel \"{$pid}\" field \"{$fname}\" label must be 1–60 characters.";
            }
            $placeholder = trim((string) ($f['placeholder'] ?? ''));
            if (mb_strlen($placeholder) > 100) {
                return "Panel \"{$pid}\" field \"{$fname}\" placeholder is too long.";
            }
            $fields[] = [
                'name' => $fname,
                'label' => $flabel,
                'placeholder' => $placeholder,
                'required' => !empty($f['required']),
            ];
        }

        return [
            'id' => $pid,
            'title' => $title,
            'description' => $description,
            'type' => 'form',
            'method' => $method,
            'path' => $path,
            'submitLabel' => $submitLabel,
            'confirm' => $confirm,
            'fields' => $fields,
        ];
    }

    /**
     * @return array{key: string, label: string, as: string}|string
     */
    private static function normaliseColumn(mixed $raw): array|string
    {
        if (!is_array($raw)) {
            return 'column must be an object.';
        }
        $key = (string) ($raw['key'] ?? '');
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]{0,39}$/', $key)) {
            return 'column key is invalid.';
        }
        $label = trim((string) ($raw['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 40) {
            return "column \"{$key}\" label must be 1–40 characters.";
        }
        $as = (string) ($raw['as'] ?? 'text');
        if (!in_array($as, self::COLUMN_RENDERERS, true)) {
            return "column \"{$key}\" renderer \"{$as}\" is not supported.";
        }
        return ['key' => $key, 'label' => $label, 'as' => $as];
    }

    /**
     * @return array<string, mixed>|string
     */
    private static function normaliseAction(mixed $raw, ExtensionContext $ctx): array|string
    {
        if (!is_array($raw)) {
            return 'action must be an object.';
        }
        $label = trim((string) ($raw['label'] ?? ''));
        if ($label === '' || mb_strlen($label) > 40) {
            return 'action label must be 1–40 characters.';
        }
        $method = strtoupper((string) ($raw['method'] ?? 'POST'));
        if (!in_array($method, self::ACTION_METHODS, true)) {
            return "action \"{$label}\" method must be POST or DELETE.";
        }
        $path = self::validateAdminRouteRef((string) ($raw['path'] ?? ''), $method, $ctx, false);
        if ($path === null) {
            return "action \"{$label}\" path must match an admin {$method} route registered by this extension.";
        }
        $body = null;
        if (array_key_exists('body', $raw) && $raw['body'] !== null) {
            if (!is_array($raw['body']) || (count($raw['body']) > 0 && array_is_list($raw['body']))) {
                return "action \"{$label}\" body must be a JSON object.";
            }
            $body = $raw['body'];
        }
        $when = null;
        if (isset($raw['when']) && $raw['when'] !== null) {
            if (!is_array($raw['when']) || !is_string($raw['when']['key'] ?? null) || !array_key_exists('equals', $raw['when'])) {
                return "action \"{$label}\" when must have key and equals.";
            }
            $eq = $raw['when']['equals'];
            if (!is_scalar($eq) && $eq !== null) {
                return "action \"{$label}\" when.equals must be a scalar or null.";
            }
            $when = ['key' => $raw['when']['key'], 'equals' => $eq];
        }
        $confirm = trim((string) ($raw['confirm'] ?? ''));
        if (mb_strlen($confirm) > 200) {
            return "action \"{$label}\" confirm must be at most 200 characters.";
        }
        $style = (string) ($raw['style'] ?? 'default');
        if (!in_array($style, ['default', 'danger'], true)) {
            return "action \"{$label}\" style must be default or danger.";
        }

        return [
            'label' => $label,
            'method' => $method,
            'path' => $path,
            'body' => $body,
            'when' => $when,
            'confirm' => $confirm,
            'style' => $style,
        ];
    }

    /**
     * Checks that a manifest route reference (e.g. `v1/admin/games` or `v1/admin/games/{slug}/enabled`)
     * matches a route registered by this extension with `admin === true` and the expected HTTP method.
     */
    private static function validateAdminRouteRef(string $rawRef, string $method, ExtensionContext $ctx, bool $allowQuery): ?string
    {
        $ref = trim($rawRef);
        if ($ref === '' || strlen($ref) > 160 || str_starts_with($ref, '/') || str_contains($ref, '..')) {
            return null;
        }
        $pathPart = $ref;
        $queryPart = '';
        if (str_contains($ref, '?')) {
            if (!$allowQuery) {
                return null;
            }
            [$pathPart, $queryPart] = explode('?', $ref, 2);
            if ($queryPart === '' || !preg_match('/^[A-Za-z0-9._=&%-]+$/', $queryPart)) {
                return null;
            }
        }
        $pathPart = trim($pathPart, '/');
        if (!preg_match('#^v[0-9]+(/[A-Za-z0-9._{}-]+)*$#', $pathPart)) {
            return null;
        }

        foreach ($ctx->routes as $route) {
            if ($route['admin'] !== true || $route['method'] !== $method) {
                continue;
            }
            if ($route['pattern'] === $pathPart) {
                return $queryPart !== '' ? ($pathPart . '?' . $queryPart) : $pathPart;
            }
            // Also allow matching segment-for-segment when placeholders match registered `{param}` slots
            $regSegs = explode('/', $route['pattern']);
            $refSegs = explode('/', $pathPart);
            if (count($regSegs) === count($refSegs)) {
                $matched = true;
                for ($i = 0; $i < count($regSegs); $i++) {
                    $rs = $regSegs[$i];
                    $qs = $refSegs[$i];
                    $isRegParam = preg_match('/^\{[a-zA-Z][a-zA-Z0-9]*\}$/', $rs) === 1;
                    if ($isRegParam) {
                        if ($qs === '') {
                            $matched = false;
                            break;
                        }
                    } elseif ($rs !== $qs) {
                        $matched = false;
                        break;
                    }
                }
                if ($matched) {
                    return $queryPart !== '' ? ($pathPart . '?' . $queryPart) : $pathPart;
                }
            }
        }

        return null;
    }
}
