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
require_once $appRoot . '/src/Entries.php';

const MAX_BODY_BYTES = 64 * 1024;
const NONCE_TTL_SEC = 15 * 60;
const MAX_CLOCK_SKEW_SEC = 10 * 60;

const CONTENT_SECURITY_POLICY = "default-src 'self'; img-src 'self' https: http: data:; style-src 'self'; script-src 'self'; connect-src 'self'; base-uri 'none'; form-action 'self'; frame-ancestors 'none'";

const STATIC_ASSETS = [
    '/app.js' => ['app.js', 'text/javascript; charset=utf-8'],
    '/remove.js' => ['remove.js', 'text/javascript; charset=utf-8'],
    '/admin.js' => ['admin.js', 'text/javascript; charset=utf-8'],
    '/style.css' => ['style.css', 'text/css; charset=utf-8'],
    '/favicon.svg' => ['favicon.svg', 'image/svg+xml'],
    '/favicon.png' => ['favicon.png', 'image/png'],
    '/logo-mark.png' => ['logo-mark.png', 'image/png'],
];

function setCommonHeaders(): void
{
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header('X-Frame-Options: DENY');
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
    sendJson($appError->status, [
        'error' => $appError->errorCode,
        'message' => $appError->getMessage(),
    ]);
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

function requireAdmin(string $adminToken, ?PDO $pdo = null, bool $trustProxy = false, int $trustedProxyHops = 1): void
{
    if (strlen($adminToken) < 32) {
        throw new AppError(503, 'admin_disabled', 'Admin access is not configured.');
    }
    $ip = clientIp($trustProxy, $trustedProxyHops);
    if ($pdo !== null && !Database::checkRateLimit($pdo, 'admin_auth:' . $ip, 900, 60)) {
        throw new AppError(429, 'rate_limited', 'Too many admin requests. Try again later.');
    }
    $header = getAuthorizationHeader();
    if (!preg_match('/^Bearer\s+(.+)$/', $header, $matches) || !hash_equals($adminToken, $matches[1])) {
        if ($pdo !== null && !Database::checkRateLimit($pdo, 'admin_auth_fail:' . $ip, 900, 10)) {
            throw new AppError(429, 'rate_limited', 'Too many failed admin login attempts. Try again later.');
        }
        throw new AppError(401, 'unauthorized', 'A valid admin token is required.');
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
    $dispatchExtension = static function () use ($extensions, $method, $pathname, $adminToken, $enforceWriteLimit): void {
        $match = $extensions->match($method, $pathname);
        if ($match === null) {
            return;
        }
        if ($match['route']['admin']) {
            requireAdmin($adminToken, $pdo, $trustProxy, $trustedProxyHops);
        }
        if ($match['route']['rateLimited']) {
            $enforceWriteLimit();
        }
        $request = new ExtensionRequest(
            $method,
            $pathname,
            $_GET,
            static fn (): array => readJsonBody()
        );
        [$status, $body] = Extensions::run($match, $request);
        sendJson($status, $body, [], $method === 'HEAD');
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
            $html = Template::renderTemplateFile('index.html', $meta, $pdo);
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
            requireAdmin($adminToken, $pdo, $trustProxy, $trustedProxyHops);
            sendJson(200, ['extensions' => $extensions->adminList()], [], $headOnly);
        }

        // Every entry whatever its status, with the operator-only fields from toAdminEntry.
        if ($pathname === '/api/admin/entries') {
            requireAdmin($adminToken, $pdo, $trustProxy, $trustedProxyHops);
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
                'entries' => array_map([Database::class, 'toAdminEntry'], $result['entries']),
            ], [], $headOnly);
        }

        $dispatchExtension();

        throw new AppError(404, 'not_found', 'Not found.');
    }

    if ($method === 'POST') {
        $rawContentType = (string) ($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '');
        $mediaType = strtolower(trim(explode(';', $rawContentType, 2)[0]));
        if ($mediaType !== 'application/json') {
            throw new AppError(415, 'unsupported_media_type', 'Content-Type must be application/json.');
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
            if (($existingBySite['status'] ?? '') === 'blocked' || ($existingByDid['status'] ?? '') === 'blocked') {
                throw new AppError(403, 'entry_blocked', 'This site is blocked in this hub. Contact the operator.');
            }
            if ($existingBySite === null && $normalizedInputUrl !== '') {
                $domainCount = Database::countEntriesByRegistrableDomain($pdo, $normalizedInputUrl);
                if ($domainCount >= $maxEntriesPerDomain) {
                    throw new AppError(429, 'domain_limit_reached', 'Too many entries are already listed for this domain.');
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
                if ($lastCheckedTs !== false && (time() - $lastCheckedTs) < $refreshCooldownSec) {
                    throw new AppError(429, 'rate_limited', 'This entry was refreshed recently. Try again in a few minutes.');
                }
            }
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
            $updatedRow = Database::getEntryByDid($pdo, $did);
            sendJson(200, [
                'entry' => Database::toPublicEntry($updatedRow ?? $row),
            ]);
        }

        if (preg_match('#^/api/entries/([^/]+)/remove$#', $pathname, $matches)) {
            $enforceWriteLimit();
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
            $meta = Database::getInstanceMeta($pdo, $defaultMeta);
            $expectedHubUrl = Verify::normalizeSiteUrl((string) ($meta['directoryUrl'] ?? ''));
            if ($expectedHubUrl === '' && !empty($_SERVER['HTTP_HOST'])) {
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $expectedHubUrl = Verify::normalizeSiteUrl("{$scheme}://{$_SERVER['HTTP_HOST']}");
            }
            if ($expectedHubUrl === '' || Verify::normalizeSiteUrl($hubUrl) !== $expectedHubUrl) {
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
            requireAdmin($adminToken, $pdo, $trustProxy, $trustedProxyHops);
            $body = readJsonBody();
            validateSettingsPatch($body);
            Database::setInstanceMeta($pdo, $body);
            $meta = Database::getInstanceMeta($pdo, $defaultMeta);
            sendJson(200, [
                'settings' => Database::resolvePublicInfo($pdo, $meta),
            ]);
        }

        if ($pathname === '/api/admin/refresh') {
            requireAdmin($adminToken, $pdo, $trustProxy, $trustedProxyHops);
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
            requireAdmin($adminToken, $pdo, $trustProxy, $trustedProxyHops);
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
            sendJson(200, ['entry' => Database::toAdminEntry($row)]);
        }

        // Accepts a site's new signing key when it changed without a rotation certificate (for
        // example after the owner lost their old key). Only the operator can do this, after
        // confirming the change with the owner some other way.
        if (preg_match('#^/api/admin/entries/([^/]+)/trust-key$#', $pathname, $matches)) {
            requireAdmin($adminToken, $pdo, $trustProxy, $trustedProxyHops);
            $did = decodeParam($matches[1]);
            $row = Database::getEntryByDid($pdo, $did);
            if ($row === null) {
                throw new AppError(404, 'not_found', 'No such entry.');
            }
            $candidatePem = Database::getCandidateKey($pdo, $did);
            $site = Site::verifySite((string) $row['site_url'], [
                'allowInsecure' => $allowInsecure,
                'allowPrivate' => $allowPrivate,
                'timeoutMs' => $refreshTimeoutMs,
                'budgetMs' => $fetchBudgetMs,
            ]);
            if ($site['did'] !== $row['did']) {
                throw new AppError(409, 'identity_changed', 'The site now publishes a different did:web identity.');
            }
            if ($candidatePem !== null && Verify::spkiFingerprint($site['publicKeyPem']) !== Verify::spkiFingerprint($candidatePem)) {
                throw new AppError(409, 'candidate_key_mismatch', 'The site is now serving a different key than the candidate key that was flagged for review.');
            }
            $result = Entries::saveVerified($pdo, $extensions, $site, true);
            $updatedRow = Database::getEntryByDid($pdo, $did);
            sendJson(200, [
                'entry' => Database::toAdminEntry($updatedRow ?? $row),
                'keyChanged' => $result['keyRotated'],
            ]);
        }

        $dispatchExtension();

        throw new AppError(404, 'not_found', 'Not found.');
    }

    if ($method === 'DELETE') {
        if (preg_match('#^/api/admin/entries/([^/]+)$#', $pathname, $matches)) {
            requireAdmin($adminToken, $pdo, $trustProxy, $trustedProxyHops);
            $did = decodeParam($matches[1]);
            if (!Entries::remove($pdo, $extensions, $did, 'admin')) {
                throw new AppError(404, 'not_found', 'No such entry.');
            }
            http_response_code(204);
            setCommonHeaders();
            exit;
        }
        throw new AppError(404, 'not_found', 'Not found.');
    }

    throw new AppError(405, 'method_not_allowed', 'Method not allowed.');
} catch (\Throwable $err) {
    sendError($err);
}
