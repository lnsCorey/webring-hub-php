<?php
declare(strict_types=1);

namespace WebringHub;

use PDO;

$appRoot = is_dir(__DIR__ . '/../src') ? dirname(__DIR__) : __DIR__;

require_once $appRoot . '/src/AppError.php';
require_once $appRoot . '/src/Config.php';
require_once $appRoot . '/src/Verify.php';
require_once $appRoot . '/src/Net.php';
require_once $appRoot . '/src/Site.php';
require_once $appRoot . '/src/Database.php';
require_once $appRoot . '/src/Template.php';
require_once $appRoot . '/src/Extensions.php';
require_once $appRoot . '/src/Auth.php';
require_once $appRoot . '/src/Entries.php';

const MAX_BODY_BYTES = 64 * 1024;
const NONCE_TTL_SEC = 15 * 60;
const MAX_CLOCK_SKEW_SEC = 10 * 60;

const CONTENT_SECURITY_POLICY = "default-src 'self'; img-src 'self' https: data:; style-src 'self'; script-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'";

const STATIC_ASSETS = [
    '/app.js' => ['app.js', 'text/javascript; charset=utf-8'],
    '/theme.js' => ['theme.js', 'text/javascript; charset=utf-8'],
    '/hub-auth.js' => ['hub-auth.js', 'text/javascript; charset=utf-8'],
    '/ext-carousel.js' => ['ext-carousel.js', 'text/javascript; charset=utf-8'],
    '/remove.js' => ['remove.js', 'text/javascript; charset=utf-8'],
    '/admin.js' => ['admin.js', 'text/javascript; charset=utf-8'],
    '/admin-panels.js' => ['admin-panels.js', 'text/javascript; charset=utf-8'],
    '/style.css' => ['style.css', 'text/css; charset=utf-8'],
    '/favicon.svg' => ['favicon.svg', 'image/svg+xml'],
    '/favicon.png' => ['favicon.png', 'image/png'],
    '/logo-mark.png' => ['logo-mark.png', 'image/png'],
];

/**
 * True when the visitor reached the hub over HTTPS, directly or through a TLS-terminating proxy or
 * CDN. Only used to decide whether to send HSTS, which browsers ignore on plain HTTP anyway, so a
 * spoofed X-Forwarded-Proto can't do harm here.
 */
function isHttpsRequest(): bool
{
    $https = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    if ($https !== '' && $https !== 'off') {
        return true;
    }
    return strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
}

function setCommonHeaders(bool $frameable = false): void
{
    if (function_exists('header_remove')) {
        header_remove('X-Powered-By');
    }
    if (isHttpsRequest()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    if (!$frameable) {
        header('X-Frame-Options: DENY');
    }
}

/**
 * @param array{filePath: string, mimeType: string, etag: string, headers: array<string, string>, frameable: bool} $resolved
 */
function sendExtensionFile(array $resolved, bool $headOnly = false): never
{
    $ifNoneMatch = trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''));
    setCommonHeaders($resolved['frameable']);
    foreach ($resolved['headers'] as $k => $v) {
        header("{$k}: {$v}");
    }
    if ($ifNoneMatch !== '' && $ifNoneMatch === $resolved['etag']) {
        http_response_code(304);
        exit;
    }
    $bytes = (string) file_get_contents($resolved['filePath']);
    http_response_code(200);
    header('Content-Type: ' . $resolved['mimeType']);
    header('Content-Length: ' . strlen($bytes));
    if (!$headOnly) {
        echo $bytes;
    }
    exit;
}

/**
 * @param array<string, mixed> $body
 * @param array<string, string> $extraHeaders
 */
function sendJson(int $status, array $body, array $extraHeaders = [], bool $headOnly = false): never
{
    $payload = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($payload === false) {
        $payload = '{"error":"internal_error","message":"JSON encoding failed."}';
    }
    http_response_code($status);
    setCommonHeaders();
    header('Content-Type: application/json; charset=utf-8');
    header('Content-Length: ' . strlen($payload));
    header('Cache-Control: no-store');
    foreach ($extraHeaders as $k => $v) {
        header("{$k}: {$v}");
    }
    if (!$headOnly) {
        echo $payload;
    }
    exit;
}

/**
 * @param array<string, string> $extraHeaders replace or add headers (e.g. a stricter Cache-Control)
 */
function sendHtml(int $status, string $html, bool $headOnly = false, array $extraHeaders = []): never
{
    http_response_code($status);
    setCommonHeaders();
    header('Content-Type: text/html; charset=utf-8');
    header('Content-Length: ' . strlen($html));
    header('Cache-Control: no-cache');
    header('Content-Security-Policy: ' . CONTENT_SECURITY_POLICY);
    foreach ($extraHeaders as $k => $v) {
        header("{$k}: {$v}");
    }
    if (!$headOnly) {
        echo $html;
    }
    exit;
}

function sendError(\Throwable $err): never
{
    $appError = AppError::fromThrowable($err);
    if ($appError->status >= 500) {
        error_log('[webring-hub-php] ' . $appError->errorCode . ': ' . ($appError->details ?: $appError->getMessage()));
    }
    $extraHeaders = [];
    if (isset($appError->extra['retryAfterSeconds'])) {
        $extraHeaders['Retry-After'] = (string) (int) $appError->extra['retryAfterSeconds'];
    }
    sendJson($appError->status, array_merge($appError->extra, [
        'error' => $appError->errorCode,
        'message' => $appError->getMessage(),
    ]), $extraHeaders);
}

/**
 * @return array<string, mixed>
 */
function readJsonBody(int $maxBytes = MAX_BODY_BYTES): array
{
    $raw = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if ($raw === false || $raw === '') {
        return [];
    }
    if (strlen($raw) > $maxBytes) {
        throw new AppError(413, 'body_too_large', 'Request body is too large.');
    }
    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        throw new AppError(400, 'invalid_json', 'Request body must be JSON.');
    }
    return $decoded;
}

function clientIp(bool $trustProxy, int $trustedProxyHops = 1): string
{
    return Net::resolveClientIp($_SERVER, $trustProxy, $trustedProxyHops);
}

function getAuthorizationHeader(): string
{
    if (!empty($_SERVER['HTTP_AUTHORIZATION']) && is_string($_SERVER['HTTP_AUTHORIZATION'])) {
        return $_SERVER['HTTP_AUTHORIZATION'];
    }
    if (!empty($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && is_string($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
        return $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
    }
    if (function_exists('getallheaders')) {
        foreach ((array) getallheaders() as $name => $val) {
            if (strtolower((string) $name) === 'authorization' && is_string($val)) {
                return $val;
            }
        }
    }
    return '';
}

/**
 * Checks the admin Bearer token. Failed attempts are limited per client IP (and an IP over the
 * limit is refused before its token is even compared), and mutating requests (POST/DELETE) are
 * limited per window. Successful reads don't count, so browsing the admin page can't lock the
 * operator out.
 *
 * @param array{window_seconds?: int, max_writes?: int, max_failures?: int} $limits
 */
function requireAdmin(string $adminToken, ?PDO $pdo = null, bool $trustProxy = false, int $trustedProxyHops = 1, array $limits = []): void
{
    if (strlen($adminToken) < 32) {
        throw new AppError(503, 'admin_disabled', 'Admin access is not configured.');
    }
    $window = max(60, (int) ($limits['window_seconds'] ?? 900));
    $maxFailures = max(3, (int) ($limits['max_failures'] ?? 10));
    $maxWrites = max(10, (int) ($limits['max_writes'] ?? 120));
    $ip = clientIp($trustProxy, $trustedProxyHops);
    if ($pdo !== null && Database::isRateLimited($pdo, 'admin_auth_fail:' . $ip, $window, $maxFailures)) {
        throw new AppError(429, 'rate_limited', 'Too many failed admin sign-in attempts. Try again later.');
    }
    $header = getAuthorizationHeader();
    if (!preg_match('/^Bearer\s+(.+)$/', $header, $matches) || !hash_equals($adminToken, $matches[1])) {
        if ($pdo !== null && !Database::checkRateLimit($pdo, 'admin_auth_fail:' . $ip, $window, $maxFailures)) {
            throw new AppError(429, 'rate_limited', 'Too many failed admin sign-in attempts. Try again later.');
        }
        throw new AppError(401, 'unauthorized', 'A valid admin token is required.');
    }
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($pdo !== null && ($method === 'POST' || $method === 'DELETE')
        && !Database::checkRateLimit($pdo, 'admin_write:' . $ip, $window, $maxWrites)) {
        throw new AppError(429, 'rate_limited', 'Too many admin changes in a short time. Try again in a few minutes.');
    }
}

function parseLimit(mixed $value, int $fallback, int $max): int
{
    if (!is_numeric($value)) {
        return $fallback;
    }
    $num = (int) $value;
    return min(max($num, 1), $max);
}

function decodeParam(string $value): string
{
    $decoded = rawurldecode($value);
    if ($decoded === '') {
        throw new AppError(400, 'invalid_path', 'Malformed URL path.');
    }
    return $decoded;
}

/**
 * @param array<string, mixed> $body
 */
function validateSettingsPatch(array $body): void
{
    if (array_is_list($body) && count($body) > 0) {
        throw new AppError(400, 'invalid_request', 'Send a JSON object with instance settings.');
    }
    $stringKeys = [
        'title',
        'description',
        'bannerUrl',
        'directoryUrl',
        'maintainerName',
        'maintainerUrl',
        'maintainerDid',
    ];
    foreach ($stringKeys as $key) {
        if (!array_key_exists($key, $body)) {
            continue;
        }
        if ($body[$key] !== null && !is_string($body[$key])) {
            throw new AppError(400, 'invalid_request', "{$key} must be a string or null.");
        }
    }
    if (array_key_exists('maintainerBadge', $body)) {
        $val = $body['maintainerBadge'];
        if ($val !== null && !is_bool($val) && !is_string($val)) {
            throw new AppError(400, 'invalid_request', 'maintainerBadge must be a boolean or null.');
        }
    }
    if (isset($body['bannerUrl']) && is_string($body['bannerUrl']) && trim($body['bannerUrl']) !== '' && Database::cleanBannerUrl($body['bannerUrl']) === '') {
        throw new AppError(
            400,
            'invalid_banner_url',
            'bannerUrl must be an http(s) URL or a root-relative path starting with /.'
        );
    }
    if (isset($body['directoryUrl']) && is_string($body['directoryUrl']) && trim($body['directoryUrl']) !== '' && Verify::normalizeSiteUrl($body['directoryUrl']) === '') {
        throw new AppError(400, 'invalid_directory_url', 'directoryUrl must be a valid http(s) site URL.');
    }
    if (isset($body['maintainerUrl']) && is_string($body['maintainerUrl']) && trim($body['maintainerUrl']) !== '' && Verify::normalizeSiteUrl($body['maintainerUrl']) === '') {
        throw new AppError(400, 'invalid_maintainer_url', 'maintainerUrl must be a valid http(s) site URL.');
    }
    if (isset($body['maintainerDid']) && is_string($body['maintainerDid']) && trim($body['maintainerDid']) !== '' && Database::cleanDid($body['maintainerDid']) === '') {
        throw new AppError(400, 'invalid_maintainer_did', 'maintainerDid must be a valid DID (e.g. did:web:example.com).');
    }
}

/**
 * Resolves the request path relative to the application directory so installations at either
 * the domain root (`/`) or a subdirectory (`/webring`) work identically.
 */
function resolveRequestPath(): string
{
    $requestUri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    $pathname = (string) (parse_url($requestUri, PHP_URL_PATH) ?: '/');
    $scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? '/index.php');
    // PHP's built-in server sets SCRIPT_NAME to the request path when its last segment looks like
    // a file name (a DID such as did:web:alice.example contains a dot), so only trust a real script.
    if (!str_ends_with(strtolower($scriptName), '.php')) {
        $scriptName = '/index.php';
    }
    $scriptDir = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');
    if ($scriptDir !== ''&& $scriptDir !== '/' && str_starts_with($pathname, $scriptDir)) {
        $pathname = substr($pathname, strlen($scriptDir)) ?: '/';
    }
    return $pathname;
}

try {
    $config = Config::load();
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $pathname = resolveRequestPath();

    // Serve static assets when running under PHP's built-in CLI web server (`php -S`)
    if (($method === 'GET' || $method === 'HEAD') && isset(STATIC_ASSETS[$pathname])) {
        [$fileName, $contentType] = STATIC_ASSETS[$pathname];
        $filePath = __DIR__ . '/' . $fileName;
        if (is_file($filePath)) {
            $bytes = (string) file_get_contents($filePath);
            http_response_code(200);
            setCommonHeaders();
            header('Content-Type: ' . $contentType);
            header('Content-Length: ' . strlen($bytes));
            header('Cache-Control: public, max-age=300');
            if ($method !== 'HEAD') {
                echo $bytes;
            }
            exit;
        }
    }

    if (($method === 'GET' || $method === 'HEAD') && $pathname === '/favicon.ico') {
        http_response_code(204);
        setCommonHeaders();
        exit;
    }

    if ($method === 'OPTIONS') {
        http_response_code(204);
        header('Allow: GET, HEAD, POST, DELETE, OPTIONS');
        exit;
    }

    $pdo = Database::open($config);

    $instanceCfg = is_array($config['instance'] ?? null) ? $config['instance'] : [];
    $defaultMeta = Database::normalizeInstanceMeta($instanceCfg, Database::DEFAULT_INSTANCE_META);
    $allowInsecure = !empty($config['allow_insecure']);
    $allowPrivate = !empty($config['allow_private_hosts']);
    $trustProxy = !empty($config['trust_proxy']);
    $trustedProxyHops = max(1, (int) ($config['trusted_proxy_hops'] ?? 1));
    $rateLimitEnabled = $config['rate_limit_enabled'] ?? true;
    $writeRateWindow = (int) ($config['write_rate_limit']['window_seconds'] ?? 3600);
    $writeRateMax = (int) ($config['write_rate_limit']['max'] ?? 30);
    $globalCrawlWindow = max(1, (int) ($config['global_crawl_rate_limit']['window_seconds'] ?? 60));
    $globalCrawlMax = max(1, (int) ($config['global_crawl_rate_limit']['max'] ?? 20));
    $refreshTimeoutMs = (int) ($config['refresh_timeout_ms'] ?? 8000);
    $fetchBudgetMs = max(1000, (int) ($config['fetch_budget_ms'] ?? 15000));
    $refreshCooldownSec = max(0, (int) ($config['refresh_cooldown_seconds'] ?? 300));
    $maxEntriesPerDomain = max(1, (int) ($config['max_entries_per_domain'] ?? 10));
    $adminToken = (string) ($config['admin_token'] ?? '');
    $adminLimits = is_array($config['admin_rate_limit'] ?? null) ? $config['admin_rate_limit'] : [];
    $extensions = Extensions::load($config, $pdo);

    $enforceWriteLimit = static function () use ($pdo, $rateLimitEnabled, $trustProxy, $trustedProxyHops, $writeRateWindow, $writeRateMax): void {
        if (!$rateLimitEnabled) {
            return;
        }
        $ip = clientIp($trustProxy, $trustedProxyHops);
        if (!Database::checkRateLimit($pdo, 'write:' . $ip, $writeRateWindow, $writeRateMax)) {
            throw new AppError(429, 'rate_limited', 'Too many requests. Try again later.');
        }
    };

    $enforceGlobalCrawlLimit = static function () use ($pdo, $rateLimitEnabled, $globalCrawlWindow, $globalCrawlMax): void {
        if (!$rateLimitEnabled) {
            return;
        }
        if (!Database::checkRateLimit($pdo, 'global:crawl', $globalCrawlWindow, $globalCrawlMax)) {
            throw new AppError(429, 'rate_limited', 'Hub is busy verifying sites. Try again shortly.');
        }
    };

    // Routes added by extensions live under /api/ext/<id>/ and never shadow core routes.
    // Every variable used inside must be listed in `use`: an undefined one here turned every
    // extension admin route into a 500 before phase 5 (scripts/smoke-router.php covers this).
    $dispatchExtension = static function () use (
        $extensions,
        $method,
        $pathname,
        $adminToken,
        $adminLimits,
        $enforceWriteLimit,
        $pdo,
        $trustProxy,
        $trustedProxyHops
    ): void {
        $match = $extensions->match($method, $pathname);
        if ($match === null) {
            return;
        }
        if ($match['route']['admin']) {
            requireAdmin($adminToken, $pdo, $trustProxy, $trustedProxyHops, $adminLimits);
        }
        if ($match['route']['rateLimited']) {
            $enforceWriteLimit();
        }
        $cookieToken = is_string($_COOKIE[Auth::SESSION_COOKIE] ?? null) ? $_COOKIE[Auth::SESSION_COOKIE] : '';
        $userSession = $cookieToken !== '' ? Auth::getSession($pdo, $cookieToken) : null;
        $needsUser = !empty($match['route']['userAuth']) || !empty($match['route']['requireOptIn']);
        if ($userSession !== null && !$needsUser && ($method === 'POST' || $method === 'DELETE')) {
            // A route that doesn't require sign-in still sees who is signed in, but a write may
            // only act as that member when it passes the CSRF check; otherwise it runs anonymously
            // so a cross-site form can't ride the session cookie.
            try {
                Auth::requireCsrf($userSession, $_SERVER);
            } catch (AppError) {
                $userSession = null;
            }
        }
        $userDid = $userSession !== null ? $userSession['did'] : null;
        $extId = (string) ($match['extensionId'] ?? '');
        $userOptedIn = ($userDid !== null && $extId !== '') ? Auth::isOptedIn($pdo, $userDid, $extId) : false;

        if ($needsUser) {
            if ($userSession === null) {
                throw new AppError(401, 'auth_required', 'Sign in with your Webring Studio DID to use this feature.');
            }
            if ($method === 'POST' || $method === 'DELETE') {
                Auth::requireCsrf($userSession, $_SERVER);
            }
            if (!empty($match['route']['requireOptIn']) && !$userOptedIn) {
                throw new AppError(403, 'opt_in_required', 'Opt in to this extension before recording data under your DID.');
            }
        }

        $request = new ExtensionRequest(
            $method,
            $pathname,
            $_GET,
            static fn (): array => readJsonBody(),
            Database::clientKeyHash($pdo, clientIp($trustProxy, $trustedProxyHops)),
            $userDid,
            $userOptedIn
        );
        $res = Extensions::run($match, $request);
        sendJson($res[0], $res[1], $res[2] ?? [], $method === 'HEAD');
    };

    if ($method === 'GET' || $method === 'HEAD') {
        $headOnly = $method === 'HEAD';

        if ($pathname === '/.well-known/did.json' || $pathname === '/did.json') {
            $meta = Database::getInstanceMeta($pdo, $defaultMeta);
            $didDoc = Database::buildInstanceDidDocument($pdo, $meta);
            if ($didDoc === null) {
                throw new AppError(404, 'not_found', 'Not found.');
            }
            sendJson(200, $didDoc, [
                'Cache-Control' => 'no-cache',
                'Access-Control-Allow-Origin' => '*',
            ], $headOnly);
        }

        if ($pathname === '/' || $pathname === '/index.html') {
            $meta = Database::getInstanceMeta($pdo, $defaultMeta);
            $html = Template::renderTemplateFile('index.html', $meta, $pdo, $extensions->publicCards());
            sendHtml(200, $html, $headOnly);
        }

        if ($pathname === '/remove' || $pathname === '/remove/' || $pathname === '/remove.html') {
            $meta = Database::getInstanceMeta($pdo, $defaultMeta);
            $html = Template::renderTemplateFile('remove.html', $meta, $pdo);
            sendHtml(200, $html, $headOnly);
        }

        // The admin page is a static shell: it holds no data and no secrets. admin.js asks for the
        // token and calls the /api/admin/* endpoints, which check it on every request.
        if ($pathname === '/admin' || $pathname === '/admin/') {
            $meta = Database::getInstanceMeta($pdo, $defaultMeta);
            $html = Template::renderTemplateFile('admin.html', $meta, $pdo);
            sendHtml(200, $html, $headOnly, [
                'Cache-Control' => 'no-store',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]);
        }

        if ($pathname === '/api/healthz') {
            sendJson(200, ['ok' => true], [], $headOnly);
        }

        // One-click direct-launch handoff from Webring Studio: exchanges a pre-approved launch code
        // for an HttpOnly session cookie and redirects to a safe local path (e.g. `/` or `/ext/arcade/`).
        if ($pathname === '/auth/claim') {
            $code = is_string($_GET['code'] ?? null) ? trim($_GET['code']) : '';
            $secret = is_string($_GET['secret'] ?? null) ? trim($_GET['secret']) : '';
            $next = is_string($_GET['next'] ?? null) ? trim($_GET['next']) : '/';
            if (!str_starts_with($next, '/') || str_starts_with($next, '//') || str_contains($next, "\r") || str_contains($next, "\n")) {
                $next = '/';
            }
            $claimed = Auth::claimChallenge($pdo, $code, $secret, $extensions);
            if (($claimed['status'] ?? '') !== 'authenticated' || empty($claimed['token'])) {
                throw new AppError(400, 'invalid_challenge', 'This sign-in link is not ready or has expired.');
            }
            Auth::setSessionCookie((string) $claimed['token'], isHttpsRequest());
            http_response_code(302);
            setCommonHeaders();
            header('Cache-Control: no-store');
            header('Location: ' . $next);
            exit;
        }

        if ($pathname === '/api/auth/me') {
            $cookieToken = is_string($_COOKIE[Auth::SESSION_COOKIE] ?? null) ? $_COOKIE[Auth::SESSION_COOKIE] : '';
            $session = $cookieToken !== '' ? Auth::getSession($pdo, $cookieToken) : null;
            if ($session === null) {
                sendJson(200, [
                    'authenticated' => false,
                    'user' => null,
                    'csrfToken' => null,
                    'consents' => [],
                ], [], $headOnly);
            }
            $entry = $session['entry'];
            sendJson(200, [
                'authenticated' => true,
                'user' => [
                    'did' => (string) $entry['did'],
                    'displayName' => (string) ($entry['display_name'] ?: $entry['did']),
                    'handle' => (string) ($entry['handle'] ?? ''),
                    'siteUrl' => (string) ($entry['site_url'] ?? ''),
                ],
                'csrfToken' => $session['csrfToken'],
                'expiresAt' => $session['expiresAt'],
                'consents' => Auth::listConsents($pdo, $session['did'], $extensions),
            ], [], $headOnly);
        }

        if ($pathname === '/api/info') {
            $meta = Database::getInstanceMeta($pdo, $defaultMeta);
            $info = Database::resolvePublicInfo($pdo, $meta);
            $info['extensions'] = $extensions->publicList();
            sendJson(200, $info, ['Access-Control-Allow-Origin' => '*'], $headOnly);
        }

        if ($pathname === '/api/entries') {
            $result = Database::listEntries($pdo, [
                'q' => (string) ($_GET['q'] ?? ''),
                'limit' => parseLimit($_GET['limit'] ?? null, 24, 100),
                'offset' => max((int) ($_GET['offset'] ?? 0), 0),
            ]);
            $entries = array_map([Database::class, 'toPublicEntry'], $result['entries']);
            sendJson(200, [
                'total' => $result['total'],
                'entries' => $entries,
            ], [], $headOnly);
        }

        if (preg_match('#^/api/entries/([^/]+)$#', $pathname, $matches)) {
            $did = decodeParam($matches[1]);
            $row = Database::getEntryByDid($pdo, $did);
            if ($row === null || ($row['status'] ?? '') !== 'active') {
                throw new AppError(404, 'not_found', 'No such entry.');
            }
            sendJson(200, ['entry' => Database::toPublicEntry($row)], [], $headOnly);
        }

        if ($pathname === '/api/nonce') {
            $enforceWriteLimit();
            $nowTs = time();
            $nowIso = gmdate('Y-m-d\TH:i:s.000\Z', $nowTs);
            $expiresIso = gmdate('Y-m-d\TH:i:s.000\Z', $nowTs + NONCE_TTL_SEC);
            $nonce = Database::createNonce($pdo, $nowIso);
            sendJson(200, [
                'nonce' => $nonce,
                'expiresAt' => $expiresIso,
            ], [], $headOnly);
        }

        if ($pathname === '/api/admin/extensions') {
            requireAdmin($adminToken, $pdo, $trustProxy, $trustedProxyHops, $adminLimits);
            sendJson(200, [
                'extensions' => $extensions->adminList(),
                'available' => $extensions->availableList(),
            ], [], $headOnly);
        }

        // Every entry whatever its status, with the operator-only fields from toAdminEntry.
        if ($pathname === '/api/admin/entries') {
            requireAdmin($adminToken, $pdo, $trustProxy, $trustedProxyHops, $adminLimits);
            $status = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
            if ($status !== '' && !in_array($status, ['active', 'blocked', 'stale'], true)) {
                throw new AppError(400, 'invalid_status', 'status must be active, blocked or stale.');
            }
            $result = Database::listEntriesForAdmin($pdo, [
                'status' => $status,
                'q' => is_string($_GET['q'] ?? null) ? $_GET['q'] : '',
                'limit' => parseLimit($_GET['limit'] ?? null, 50, 100),
                'offset' => max((int) ($_GET['offset'] ?? 0), 0),
            ]);
            sendJson(200, [
                'total' => $result['total'],
                'entries' => Database::toAdminEntries($pdo, $result['entries']),
            ], [], $headOnly);
        }

        // Operator warnings for the admin page: missing hub URL, IP salt kept in the database,
        // failed schema upgrades and the look-alike rows that block the collation upgrade.
        if ($pathname === '/api/admin/status') {
            requireAdmin($adminToken, $pdo, $trustProxy, $trustedProxyHops, $adminLimits);
            $meta = Database::getInstanceMeta($pdo, $defaultMeta);
            sendJson(200, [
                'hubUrlConfigured' => $meta['directoryUrl'] !== '',
                'hubUrl' => $meta['directoryUrl'],
                'ipSaltInDatabase' => Database::ipSaltInDatabase(),
                'maintenance' => Database::maintenanceStatus($pdo),
                'blockedWithoutListing' => Database::blockedSitesWithoutListing($pdo),
            ], [], $headOnly);
        }

        $dispatchExtension();

        $publicFile = $extensions->resolvePublicFile($pathname);
        if ($publicFile !== null) {
            sendExtensionFile($publicFile, $headOnly);
        }

        throw new AppError(404, 'not_found', 'Not found.');
    }

    if ($method === 'POST') {
        $rawContentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
        $mediaType = strtolower(trim(explode(';', $rawContentType, 2)[0]));
        if ($mediaType !== 'application/json') {
            throw new AppError(415, 'unsupported_media_type', 'Content-Type must be application/json.');
        }

        if ($pathname === '/api/auth/challenge') {
            $enforceWriteLimit();
            $meta = Database::getInstanceMeta($pdo, $defaultMeta);
            $body = readJsonBody();
            $requestedExt = is_string($body['extensionId'] ?? null) ? trim($body['extensionId']) : '';
            $ch = Auth::createChallenge($pdo, (string) ($meta['directoryUrl'] ?? ''), $requestedExt);
            sendJson(201, $ch);
        }

        if ($pathname === '/api/auth/verify') {
            $enforceWriteLimit();
            $meta = Database::getInstanceMeta($pdo, $defaultMeta);
            $body = readJsonBody();
            $res = Auth::verifySignedAuth($pdo, $body, (string) ($meta['directoryUrl'] ?? ''), $extensions);
            sendJson(200, $res);
        }

        if ($pathname === '/api/auth/claim') {
            $body = readJsonBody();
            $cid = is_string($body['challengeId'] ?? null) ? trim($body['challengeId']) : '';
            $secret = is_string($body['challengeSecret'] ?? null) ? trim($body['challengeSecret']) : '';
            $claimed = Auth::claimChallenge($pdo, $cid, $secret, $extensions);
            if (($claimed['status'] ?? '') === 'authenticated' && !empty($claimed['token'])) {
                Auth::setSessionCookie((string) $claimed['token'], isHttpsRequest());
                sendJson(200, [
                    'status' => 'authenticated',
                    'did' => $claimed['did'],
                    'csrfToken' => $claimed['csrfToken'],
                    'expiresAt' => $claimed['expiresAt'],
                ]);
            }
            sendJson(200, ['status' => 'pending']);
        }

        if ($pathname === '/api/auth/logout') {
            $cookieToken = is_string($_COOKIE[Auth::SESSION_COOKIE] ?? null) ? $_COOKIE[Auth::SESSION_COOKIE] : '';
            if ($cookieToken !== '') {
                $session = Auth::getSession($pdo, $cookieToken);
                if ($session !== null) {
                    Auth::requireCsrf($session, $_SERVER);
                }
                Auth::revokeSession($pdo, $cookieToken);
            }
            Auth::clearSessionCookie(isHttpsRequest());
            sendJson(200, ['ok' => true]);
        }

        if (preg_match('#^/api/auth/extensions/([a-z][a-z0-9-]{1,40})/consent$#', $pathname, $matches)) {
            $extId = $matches[1];
            if (!$extensions->isLoaded($extId)) {
                throw new AppError(404, 'not_found', 'Extension not found.');
            }
            $cookieToken = is_string($_COOKIE[Auth::SESSION_COOKIE] ?? null) ? $_COOKIE[Auth::SESSION_COOKIE] : '';
            $session = $cookieToken !== '' ? Auth::getSession($pdo, $cookieToken) : null;
            if ($session === null) {
                throw new AppError(401, 'auth_required', 'Sign in with your DID first.');
            }
            Auth::requireCsrf($session, $_SERVER);
            $body = readJsonBody();
            if (!isset($body['optedIn']) || !is_bool($body['optedIn'])) {
                throw new AppError(400, 'invalid_request', 'optedIn must be a boolean.');
            }
            $result = Auth::setConsent($pdo, $session['did'], $extId, $body['optedIn'], $extensions);
            sendJson(200, $result);
        }

        if (preg_match('#^/api/auth/extensions/([a-z][a-z0-9-]{1,40})/opt-out$#', $pathname, $matches)) {
            $enforceWriteLimit();
            $meta = Database::getInstanceMeta($pdo, $defaultMeta);
            $body = readJsonBody();
            $result = Auth::verifySignedOptOut($pdo, $body, (string) ($meta['directoryUrl'] ?? ''), $matches[1], $extensions);
            sendJson(200, $result);
        }

        if ($pathname === '/api/entries') {
            $enforceWriteLimit();
            $enforceGlobalCrawlLimit();
            $body = readJsonBody();
            if (!isset($body['siteUrl']) || !is_string($body['siteUrl'])) {
                throw new AppError(400, 'invalid_request', 'Send JSON like {"siteUrl":"https://example.com"}.');
            }
            $normalizedInputUrl = Verify::normalizeSiteUrl($body['siteUrl']);
            $existingBySite = $normalizedInputUrl !== '' ? Database::getEntryBySiteUrl($pdo, $normalizedInputUrl) : null;
            $existingByDid = $normalizedInputUrl !== '' ? Database::getEntryByDid($pdo, Verify::siteUrlToDidWeb($normalizedInputUrl)) : null;
            if (
                ($existingBySite['status'] ?? '') === 'blocked'
                || ($existingByDid['status'] ?? '') === 'blocked'
                || ($normalizedInputUrl !== '' && Database::isBlocked($pdo, Verify::siteUrlToDidWeb($normalizedInputUrl), $normalizedInputUrl))
            ) {
                throw new AppError(403, 'entry_blocked', 'This site is blocked in this hub. Contact the operator.');
            }
            if ($existingBySite === null && $normalizedInputUrl !== '') {
                $domainCount = Database::countEntriesByRegistrableDomain($pdo, $normalizedInputUrl);
                if ($domainCount >= $maxEntriesPerDomain) {
                    $domain = Database::registrableDomain($normalizedInputUrl);
                    throw (new AppError(
                        429,
                        'domain_limit_reached',
                        "This hub already lists the maximum number of sites ({$maxEntriesPerDomain}) from {$domain}."
                    ))->withExtra(['domain' => $domain, 'limit' => $maxEntriesPerDomain]);
                }
            }
            $site = Site::verifySite($body['siteUrl'], [
                'allowInsecure' => $allowInsecure,
                'allowPrivate' => $allowPrivate,
                'timeoutMs' => $refreshTimeoutMs,
                'budgetMs' => $fetchBudgetMs,
            ]);
            $upserted = Entries::saveVerified($pdo, $extensions, $site);
            $row = Database::getEntryByDid($pdo, $site['did']);
            if ($row === null) {
                throw new AppError(500, 'upsert_failed', 'Could not save verified entry.');
            }
            if (($row['status'] ?? '') === 'blocked') {
                throw new AppError(403, 'entry_blocked', 'This site is blocked in this hub. Contact the operator.');
            }
            sendJson($upserted['inserted'] ? 201 : 200, [
                'entry' => Database::toPublicEntry($row),
            ]);
        }

        if (preg_match('#^/api/entries/([^/]+)/refresh$#', $pathname, $matches)) {
            $enforceWriteLimit();
            $enforceGlobalCrawlLimit();
            $did = decodeParam($matches[1]);
            $row = Database::getEntryByDid($pdo, $did);
            if ($row === null || ($row['status'] ?? '') === 'blocked') {
                throw new AppError(404, 'not_found', 'No such entry.');
            }
            if ($refreshCooldownSec > 0 && !empty($row['last_checked_at'])) {
                $lastCheckedTs = strtotime((string) $row['last_checked_at']);
                $waitSec = $lastCheckedTs === false ? 0 : $refreshCooldownSec - (time() - $lastCheckedTs);
                if ($waitSec > 0) {
                    $minutes = max(1, (int) ceil($waitSec / 60));
                    throw (new AppError(
                        429,
                        'refresh_cooldown',
                        "This site was checked recently. Try again in {$minutes} minute" . ($minutes === 1 ? '' : 's') . '.'
                    ))->withExtra(['retryAfterSeconds' => $waitSec]);
                }
            }
            try {
                $site = Site::verifySite((string) $row['site_url'], [
                    'allowInsecure' => $allowInsecure,
                    'allowPrivate' => $allowPrivate,
                    'timeoutMs' => $refreshTimeoutMs,
                    'budgetMs' => $fetchBudgetMs,
                ]);
                if ($site['did'] !== $row['did']) {
                    throw new AppError(409, 'identity_changed', 'The site now publishes a different did:web identity.');
                }
                Entries::saveVerified($pdo, $extensions, $site);
            } catch (\Throwable $err) {
                // Record the attempt so the cooldown also applies to sites that fail or time out;
                // otherwise a slow site could be re-crawled on every request.
                Database::recordCheckAttempt($pdo, $row, $err instanceof AppError ? $err->getMessage() : 'Verification failed.');
                throw $err;
            }
            $updatedRow = Database::getEntryByDid($pdo, $did);
            sendJson(200, [
                'entry' => Database::toPublicEntry($updatedRow ?? $row),
            ]);
        }

        if (preg_match('#^/api/entries/([^/]+)/remove$#', $pathname, $matches)) {
            $enforceWriteLimit();
            // The signed request names the hub it is meant for. Without a configured hub URL there
            // is nothing trustworthy to compare it with (the Host header is set by the caller), so
            // a removal signed for another hub could be replayed here. Refuse until it is set.
            $meta = Database::getInstanceMeta($pdo, $defaultMeta);
            $expectedHubUrl = Verify::normalizeSiteUrl((string) ($meta['directoryUrl'] ?? ''));
            if ($expectedHubUrl === '') {
                throw new AppError(
                    503,
                    'hub_url_not_configured',
                    "This hub has not configured its public URL, so self-service removal is unavailable. Contact the hub's operator."
                );
            }
            $did = decodeParam($matches[1]);
            $row = Database::getEntryByDid($pdo, $did);
            if ($row === null) {
                throw new AppError(404, 'not_found', 'No such entry.');
            }

            $body = readJsonBody();
            $siteUrl = $body['siteUrl'] ?? null;
            $hubUrl = $body['hubUrl'] ?? null;
            $nonce = $body['nonce'] ?? null;
            $createdAt = $body['createdAt'] ?? null;
            $signature = $body['signature'] ?? null;
            if (!is_string($siteUrl) || !is_string($hubUrl) || !is_string($nonce) || !is_string($createdAt) || !is_string($signature)) {
                throw new AppError(400, 'invalid_request', 'A signed removal request needs siteUrl, hubUrl, nonce, createdAt and signature.');
            }
            if (Verify::normalizeSiteUrl($hubUrl) !== $expectedHubUrl) {
                throw new AppError(403, 'hub_mismatch', 'The removal request is not addressed to this hub.');
            }
            if (!empty($body['did']) && $body['did'] !== $row['did']) {
                throw new AppError(403, 'did_mismatch', 'The request did not name this entry.');
            }
            if (Verify::normalizeSiteUrl($siteUrl) !== $row['site_url']) {
                throw new AppError(403, 'site_mismatch', 'The request did not name this entry.');
            }
            $createdTs = strtotime($createdAt);
            if ($createdTs === false || abs(time() - $createdTs) > MAX_CLOCK_SKEW_SEC) {
                throw new AppError(400, 'stale_request', 'The signed request is missing or too old.');
            }

            $payload = [
                'action' => 'remove',
                'siteUrl' => (string) $row['site_url'],
                'did' => (string) $row['did'],
                'hubUrl' => $expectedHubUrl,
                'nonce' => $nonce,
                'createdAt' => $createdAt,
            ];
            if (!Verify::verifyPayloadSignature($payload, $signature, (string) $row['public_key_pem'])) {
                throw new AppError(403, 'invalid_signature', 'The removal signature did not verify for this entry.');
            }
            if (!Database::consumeNonce($pdo, $nonce)) {
                throw new AppError(409, 'invalid_nonce', 'The nonce is unknown, expired, or already used.');
            }
            Entries::remove($pdo, $extensions, (string) $row['did'], 'self');
            http_response_code(204);
            setCommonHeaders();
            exit;
        }

        if ($pathname === '/api/admin/settings') {
            requireAdmin($adminToken, $pdo, $trustProxy, $trustedProxyHops, $adminLimits);
            $body = readJsonBody();
            validateSettingsPatch($body);
            Database::setInstanceMeta($pdo, $body);
            $meta = Database::getInstanceMeta($pdo, $defaultMeta);
            sendJson(200, [
                'settings' => Database::resolvePublicInfo($pdo, $meta),
            ]);
        }

        if ($pathname === '/api/admin/refresh') {
            requireAdmin($adminToken, $pdo, $trustProxy, $trustedProxyHops, $adminLimits);
            $body = readJsonBody();
            $limit = parseLimit($body['limit'] ?? null, 10, 25);
            $rows = Database::listForRefresh($pdo, $limit);
            $updated = 0;
            $failed = 0;
            foreach ($rows as $row) {
                try {
                    $site = Site::verifySite((string) $row['site_url'], [
                        'allowInsecure' => $allowInsecure,
                        'allowPrivate' => $allowPrivate,
                        'timeoutMs' => $refreshTimeoutMs,
                        'budgetMs' => $fetchBudgetMs,
                    ]);
                    if ($site['did'] !== $row['did']) {
                        throw new AppError(409, 'identity_changed', 'The site now publishes a different did:web identity.');
                    }
                    Entries::saveVerified($pdo, $extensions, $site);
                    $updated++;
                } catch (\Throwable $err) {
                    $msg = $err instanceof AppError ? $err->getMessage() : $err->getMessage();
                    Database::recordFailure($pdo, $row, $msg);
                    $failed++;
                }
            }
            sendJson(200, [
                'checked' => count($rows),
                'updated' => $updated,
                'failed' => $failed,
            ]);
        }

        if (preg_match('#^/api/admin/entries/([^/]+)/status$#', $pathname, $matches)) {
            requireAdmin($adminToken, $pdo, $trustProxy, $trustedProxyHops, $adminLimits);
            $did = decodeParam($matches[1]);
            $body = readJsonBody();
            $status = $body['status'] ?? null;
            if (!is_string($status) || !in_array($status, ['active', 'blocked', 'stale'], true)) {
                throw new AppError(400, 'invalid_status', 'status must be active, blocked or stale.');
            }
            if (!Entries::setStatus($pdo, $extensions, $did, $status)) {
                throw new AppError(404, 'not_found', 'No such entry.');
            }
            $row = Database::getEntryByDid($pdo, $did);
            if ($row === null) {
                throw new AppError(404, 'not_found', 'No such entry.');
            }
            sendJson(200, ['entry' => Database::toAdminEntry($row, Database::getCandidateKey($pdo, $did))]);
        }

        // Runs pending schema upgrades and re-runs ones that failed (for example the collation upgrade, after the
        // operator removed the look-alike duplicates it reported).
        if ($pathname === '/api/admin/migrations/retry') {
            requireAdmin($adminToken, $pdo, $trustProxy, $trustedProxyHops, $adminLimits);
            // migrate() first: with auto_migrate off, the tables the upgrades rely on may not exist yet.
            Database::migrate($pdo);
            Database::runMigrations($pdo, null, true);
            sendJson(200, ['maintenance' => Database::maintenanceStatus($pdo)]);
        }

        // Accepts a site's new signing key when it changed without a rotation certificate (for
        // example after the owner lost their old key). Only the operator can do this, after
        // confirming the change with the owner some other way.
        if (preg_match('#^/api/admin/entries/([^/]+)/trust-key$#', $pathname, $matches)) {
            requireAdmin($adminToken, $pdo, $trustProxy, $trustedProxyHops, $adminLimits);
            $did = decodeParam($matches[1]);
            $row = Database::getEntryByDid($pdo, $did);
            if ($row === null) {
                throw new AppError(404, 'not_found', 'No such entry.');
            }
            $body = readJsonBody();
            $result = Entries::trustCandidateKey(
                $pdo,
                $extensions,
                $row,
                $body['expectedFingerprint'] ?? null,
                static fn (): array => Site::verifySite((string) $row['site_url'], [
                    'allowInsecure' => $allowInsecure,
                    'allowPrivate' => $allowPrivate,
                    'timeoutMs' => $refreshTimeoutMs,
                    'budgetMs' => $fetchBudgetMs,
                ])
            );
            $updatedRow = Database::getEntryByDid($pdo, $did);
            sendJson(200, [
                'entry' => Database::toAdminEntry($updatedRow ?? $row, Database::getCandidateKey($pdo, $did)),
                'keyChanged' => $result['keyRotated'],
            ]);
        }

        $dispatchExtension();

        throw new AppError(404, 'not_found', 'Not found.');
    }

    if ($method === 'DELETE') {
        // Lift a block whose listing is gone (a still-listed blocked site is unblocked by
        // setting its status to active)
        if (preg_match('#^/api/admin/blocked/([^/]+)$#', $pathname, $matches)) {
            requireAdmin($adminToken, $pdo, $trustProxy, $trustedProxyHops, $adminLimits);
            if (!Database::unblockSite($pdo, decodeParam($matches[1]))) {
                throw new AppError(404, 'not_found', 'That site is not blocked.');
            }
            http_response_code(204);
            setCommonHeaders();
            exit;
        }
        if (preg_match('#^/api/admin/entries/([^/]+)$#', $pathname, $matches)) {
            requireAdmin($adminToken, $pdo, $trustProxy, $trustedProxyHops, $adminLimits);
            $did = decodeParam($matches[1]);
            if (!Entries::remove($pdo, $extensions, $did, 'admin')) {
                throw new AppError(404, 'not_found', 'No such entry.');
            }
            http_response_code(204);
            setCommonHeaders();
            exit;
        }
        $dispatchExtension();
        throw new AppError(404, 'not_found', 'Not found.');
    }

    throw new AppError(405, 'method_not_allowed', 'Method not allowed.');
} catch (\Throwable $err) {
    sendError($err);
}
