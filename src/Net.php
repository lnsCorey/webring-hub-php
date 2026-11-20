<?php
declare(strict_types=1);

namespace WebringHub;

/**
 * SSRF-safe JSON fetcher with public-address validation, DNS pinning via CURLOPT_RESOLVE,
 * manual redirect re-validation, a hard timeout, and a 2 MB response size cap.
 */
final class Net
{
    public const DEFAULT_USER_AGENT = 'WebringHub-PHP/0.1 (+https://github.com/bitphr3ak/webring-studio)';

    /**
     * Resolves the client IP address for rate limiting.
     * When $trustProxy is true, reads the address $trustedProxyHops from the right in
     * X-Forwarded-For, validates it as an IP, and falls back to REMOTE_ADDR.
     *
     * @param array<string, mixed> $server
     */
    public static function resolveClientIp(array $server, bool $trustProxy, int $trustedProxyHops = 1): string
    {
        $remote = (string) ($server['REMOTE_ADDR'] ?? 'unknown');
        $remote = str_starts_with($remote, '::ffff:') ? substr($remote, 7) : $remote;

        if (!$trustProxy || $trustedProxyHops < 1) {
            return $remote;
        }

        $forwarded = $server['HTTP_X_FORWARDED_FOR'] ?? '';
        if (!is_string($forwarded) || trim($forwarded) === '') {
            return $remote;
        }

        $parts = array_values(array_filter(
            array_map('trim', explode(',', $forwarded)),
            static fn (string $v): bool => $v !== ''
        ));
        $index = count($parts) - $trustedProxyHops;
        if ($index < 0) {
            return $remote;
        }

        $candidate = $parts[$index];
        if (str_starts_with($candidate, '::ffff:')) {
            $candidate = substr($candidate, 7);
        }
        if (filter_var($candidate, FILTER_VALIDATE_IP) === false) {
            return $remote;
        }

        return $candidate;
    }

    /**
     * True only for globally routable unicast IPv4 / IPv6 addresses.
     * Mirrors `isPublicAddress` in `webring-directory/src/net.js`.
     */
    public static function isPublicAddress(string $address): bool
    {
        $clean = $address;
        $zonePos = strpos($clean, '%');
        if ($zonePos !== false) {
            $clean = substr($clean, 0, $zonePos);
        }

        if (filter_var($clean, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $packed = inet_pton($clean);
            if ($packed === false || strlen($packed) !== 4) {
                return false;
            }
            $bytes = array_values(unpack('C4', $packed));
            return self::isPublicV4($bytes);
        }

        if (filter_var($clean, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($clean);
            if ($packed === false || strlen($packed) !== 16) {
                return false;
            }
            $bytes = array_values(unpack('C16', $packed));
            return self::isPublicV6($bytes);
        }

        return false;
    }

    /**
     * @param list<int> $bytes
     */
    private static function isPublicV4(array $bytes): bool
    {
        [$a, $b, $c] = $bytes;
        if ($a === 0 || $a === 10 || $a === 127) {
            return false;
        }
        if ($a === 100 && $b >= 64 && $b <= 127) {
            return false;
        }
        if ($a === 169 && $b === 254) {
            return false;
        }
        if ($a === 172 && $b >= 16 && $b <= 31) {
            return false;
        }
        if ($a === 192 && $b === 168) {
            return false;
        }
        if ($a === 192 && $b === 0 && $c === 0) {
            return false;
        }
        if ($a === 192 && $b === 0 && $c === 2) {
            return false;
        }
        if ($a === 192 && $b === 88 && $c === 99) {
            return false;
        }
        if ($a === 198 && ($b === 18 || $b === 19)) {
            return false;
        }
        if ($a === 198 && $b === 51 && $c === 100) {
            return false;
        }
        if ($a === 203 && $b === 0 && $c === 113) {
            return false;
        }
        if ($a >= 224) {
            return false;
        }
        return true;
    }

    /**
     * @param list<int> $bytes
     */
    private static function isPublicV6(array $bytes): bool
    {
        // ::ffff:a.b.c.d (IPv4-mapped) and ::a.b.c.d (IPv4-compatible)
        $firstTenZero = true;
        for ($i = 0; $i < 10; $i++) {
            if ($bytes[$i] !== 0) {
                $firstTenZero = false;
                break;
            }
        }
        if ($firstTenZero) {
            $v4 = array_slice($bytes, 12, 4);
            if ($bytes[10] === 0xff && $bytes[11] === 0xff) {
                return self::isPublicV4($v4);
            }
            if ($bytes[10] === 0 && $bytes[11] === 0) {
                return self::isPublicV4($v4);
            }
        }

        // Only 2000::/3 is global unicast
        if (($bytes[0] & 0xe0) !== 0x20) {
            return false;
        }
        // 2001:db8::/32 documentation
        if ($bytes[0] === 0x20 && $bytes[1] === 0x01 && $bytes[2] === 0x0d && $bytes[3] === 0xb8) {
            return false;
        }
        // 2001:2::/48 benchmarking
        if ($bytes[0] === 0x20 && $bytes[1] === 0x01 && $bytes[2] === 0x00 && $bytes[3] === 0x02) {
            return false;
        }
        // 2001:10::/28 (ORCHID) and 2001:20::/28 (ORCHIDv2)
        if ($bytes[0] === 0x20 && $bytes[1] === 0x01 && $bytes[2] === 0x00) {
            $highNibble = $bytes[3] & 0xf0;
            if ($highNibble === 0x10 || $highNibble === 0x20) {
                return false;
            }
        }
        // 2001::/32 Teredo
        if ($bytes[0] === 0x20 && $bytes[1] === 0x01 && $bytes[2] === 0x00 && $bytes[3] === 0x00) {
            return false;
        }
        // 2002::/16 6to4
        if ($bytes[0] === 0x20 && $bytes[1] === 0x02) {
            return false;
        }
        // 3fff::/20 documentation
        if ($bytes[0] === 0x3f && ($bytes[1] & 0xf0) === 0xf0) {
            return false;
        }

        return true;
    }

    /**
     * Resolves a hostname and validates that every returned IP is public (unless $allowPrivate is true).
     * Returns the first resolved IP address for pinning via CURLOPT_RESOLVE.
     */
    private static function resolveAndValidateHost(string $host, bool $allowPrivate): string
    {
        $unbracketed = trim($host, '[]');
        if (filter_var($unbracketed, FILTER_VALIDATE_IP) !== false) {
            if (!$allowPrivate && !self::isPublicAddress($unbracketed)) {
                throw new AppError(400, 'unsafe_host', 'Refusing to connect to a private or reserved address.');
            }
            return $unbracketed;
        }

        $ips = [];
        $records = @dns_get_record($unbracketed, DNS_A + DNS_AAAA);
        if (is_array($records)) {
            foreach ($records as $rec) {
                if (!empty($rec['ip']) && is_string($rec['ip'])) {
                    $ips[] = $rec['ip'];
                } elseif (!empty($rec['ipv6']) && is_string($rec['ipv6'])) {
                    $ips[] = $rec['ipv6'];
                }
            }
        }
        if (count($ips) === 0) {
            $v4s = @gethostbynamel($unbracketed);
            if (is_array($v4s)) {
                $ips = $v4s;
            }
        }
        if (count($ips) === 0) {
            throw new AppError(502, 'dns_failed', 'The hostname did not resolve.');
        }

        if (!$allowPrivate) {
            foreach ($ips as $ip) {
                if (!self::isPublicAddress($ip)) {
                    throw new AppError(400, 'unsafe_host', 'Refusing to connect to a private or reserved address.');
                }
            }
        }

        return $ips[0];
    }

    /**
     * Resolves a relative redirect Location against a base URL.
     */
    private static function resolveRedirectUrl(string $baseUrl, string $location): string
    {
        if (preg_match('/^https?:\/\//i', $location)) {
            return $location;
        }
        $base = parse_url($baseUrl);
        if (!is_array($base) || empty($base['scheme']) || empty($base['host'])) {
            throw new AppError(502, 'bad_redirect', 'The remote site sent an invalid redirect.');
        }
        $scheme = strtolower($base['scheme']);
        $host = $base['host'];
        $port = isset($base['port']) ? ':' . (int) $base['port'] : '';
        if (str_starts_with($location, '//')) {
            return "{$scheme}:{$location}";
        }
        if (str_starts_with($location, '/')) {
            return "{$scheme}://{$host}{$port}{$location}";
        }
        $basePath = $base['path'] ?? '/';
        $dir = preg_replace('#/[^/]*$#', '/', $basePath) ?: '/';
        return "{$scheme}://{$host}{$port}{$dir}{$location}";
    }

    /**
     * Validates and normalizes a URL host to plain ASCII (converting IDN via UTS-46)
     * or a bracketed IPv6 literal, rejecting percent-encoding and malformed characters.
     */
    private static function normalizeAndValidateHost(string $rawHost): string
    {
        if ($rawHost === '' || str_contains($rawHost, '%') || preg_match('/[\s\x00-\x1F\x7F]/', $rawHost)) {
            throw new AppError(400, 'invalid_url', 'The URL hostname is not valid.');
        }

        if (str_starts_with($rawHost, '[') && str_ends_with($rawHost, ']')) {
            $inner = substr($rawHost, 1, -1);
            if (filter_var($inner, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw new AppError(400, 'invalid_url', 'The URL IPv6 host is not valid.');
            }
            return '[' . strtolower($inner) . ']';
        }

        if (filter_var($rawHost, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $rawHost;
        }

        $asciiHost = $rawHost;
        if (preg_match('/[^\x20-\x7E]/', $rawHost)) {
            if (!function_exists('idn_to_ascii')) {
                throw new AppError(400, 'invalid_url', 'Internationalized domain names are not supported.');
            }
            $converted = idn_to_ascii($rawHost, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if (!is_string($converted) || $converted === '') {
                throw new AppError(400, 'invalid_url', 'The URL hostname is not valid.');
            }
            $asciiHost = $converted;
        }

        $asciiHost = strtolower($asciiHost);
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*\.?$/', $asciiHost)) {
            throw new AppError(400, 'invalid_url', 'The URL hostname is not valid.');
        }

        return $asciiHost;
    }

    /**
     * Fetches and parses JSON with a public-address guard, redirect re-validation,
     * a hard timeout, and a response-size cap.
     *
     * Notes:
     * - Decodes JSON into both associative array (`json`) and object (`jsonObject` via `json_decode(..., false)`)
     *   so signature verification preserves `{}` vs `[]` exactly like Node.js.
     *
     * @param array<string, mixed> $options
     * @return array{url: string, status: int, headers: array<string, string>, json: mixed, jsonObject: mixed}
     */
    public static function safeGetJson(string $urlString, array $options = []): array
    {
        $timeoutMs = (int) ($options['timeoutMs'] ?? 8000);
        $budgetMs = max(1000, (int) ($options['budgetMs'] ?? 15000));
        $maxBytes = (int) ($options['maxBytes'] ?? (2 * 1024 * 1024));
        $maxRedirects = (int) ($options['maxRedirects'] ?? 3);
        $allowInsecure = (bool) ($options['allowInsecure'] ?? false);
        $allowPrivate = (bool) ($options['allowPrivate'] ?? false);
        $userAgent = (string) ($options['userAgent'] ?? self::DEFAULT_USER_AGENT);
        $deadline = microtime(true) + ($budgetMs / 1000.0);
        // A caller verifying several documents passes one shared deadline (Site::verifySite), so
        // the whole verification, not each fetch, is bounded.
        if (isset($options['deadline']) && is_float($options['deadline'])) {
            $deadline = min($deadline, $options['deadline']);
        }
        $remaining = static function () use ($deadline): int {
            $ms = (int) floor(($deadline - microtime(true)) * 1000);
            if ($ms <= 0) {
                throw new AppError(504, 'fetch_timeout', 'The remote site took too long to respond.');
            }
            return $ms;
        };

        $currentUrl = trim($urlString);
        for ($hop = 0; $hop <= $maxRedirects; $hop++) {
            $remaining();
            $parts = parse_url($currentUrl);
            if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
                throw new AppError(400, 'invalid_url', 'The URL is not valid.');
            }
            $scheme = strtolower($parts['scheme']);
            if ($scheme !== 'https' && !($allowInsecure && $scheme === 'http')) {
                throw new AppError(400, 'insecure_url', 'Only https:// URLs are allowed.');
            }
            if (!empty($parts['user']) || !empty($parts['pass'])) {
                throw new AppError(400, 'unsafe_url', 'URLs with credentials are not allowed.');
            }

            $host = self::normalizeAndValidateHost((string) $parts['host']);
            $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
            if ($port !== 443 && !($allowInsecure && $scheme === 'http' && $port === 80)) {
                throw new AppError(400, 'unsafe_port', 'Only standard HTTPS port 443 is allowed.');
            }

            $path = (string) ($parts['path'] ?? '/');
            if ($path === '') {
                $path = '/';
            } elseif (!str_starts_with($path, '/')) {
                $path = '/' . $path;
            }
            $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
            $portPart = (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80))
                ? ':' . $port
                : '';
            $rebuiltUrl = "{$scheme}://{$host}{$portPart}{$path}{$query}";

            $pinnedIp = self::resolveAndValidateHost($host, $allowPrivate);
            // DNS lookups can't be interrupted, so re-check the budget once they return.
            $hopTimeoutMs = min($timeoutMs, $remaining());

            $result = self::requestOnce($rebuiltUrl, $host, $port, $pinnedIp, $hopTimeoutMs, $maxBytes, $userAgent);
            if (isset($result['redirect'])) {
                if ($hop === $maxRedirects) {
                    throw new AppError(502, 'too_many_redirects', 'The remote site redirected too many times.');
                }
                $currentUrl = self::resolveRedirectUrl($rebuiltUrl, $result['redirect']);
                continue;
            }

            return [
                'url' => $rebuiltUrl,
                'status' => $result['status'],
                'headers' => $result['headers'],
                'json' => $result['json'],
                'jsonObject' => $result['jsonObject'],
            ];
        }

        throw new AppError(502, 'too_many_redirects', 'The remote site redirected too many times.');
    }

    /**
     * @return array{status: int, redirect?: string, headers?: array<string, string>, json?: mixed, jsonObject?: mixed}
     */
    private static function requestOnce(
        string $url,
        string $host,
        int $port,
        string $pinnedIp,
        int $timeoutMs,
        int $maxBytes,
        string $userAgent
    ): array {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new AppError(500, 'curl_unavailable', 'cURL is required to verify remote sites.');
        }

        $responseHeaders = [];
        $bodyBuffer = '';
        $abortedForSize = false;
        $resolveHost = trim($host, '[]');
        $formattedIp = str_contains($pinnedIp, ':') ? "[{$pinnedIp}]" : $pinnedIp;

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_TIMEOUT_MS => max($timeoutMs, 1000),
            CURLOPT_CONNECTTIMEOUT_MS => max(min($timeoutMs, 5000), 1000),
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'Accept-Encoding: identity',
                'User-Agent: ' . $userAgent,
            ],
            CURLOPT_RESOLVE => ["{$resolveHost}:{$port}:{$formattedIp}"],
            CURLOPT_HEADERFUNCTION => static function ($curl, string $headerLine) use (&$responseHeaders): int {
                $len = strlen($headerLine);
                $parts = explode(':', $headerLine, 2);
                if (count($parts) === 2) {
                    $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return $len;
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$bodyBuffer, $maxBytes, &$abortedForSize): int {
                if (strlen($bodyBuffer) + strlen($chunk) > $maxBytes) {
                    $abortedForSize = true;
                    return 0;
                }
                $bodyBuffer .= $chunk;
                return strlen($chunk);
            },
        ]);

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $errorMsg = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $primaryIp = (string) curl_getinfo($ch, CURLINFO_PRIMARY_IP);
        curl_close($ch);

        if ($abortedForSize) {
            throw new AppError(502, 'response_too_large', 'The remote document is too large.');
        }
        if ($ok === false) {
            error_log("[webring-hub-php] fetch_failed for {$url}: ({$errno}) {$errorMsg}");
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                throw new AppError(504, 'fetch_timeout', 'The remote site took too long to respond.');
            }
            throw new AppError(502, 'fetch_failed', 'Could not fetch the remote site.');
        }
        $pinnedPacked = inet_pton($pinnedIp);
        $primaryPacked = $primaryIp !== '' ? inet_pton($primaryIp) : false;
        if ($pinnedPacked === false || $primaryPacked === false || $primaryPacked !== $pinnedPacked) {
            error_log("[webring-hub-php] ip_pin_mismatch for {$url}: expected {$pinnedIp}, got {$primaryIp}");
            throw new AppError(502, 'fetch_failed', 'The remote connection did not match the validated address.');
        }

        if ($status >= 300 && $status < 400) {
            $location = $responseHeaders['location'] ?? '';
            if ($location === '') {
                throw new AppError(502, 'bad_redirect', 'The remote server sent an empty redirect.');
            }
            return ['status' => $status, 'redirect' => $location];
        }

        if ($status < 200 || $status >= 300) {
            $isMissing = $status === 404 || $status === 410;
            throw new AppError(
                $isMissing ? 404 : 502,
                $isMissing ? 'not_found' : 'fetch_failed',
                "The remote site returned HTTP {$status} for {$url}."
            );
        }

        $cleanBody = preg_replace('/^\xEF\xBB\xBF/', '', $bodyBuffer) ?? $bodyBuffer;
        $jsonAssoc = json_decode($cleanBody, true);
        $jsonObj = json_decode($cleanBody, false);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $type = $responseHeaders['content-type'] ?? 'an unknown content type';
            throw new AppError(502, 'invalid_json', "{$url} did not return JSON (got {$type}).");
        }

        return [
            'status' => $status,
            'headers' => $responseHeaders,
            'json' => $jsonAssoc,
            'jsonObject' => $jsonObj,
        ];
    }
}
