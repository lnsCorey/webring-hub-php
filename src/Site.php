<?php
declare(strict_types=1);

namespace WebringHub;

/**
 * Fetches a remote site's manifest, did:web document, and signed profile,
 * then verifies ownership and Ed25519 signature integrity.
 * Mirrors `webring-directory/src/site.js`.
 */
final class Site
{
    public const MAX_JSON_BYTES = 2 * 1024 * 1024;

    private static function fail(string $code, string $message, int $status = 422): AppError
    {
        return new AppError($status, $code, $message);
    }

    /**
     * @param array<string, mixed> $options
     * @return array{url: string, status: int, headers: array<string, string>, json: mixed, jsonObject: mixed}
     */
    private static function fetchJson(string $url, array $options): array
    {
        try {
            return Net::safeGetJson($url, $options);
        } catch (AppError $err) {
            if ($err->status === 404) {
                throw self::fail('not_found', "No document was found at {$url}.", 422);
            }
            throw $err;
        }
    }

    /**
     * Normalises dot-segments (`/./`, `/../`) in a URL path.
     */
    private static function normalizePath(string $path): string
    {
        $segments = explode('/', $path);
        $stack = [];
        foreach ($segments as $seg) {
            if ($seg === '' || $seg === '.') {
                continue;
            }
            if ($seg === '..') {
                array_pop($stack);
                continue;
            }
            $stack[] = $seg;
        }
        $normalized = '/' . implode('/', $stack);
        if (str_ends_with($path, '/') && $normalized !== '/') {
            $normalized .= '/';
        }
        return $normalized;
    }

    /**
     * Webring Studio writes its manifest endpoints as site-root-relative paths
     * (`/.well-known/did.json`, `/api/profile.json`). For a site published under a
     * subpath such as https://example.com/social, those must resolve under
     * https://example.com/social, not at the origin root. They also must not be
     * allowed to point off-site.
     */
    public static function resolveEndpoint(string $siteUrl, mixed $endpoint, string $fallback): string
    {
        $raw = (is_string($endpoint) && trim($endpoint) !== '') ? trim($endpoint) : $fallback;
        $siteParts = parse_url($siteUrl);
        if (!is_array($siteParts) || empty($siteParts['scheme']) || empty($siteParts['host'])) {
            throw self::fail('bad_endpoint', 'The manifest contains an invalid endpoint URL.');
        }
        $siteScheme = strtolower($siteParts['scheme']);
        $siteHost = strtolower($siteParts['host']);
        $sitePort = isset($siteParts['port']) ? ':' . (int) $siteParts['port'] : '';
        $siteOrigin = "{$siteScheme}://{$siteHost}{$sitePort}";
        $sitePath = rtrim($siteParts['path'] ?? '', '/');

        if (preg_match('/^https?:\/\//i', $raw)) {
            $candidate = $raw;
        } elseif (str_starts_with($raw, '/')) {
            $candidate = $siteUrl . $raw;
        } else {
            $candidate = $siteUrl . '/' . $raw;
        }

        $parts = parse_url($candidate);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw self::fail('bad_endpoint', 'The manifest contains an invalid endpoint URL.');
        }
        $resScheme = strtolower($parts['scheme']);
        $resHost = strtolower($parts['host']);
        $resPort = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $resOrigin = "{$resScheme}://{$resHost}{$resPort}";
        $resPath = self::normalizePath($parts['path'] ?? '/');

        if ($resOrigin !== $siteOrigin) {
            throw self::fail('endpoint_offsite', 'The manifest points to a different origin.');
        }
        if ($sitePath !== '' && $resPath !== $sitePath && !str_starts_with($resPath, $sitePath . '/')) {
            throw self::fail('endpoint_offsite', 'The manifest endpoint is outside the site.');
        }

        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        return "{$resOrigin}{$resPath}{$query}";
    }

    /**
     * Fetches a site's manifest, did:web document, and signed profile, then verifies:
     *   1. the submitted URL matches `manifest.siteUrl`;
     *   2. `manifest.did` is the did:web for that URL;
     *   3. `/.well-known/did.json` has the same id and a PEM Ed25519 key;
     *   4. `profile.json` names the same did and site, and its Ed25519 proof verifies.
     *
     * @param array<string, mixed> $options
     * @return array{siteUrl: string, did: string, manifest: array<string, mixed>, profile: array<string, mixed>, publicKeyPem: string, keyRotations: list<\stdClass>, fetchedAt: string}
     */
    public static function verifySite(string $rawSiteUrl, array $options = []): array
    {
        $allowInsecure = (bool) ($options['allowInsecure'] ?? false);
        $allowPrivate = (bool) ($options['allowPrivate'] ?? false);
        $timeoutMs = (int) ($options['timeoutMs'] ?? 8000);

        $siteUrl = Verify::normalizeSiteUrl($rawSiteUrl);
        if ($siteUrl === '') {
            throw self::fail('invalid_site_url', 'Enter a valid http(s) site URL.', 400);
        }
        if (!$allowInsecure && !str_starts_with($siteUrl, 'https://')) {
            throw self::fail('insecure_url', 'Only https:// sites can be listed.', 400);
        }

        $siteParts = parse_url($siteUrl);
        $sitePath = is_array($siteParts) ? ($siteParts['path'] ?? '/') : '/';
        $fetchOptions = [
            'timeoutMs' => $timeoutMs,
            'maxBytes' => self::MAX_JSON_BYTES,
            'allowInsecure' => $allowInsecure,
            'allowPrivate' => $allowPrivate,
        ];

        $manifestRes = self::fetchJson($siteUrl . '/api/manifest.json', $fetchOptions);
        $manifest = $manifestRes['json'];
        if (!is_array($manifest) || array_is_list($manifest)) {
            throw self::fail('invalid_manifest', 'The site did not return a Webring Studio manifest.');
        }

        if (Verify::normalizeSiteUrl((string) ($manifest['siteUrl'] ?? '')) !== $siteUrl) {
            throw self::fail(
                'manifest_site_mismatch',
                'The manifest does not name the site URL that was submitted.'
            );
        }

        $did = Verify::siteUrlToDidWeb($siteUrl);
        if ($did === '' || ($manifest['did'] ?? '') !== $did) {
            throw self::fail('manifest_did_mismatch', 'The manifest does not use the did:web for this site URL.');
        }

        $endpoints = (isset($manifest['endpoints']) && is_array($manifest['endpoints']))
            ? $manifest['endpoints']
            : [];
        $didUrl = self::resolveEndpoint($siteUrl, $endpoints['did'] ?? null, '/.well-known/did.json');

        try {
            $didRes = self::fetchJson($didUrl, $fetchOptions);
        } catch (AppError $err) {
            $hasPath = $sitePath !== '' && $sitePath !== '/';
            if ($err->errorCode === 'not_found' && $hasPath) {
                $didRes = self::fetchJson(self::resolveEndpoint($siteUrl, 'did.json', 'did.json'), $fetchOptions);
            } else {
                throw $err;
            }
        }

        $didDocument = $didRes['json'];
        if (!is_array($didDocument) || ($didDocument['id'] ?? '') !== $did) {
            throw self::fail('did_document_mismatch', 'The did:web document does not match this site.');
        }

        $methods = (isset($didDocument['verificationMethod']) && is_array($didDocument['verificationMethod']))
            ? $didDocument['verificationMethod']
            : [];
        $publicKeyPem = '';
        foreach ($methods as $entry) {
            if (is_array($entry) && ($entry['id'] ?? '') === "{$did}#key-1" && !empty($entry['publicKeyPem'])) {
                $publicKeyPem = (string) $entry['publicKeyPem'];
                break;
            }
        }
        if ($publicKeyPem === '') {
            foreach ($methods as $entry) {
                if (is_array($entry) && !empty($entry['publicKeyPem']) && is_string($entry['publicKeyPem'])) {
                    $publicKeyPem = $entry['publicKeyPem'];
                    break;
                }
            }
        }
        if (!str_contains($publicKeyPem, 'BEGIN PUBLIC KEY')) {
            throw self::fail('missing_public_key', 'The site did not publish a PEM Ed25519 public key.');
        }

        if (!empty($manifest['proof']) && !Verify::verifyDocumentSignature($manifestRes['jsonObject'], $publicKeyPem)) {
            throw self::fail('manifest_signature_invalid', 'The manifest signature did not verify.');
        }

        $profileUrl = self::resolveEndpoint($siteUrl, $endpoints['profile'] ?? null, '/api/profile.json');
        $profileRes = self::fetchJson($profileUrl, $fetchOptions);
        $profile = $profileRes['json'];
        if (!is_array($profile) || array_is_list($profile)) {
            throw self::fail('invalid_profile', 'The site did not return a valid profile document.');
        }
        if (($profile['did'] ?? '') !== $did) {
            throw self::fail('profile_did_mismatch', 'The profile does not use the did:web for this site URL.');
        }
        if (Verify::normalizeSiteUrl((string) ($profile['siteUrl'] ?? '')) !== $siteUrl) {
            throw self::fail('profile_site_mismatch', 'The profile does not name the site URL that was submitted.');
        }
        if (!Verify::verifyDocumentSignature($profileRes['jsonObject'], $publicKeyPem)) {
            throw self::fail(
                'signature_invalid',
                "The profile signature did not verify against the site's published did:web key."
            );
        }

        // Planned key changes: Webring Studio publishes every rotation certificate the site has
        // issued in did.json (`keyRotations`). Kept as decoded objects so their signatures can be
        // checked exactly as fetched. Only used when the key differs from the pinned one.
        $keyRotations = [];
        $didObject = $didRes['jsonObject'] ?? null;
        if ($didObject instanceof \stdClass && isset($didObject->keyRotations) && is_array($didObject->keyRotations)) {
            foreach (array_slice($didObject->keyRotations, 0, 20) as $cert) {
                if ($cert instanceof \stdClass) {
                    $keyRotations[] = $cert;
                }
            }
        }

        return [
            'siteUrl' => $siteUrl,
            'did' => $did,
            'manifest' => $manifest,
            'profile' => $profile,
            'publicKeyPem' => $publicKeyPem,
            'keyRotations' => $keyRotations,
            'fetchedAt' => gmdate('Y-m-d\TH:i:s.000\Z'),
        ];
    }
}
