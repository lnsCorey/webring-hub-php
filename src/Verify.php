<?php
declare(strict_types=1);

namespace WebringHub;

use stdClass;

/**
 * Ed25519 signature verification, key generation, URL normalisation, did:web resolution,
 * and Webring Studio's canonical JSON serialization.
 *
 * Uses `ext-sodium` when available and includes a built-in zero-dependency 64-bit
 * RFC 8032 / TweetNaCl Ed25519 implementation so Hostinger shared hosting works
 * out of the box even when the `sodium` PHP extension is not enabled in hPanel.
 */
final class Verify
{
    private const SPKI_ED25519_PREFIX = "\x30\x2a\x30\x05\x06\x03\x2b\x65\x70\x03\x21\x00";
    private const PKCS8_ED25519_PREFIX = "\x30\x2e\x02\x01\x00\x30\x05\x06\x03\x2b\x65\x70\x04\x22\x04\x20";

    private const GF_D = [0x78a3, 0x1359, 0x4dca, 0x75eb, 0xd8ab, 0x4141, 0x0a4d, 0x0070, 0xe898, 0x7779, 0x4079, 0x8cc7, 0xfe73, 0x2b6f, 0x6cee, 0x5203];
    private const GF_D2 = [0xf159, 0x26b2, 0x9b94, 0xebd6, 0xb156, 0x8283, 0x149a, 0x00e0, 0xd130, 0xeef3, 0x80f2, 0x198e, 0xfce7, 0x56df, 0xd9dc, 0x2406];
    private const GF_X = [0xd51a, 0x8f25, 0x2d60, 0xc956, 0xa7b2, 0x9525, 0xc760, 0x692c, 0xdc5c, 0xfdd6, 0xe231, 0xc0a4, 0x53fe, 0xcd6e, 0x36d3, 0x2169];
    private const GF_Y = [0x6658, 0x6666, 0x6666, 0x6666, 0x6666, 0x6666, 0x6666, 0x6666, 0x6666, 0x6666, 0x6666, 0x6666, 0x6666, 0x6666, 0x6666, 0x6666];
    private const GF_I = [0xa0b0, 0x4a0e, 0x1b27, 0xc4ee, 0xe478, 0xad2f, 0x1806, 0x2f43, 0xd7a7, 0x3dfb, 0x0099, 0x2b4d, 0xdf0b, 0x4fc1, 0x2480, 0x2b83];
    private const MOD_L = [0xed, 0xd3, 0xf5, 0x5c, 0x1a, 0x63, 0x12, 0x58, 0xd6, 0x9c, 0xf7, 0xa2, 0xde, 0xf9, 0xde, 0x14, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x00, 0x10];

    /** Normalises a site URL the same way Webring Studio does (https by default, no trailing slash). */
    public static function normalizeSiteUrl(?string $rawUrl): string
    {
        $trimmed = trim((string) $rawUrl);
        if ($trimmed === '') {
            return '';
        }
        $hasExplicitScheme = (bool) preg_match('/^[a-z][a-z0-9+.-]*:\/\//i', $trimmed);
        if ($hasExplicitScheme && !preg_match('/^https?:\/\//i', $trimmed)) {
            return '';
        }
        $withProto = preg_match('/^https?:\/\//i', $trimmed) ? $trimmed : 'https://' . $trimmed;
        $parts = parse_url($withProto);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return '';
        }
        $scheme = strtolower($parts['scheme']);
        if ($scheme !== 'https' && $scheme !== 'http') {
            return '';
        }
        if (!empty($parts['user']) || !empty($parts['pass'])) {
            return '';
        }
        if (isset($parts['query']) || isset($parts['fragment'])) {
            return '';
        }

        $host = strtolower(rtrim($parts['host'], '.'));
        if ($host === '' || str_contains($host, '..')) {
            return '';
        }
        $unbracketed = trim($host, '[]');
        if (
            filter_var($unbracketed, FILTER_VALIDATE_IP) === false &&
            !preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/', $host)
        ) {
            return '';
        }
        $port = isset($parts['port']) ? (int) $parts['port'] : null;
        if (($scheme === 'https' && $port === 443) || ($scheme === 'http' && $port === 80)) {
            $port = null;
        }
        $authority = $port !== null ? "{$host}:{$port}" : $host;
        $path = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }
        if (!self::isCanonicalPath($path)) {
            return '';
        }
        $href = "{$scheme}://{$authority}{$path}";
        return rtrim($href, '/');
    }

    /**
     * A site path has exactly one spelling, so one site can't be listed twice under paths that
     * mean the same thing (`/x`, `//x`, `/a/../x`, `/%78`), which would also give one did:web
     * two site URLs. Rejects empty segments (other than a single trailing slash), `.` and `..`
     * segments, percent-encoding and any character outside unreserved URL characters.
     */
    private static function isCanonicalPath(string $path): bool
    {
        if ($path === '/') {
            return true;
        }
        if (!preg_match('#^/[A-Za-z0-9._~/-]*$#', $path)) {
            return false;
        }
        $trimmed = substr($path, 1);
        if (str_ends_with($trimmed, '/')) {
            $trimmed = substr($trimmed, 0, -1);
        }
        foreach (explode('/', $trimmed) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }
        return true;
    }

    /** Converts an HTTP(S) site URL into a did:web identifier, matching Webring Studio. */
    public static function siteUrlToDidWeb(?string $siteUrl): string
    {
        $normalized = self::normalizeSiteUrl($siteUrl);
        if ($normalized === '') {
            return '';
        }
        $parts = parse_url($normalized);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $host = strtolower(rtrim($parts['host'], '.'));
        if ($host === '' || str_contains($host, '..')) {
            return '';
        }
        $unbracketed = trim($host, '[]');
        if (
            filter_var($unbracketed, FILTER_VALIDATE_IP) === false &&
            !preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)*$/', $host)
        ) {
            return '';
        }
        if (!empty($parts['port'])) {
            $host .= '%3A' . (int) $parts['port'];
        }
        $path = $parts['path'] ?? '';
        $segments = array_values(array_filter(explode('/', $path), static fn(string $s): bool => $s !== ''));
        $encodedSegments = array_map('rawurlencode', $segments);
        return count($encodedSegments) > 0
            ? 'did:web:' . $host . ':' . implode(':', $encodedSegments)
            : 'did:web:' . $host;
    }

    /**
     * Mirrors Webring Studio's `canonicalizeJson`:
     * - Top-level object keys and nested object keys are sorted recursively (`ksort`).
     * - Any `proof` property on an object is dropped.
     * - Sequential arrays (and objects inside arrays) preserve their original element and key order!
     *
     * @param stdClass|array<string, mixed> $obj
     */
    public static function canonicalizeJson(stdClass|array $obj): string
    {
        $normalized = self::sortObject($obj);
        $json = json_encode($normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return is_string($json) ? $json : '{}';
    }

    /**
     * @param stdClass|array<string, mixed> $obj
     */
    private static function sortObject(stdClass|array $obj): stdClass
    {
        $props = $obj instanceof stdClass ? get_object_vars($obj) : $obj;
        ksort($props, SORT_STRING);
        $clone = new stdClass();
        foreach ($props as $key => $value) {
            $keyStr = (string) $key;
            if ($keyStr === 'proof') {
                continue;
            }
            if ($value instanceof stdClass || (is_array($value) && !array_is_list($value))) {
                $clone->{$keyStr} = self::sortObject($value);
            } else {
                $clone->{$keyStr} = $value;
            }
        }
        return $clone;
    }

    /** Extracts raw Base64 body bytes from a PEM block. */
    private static function pemToDer(string $pem, string $expectedLabel): ?string
    {
        $pattern = '/-----BEGIN ' . preg_quote($expectedLabel, '/') . '-----([A-Za-z0-9+\/=\r\n\s]+)-----END ' . preg_quote($expectedLabel, '/') . '-----/';
        if (!preg_match($pattern, $pem, $matches)) {
            return null;
        }
        $der = base64_decode(preg_replace('/\s+/', '', $matches[1]) ?? '', true);
        return is_string($der) && $der !== '' ? $der : null;
    }

    /** Decodes base64url (or standard base64) into raw bytes. */
    public static function base64UrlDecode(string $input): ?string
    {
        $trimmed = trim($input);
        if ($trimmed === '') {
            return null;
        }
        $base64 = strtr($trimmed, '-_', '+/');
        $pad = (4 - (strlen($base64) % 4)) % 4;
        if ($pad > 0) {
            $base64 .= str_repeat('=', $pad);
        }
        $decoded = base64_decode($base64, true);
        return is_string($decoded) && $decoded !== '' ? $decoded : null;
    }

    public static function base64UrlEncode(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }

    /**
     * Signs a payload (without a `proof` field) and returns a base64url Ed25519 signature.
     *
     * @param stdClass|array<string, mixed> $payload
     */
    public static function signPayload(stdClass|array $payload, string $privateKeyPem): string
    {
        $canonical = self::canonicalizeJson($payload);
        $der = self::pemToDer($privateKeyPem, 'PRIVATE KEY');
        if ($der === null || strlen($der) !== 48 || !str_starts_with($der, self::PKCS8_ED25519_PREFIX)) {
            throw new AppError(500, 'invalid_private_key', 'Could not read Ed25519 private key.');
        }
        $seed = substr($der, 16, 32);

        if (function_exists('sodium_crypto_sign_detached') && function_exists('sodium_crypto_sign_seed_keypair')) {
            $keypair = sodium_crypto_sign_seed_keypair($seed);
            $secretKey = sodium_crypto_sign_secretkey($keypair);
            $sig = sodium_crypto_sign_detached($canonical, $secretKey);
            return self::base64UrlEncode($sig);
        }

        $sig = self::ed25519SignDetachedPure($canonical, $seed);
        return self::base64UrlEncode($sig);
    }

    /**
     * Verifies a base64url Ed25519 signature over a payload.
     *
     * @param stdClass|array<string, mixed> $payload
     */
    public static function verifyPayloadSignature(stdClass|array $payload, string $signatureValue, string $publicKeyPem): bool
    {
        try {
            $sigBytes = self::base64UrlDecode($signatureValue);
            if ($sigBytes === null || strlen($sigBytes) !== 64) {
                return false;
            }
            $der = self::pemToDer($publicKeyPem, 'PUBLIC KEY');
            if ($der === null || strlen($der) !== 44 || !str_starts_with($der, self::SPKI_ED25519_PREFIX)) {
                return false;
            }
            $rawPub = substr($der, 12, 32);
            $canonical = self::canonicalizeJson($payload);

            if (function_exists('sodium_crypto_sign_verify_detached')) {
                return sodium_crypto_sign_verify_detached($sigBytes, $canonical, $rawPub);
            }

            return self::ed25519VerifyPure($sigBytes, $canonical, $rawPub);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Verifies the `proof` on a fetched Webring Studio document.
     *
     * @param mixed $doc
     */
    public static function verifyDocumentSignature(mixed $doc, string $publicKeyPem): bool
    {
        if ($doc instanceof stdClass) {
            $proof = $doc->proof ?? null;
            $sig = ($proof instanceof stdClass && isset($proof->signatureValue) && is_string($proof->signatureValue))
                ? $proof->signatureValue
                : '';
            if ($sig === '') {
                return false;
            }
            return self::verifyPayloadSignature($doc, $sig, $publicKeyPem);
        }
        if (is_array($doc) && !array_is_list($doc)) {
            $proof = $doc['proof'] ?? null;
            $sig = '';
            if (is_array($proof) && isset($proof['signatureValue']) && is_string($proof['signatureValue'])) {
                $sig = $proof['signatureValue'];
            } elseif ($proof instanceof stdClass && isset($proof->signatureValue) && is_string($proof->signatureValue)) {
                $sig = $proof->signatureValue;
            }
            if ($sig === '') {
                return false;
            }
            return self::verifyPayloadSignature($doc, $sig, $publicKeyPem);
        }
        return false;
    }

    /**
     * Mirrors Webring Studio's `verifyKeyRotationChain`: starting from the pinned key, each step
     * needs a `webring-key-rotation` certificate for the same did and site, signed by the current
     * key, leading to a key not seen before in the chain, for at most 10 steps. True when the chain
     * reaches `$targetPem`.
     *
     * @param list<mixed> $certificates decoded certificate objects, as published in did.json
     */
    public static function verifyKeyRotationChain(
        string $pinnedPem,
        string $targetPem,
        string $did,
        string $siteUrl,
        array $certificates
    ): bool {
        // Keys are compared by SPKI fingerprint, so a certificate whose PEM is wrapped or
        // line-broken differently from the pinned copy still links the chain.
        $current = trim($pinnedPem);
        $currentFp = self::spkiFingerprint($current);
        $targetFp = self::spkiFingerprint($targetPem);
        if ($currentFp === '' || $targetFp === '') {
            return false;
        }
        if ($currentFp === $targetFp) {
            return true;
        }
        $expectedSite = self::normalizeSiteUrl($siteUrl);
        $visited = [$currentFp => true];
        $maxHops = min(count($certificates), 10);

        for ($hop = 0; $hop < $maxHops; $hop++) {
            $next = null;
            foreach ($certificates as $cert) {
                if (!$cert instanceof stdClass) {
                    continue;
                }
                if (($cert->type ?? '') !== 'webring-key-rotation') {
                    continue;
                }
                $oldFp = is_string($cert->oldPublicKeyPem ?? null) ? self::spkiFingerprint($cert->oldPublicKeyPem) : '';
                $new = is_string($cert->newPublicKeyPem ?? null) ? trim($cert->newPublicKeyPem) : '';
                $newFp = $new !== '' ? self::spkiFingerprint($new) : '';
                if ($oldFp === '' || $oldFp !== $currentFp || $newFp === '' || isset($visited[$newFp])) {
                    continue;
                }
                if (($cert->did ?? '') !== $did) {
                    continue;
                }
                if (self::normalizeSiteUrl(is_string($cert->siteUrl ?? null) ? $cert->siteUrl : '') !== $expectedSite) {
                    continue;
                }
                if (!self::verifyDocumentSignature($cert, $current)) {
                    continue;
                }
                $next = $new;
                break;
            }
            if ($next === null) {
                return false;
            }
            $current = $next;
            $currentFp = self::spkiFingerprint($current);
            $visited[$currentFp] = true;
            if ($currentFp === $targetFp) {
                return true;
            }
        }
        return false;
    }

    /** Short, stable fingerprint of a public key (same scheme as Studio). */
    public static function publicKeyFingerprint(string $publicKeyPem): string
    {
        return substr(hash('sha256', trim($publicKeyPem)), 0, 32);
    }

    /**
     * SHA-256 (lowercase hex) over the key's SPKI DER bytes. Unlike `publicKeyFingerprint` it does
     * not depend on how the PEM is wrapped, so Webring Studio computes the same value from
     * `createPublicKey(pem).export({ type: 'spki', format: 'der' })`. Returns '' for bad input.
     */
    public static function spkiFingerprint(string $publicKeyPem): string
    {
        if (!preg_match('/-----BEGIN PUBLIC KEY-----(.+?)-----END PUBLIC KEY-----/s', $publicKeyPem, $m)) {
            return '';
        }
        $der = base64_decode((string) preg_replace('/\s+/', '', $m[1]), true);
        if ($der === false || $der === '') {
            return '';
        }
        return hash('sha256', $der);
    }

    /**
     * Generates an Ed25519 keypair in SPKI / PKCS#8 PEM format.
     *
     * @return array{publicKeyPem: string, privateKeyPem: string}
     */
    public static function generateKeyPair(): array
    {
        $seed = random_bytes(32);
        if (function_exists('sodium_crypto_sign_seed_keypair') && function_exists('sodium_crypto_sign_publickey')) {
            $keypair = sodium_crypto_sign_seed_keypair($seed);
            $rawPub = sodium_crypto_sign_publickey($keypair);
        } else {
            $rawPub = self::ed25519PublicKeyFromSeedPure($seed);
        }

        $spkiDer = self::SPKI_ED25519_PREFIX . $rawPub;
        $pkcs8Der = self::PKCS8_ED25519_PREFIX . $seed;

        $publicKeyPem = "-----BEGIN PUBLIC KEY-----\n"
            . chunk_split(base64_encode($spkiDer), 64, "\n")
            . "-----END PUBLIC KEY-----\n";
        $privateKeyPem = "-----BEGIN PRIVATE KEY-----\n"
            . chunk_split(base64_encode($pkcs8Der), 64, "\n")
            . "-----END PRIVATE KEY-----\n";

        return [
            'publicKeyPem' => $publicKeyPem,
            'privateKeyPem' => $privateKeyPem,
        ];
    }

    // =========================================================================
    // Pure-PHP 64-bit Ed25519 (RFC 8032 / TweetNaCl)
    // =========================================================================

    /**
     * @param list<int> $init
     * @return list<int>
     */
    private static function gf(array $init = []): array
    {
        $r = array_fill(0, 16, 0);
        foreach ($init as $i => $v) {
            $r[$i] = $v;
        }
        return $r;
    }

    /**
     * @param list<int> $o
     */
    private static function car25519(array &$o): void
    {
        for ($i = 0; $i < 16; $i++) {
            $o[$i] += 65536;
            $c = $o[$i] >> 16;
            if ($i < 15) {
                $o[$i + 1] += $c - 1;
            } else {
                $o[0] += 38 * ($c - 1);
            }
            $o[$i] -= $c << 16;
        }
    }

    /**
     * @param list<int> $p
     * @param list<int> $q
     */
    private static function sel25519(array &$p, array &$q, int $b): void
    {
        $c = ~($b - 1);
        for ($i = 0; $i < 16; $i++) {
            $t = $c & ($p[$i] ^ $q[$i]);
            $p[$i] ^= $t;
            $q[$i] ^= $t;
        }
    }

    /**
     * @param list<int> $n
     * @return list<int>
     */
    private static function pack25519(array $n): array
    {
        $m = self::gf();
        $t = self::gf($n);
        self::car25519($t);
        self::car25519($t);
        self::car25519($t);
        for ($j = 0; $j < 2; $j++) {
            $m[0] = $t[0] - 0xffed;
            for ($i = 1; $i < 15; $i++) {
                $m[$i] = $t[$i] - 0xffff - (($m[$i - 1] >> 16) & 1);
                $m[$i - 1] &= 0xffff;
            }
            $m[15] = $t[15] - 0x7fff - (($m[14] >> 16) & 1);
            $b = ($m[15] >> 16) & 1;
            $m[14] &= 0xffff;
            self::sel25519($t, $m, 1 - $b);
        }
        $o = array_fill(0, 32, 0);
        for ($i = 0; $i < 16; $i++) {
            $o[2 * $i] = $t[$i] & 0xff;
            $o[2 * $i + 1] = ($t[$i] >> 8) & 0xff;
        }
        return $o;
    }

    /**
     * @param list<int> $a
     * @param list<int> $b
     */
    private static function neq25519(array $a, array $b): int
    {
        $c = self::pack25519($a);
        $d = self::pack25519($b);
        $r = 0;
        for ($i = 0; $i < 32; $i++) {
            $r |= $c[$i] ^ $d[$i];
        }
        return $r !== 0 ? 1 : 0;
    }

    /**
     * @param list<int> $a
     */
    private static function par25519(array $a): int
    {
        $d = self::pack25519($a);
        return $d[0] & 1;
    }

    /**
     * @param list<int> $n
     * @return list<int>
     */
    private static function unpack25519(array $n): array
    {
        $o = self::gf();
        for ($i = 0; $i < 16; $i++) {
            $o[$i] = $n[2 * $i] + ($n[2 * $i + 1] << 8);
        }
        $o[15] &= 0x7fff;
        return $o;
    }

    /**
     * @param list<int> $a
     * @param list<int> $b
     * @return list<int>
     */
    private static function gfAdd(array $a, array $b): array
    {
        $o = self::gf();
        for ($i = 0; $i < 16; $i++) {
            $o[$i] = $a[$i] + $b[$i];
        }
        return $o;
    }

    /**
     * @param list<int> $a
     * @param list<int> $b
     * @return list<int>
     */
    private static function gfSub(array $a, array $b): array
    {
        $o = self::gf();
        for ($i = 0; $i < 16; $i++) {
            $o[$i] = $a[$i] - $b[$i];
        }
        return $o;
    }

    /**
     * @param list<int> $a
     * @param list<int> $b
     * @return list<int>
     */
    private static function gfMul(array $a, array $b): array
    {
        $t = array_fill(0, 31, 0);
        for ($i = 0; $i < 16; $i++) {
            $ai = $a[$i];
            for ($j = 0; $j < 16; $j++) {
                $t[$i + $j] += $ai * $b[$j];
            }
        }
        for ($i = 0; $i < 15; $i++) {
            $t[$i] += 38 * $t[$i + 16];
        }
        $o = array_slice($t, 0, 16);
        self::car25519($o);
        self::car25519($o);
        return $o;
    }

    /**
     * @param list<int> $a
     * @return list<int>
     */
    private static function gfSqr(array $a): array
    {
        return self::gfMul($a, $a);
    }

    /**
     * @param list<int> $i
     * @return list<int>
     */
    private static function inv25519(array $i): array
    {
        $c = self::gf($i);
        for ($a = 253; $a >= 0; $a--) {
            $c = self::gfSqr($c);
            if ($a !== 2 && $a !== 4) {
                $c = self::gfMul($c, $i);
            }
        }
        return $c;
    }

    /**
     * @param list<int> $i
     * @return list<int>
     */
    private static function pow2523(array $i): array
    {
        $c = self::gf($i);
        for ($a = 250; $a >= 0; $a--) {
            $c = self::gfSqr($c);
            if ($a !== 1) {
                $c = self::gfMul($c, $i);
            }
        }
        return $c;
    }

    /**
     * @param array{0: list<int>, 1: list<int>, 2: list<int>, 3: list<int>} $p
     * @param array{0: list<int>, 1: list<int>, 2: list<int>, 3: list<int>} $q
     */
    private static function pointAdd(array &$p, array $q): void
    {
        $a = self::gfSub($p[1], $p[0]);
        $t = self::gfSub($q[1], $q[0]);
        $a = self::gfMul($a, $t);
        $b = self::gfAdd($p[0], $p[1]);
        $t = self::gfAdd($q[0], $q[1]);
        $b = self::gfMul($b, $t);
        $c = self::gfMul($p[3], $q[3]);
        $c = self::gfMul($c, self::GF_D2);
        $d = self::gfMul($p[2], $q[2]);
        $d = self::gfAdd($d, $d);
        $e = self::gfSub($b, $a);
        $f = self::gfSub($d, $c);
        $g = self::gfAdd($d, $c);
        $h = self::gfAdd($b, $a);
        $p[0] = self::gfMul($e, $f);
        $p[1] = self::gfMul($h, $g);
        $p[2] = self::gfMul($g, $f);
        $p[3] = self::gfMul($e, $h);
    }

    /**
     * @param array{0: list<int>, 1: list<int>, 2: list<int>, 3: list<int>} $p
     * @param array{0: list<int>, 1: list<int>, 2: list<int>, 3: list<int>} $q
     */
    private static function cswap(array &$p, array &$q, int $b): void
    {
        for ($i = 0; $i < 4; $i++) {
            self::sel25519($p[$i], $q[$i], $b);
        }
    }

    /**
     * @param array{0: list<int>, 1: list<int>, 2: list<int>, 3: list<int>} $p
     * @return list<int>
     */
    private static function packPoint(array $p): array
    {
        $zi = self::inv25519($p[2]);
        $tx = self::gfMul($p[0], $zi);
        $ty = self::gfMul($p[1], $zi);
        $r = self::pack25519($ty);
        $r[31] ^= self::par25519($tx) << 7;
        return $r;
    }

    /**
     * @param array{0: list<int>, 1: list<int>, 2: list<int>, 3: list<int>} $q
     * @param list<int> $s
     * @return array{0: list<int>, 1: list<int>, 2: list<int>, 3: list<int>}
     */
    private static function scalarmult(array $q, array $s): array
    {
        $p = [self::gf(), self::gf([1]), self::gf([1]), self::gf()];
        $qCopy = [self::gf($q[0]), self::gf($q[1]), self::gf($q[2]), self::gf($q[3])];
        for ($i = 255; $i >= 0; $i--) {
            $b = ($s[$i >> 3] >> ($i & 7)) & 1;
            self::cswap($p, $qCopy, $b);
            self::pointAdd($qCopy, $p);
            self::pointAdd($p, $p);
            self::cswap($p, $qCopy, $b);
        }
        return $p;
    }

    /**
     * @param list<int> $s
     * @return array{0: list<int>, 1: list<int>, 2: list<int>, 3: list<int>}
     */
    private static function scalarbase(array $s): array
    {
        $q = [self::GF_X, self::GF_Y, self::gf([1]), self::gfMul(self::GF_X, self::GF_Y)];
        return self::scalarmult($q, $s);
    }

    /**
     * @param list<int> $p
     * @return array{0: list<int>, 1: list<int>, 2: list<int>, 3: list<int>}|null
     */
    private static function unpackneg(array $p): ?array
    {
        $r0 = self::gf();
        $r1 = self::unpack25519($p);
        $r2 = self::gf([1]);

        $num = self::gfSqr($r1);
        $den = self::gfMul($num, self::GF_D);
        $num = self::gfSub($num, $r2);
        $den = self::gfAdd($r2, $den);
        $den2 = self::gfSqr($den);
        $den4 = self::gfSqr($den2);
        $den6 = self::gfMul($den4, $den2);
        $t = self::gfMul($den6, $num);
        $t = self::gfMul($t, $den);
        $t = self::pow2523($t);
        $t = self::gfMul($t, $num);
        $t = self::gfMul($t, $den);
        $t = self::gfMul($t, $den);
        $r0 = self::gfMul($t, $den);

        $chk = self::gfSqr($r0);
        $chk = self::gfMul($chk, $den);
        if (self::neq25519($chk, $num) !== 0) {
            $r0 = self::gfMul($r0, self::GF_I);
        }
        $chk = self::gfSqr($r0);
        $chk = self::gfMul($chk, $den);
        if (self::neq25519($chk, $num) !== 0) {
            return null;
        }
        if (self::par25519($r0) === (($p[31] >> 7) & 1)) {
            $r0 = self::gfSub(self::gf(), $r0);
        }
        $r3 = self::gfMul($r0, $r1);
        return [$r0, $r1, $r2, $r3];
    }

    /**
     * @param list<int> $x
     * @return list<int>
     */
    private static function modL(array $x): array
    {
        for ($i = 63; $i >= 32; $i--) {
            $carry = 0;
            $j = $i - 32;
            $k = $i - 12;
            for (; $j < $k; $j++) {
                $x[$j] += $carry - 16 * $x[$i] * self::MOD_L[$j - ($i - 32)];
                $carry = ($x[$j] + 128) >> 8;
                $x[$j] -= $carry << 8;
            }
            $x[$j] += $carry;
            $x[$i] = 0;
        }
        $carry = 0;
        for ($j = 0; $j < 32; $j++) {
            $x[$j] += $carry - ($x[31] >> 4) * self::MOD_L[$j];
            $carry = $x[$j] >> 8;
            $x[$j] &= 255;
        }
        for ($j = 0; $j < 32; $j++) {
            $x[$j] -= $carry * self::MOD_L[$j];
        }
        $r = array_fill(0, 32, 0);
        for ($i = 0; $i < 32; $i++) {
            $x[$i + 1] += $x[$i] >> 8;
            $r[$i] = $x[$i] & 255;
        }
        return $r;
    }

    private static function ed25519VerifyPure(string $sigBytes, string $msg, string $pubBytes): bool
    {
        if (strlen($sigBytes) !== 64 || strlen($pubBytes) !== 32) {
            return false;
        }
        $sigArr = array_values(unpack('C64', $sigBytes));
        $pubArr = array_values(unpack('C32', $pubBytes));

        $q = self::unpackneg($pubArr);
        if ($q === null) {
            return false;
        }

        $hRaw = hash('sha512', substr($sigBytes, 0, 32) . $pubBytes . $msg, true);
        $hArr = array_values(unpack('C64', $hRaw));
        $hReduced = self::modL($hArr);

        $p = self::scalarmult($q, $hReduced);
        $pBase = self::scalarbase(array_slice($sigArr, 32, 32));
        self::pointAdd($p, $pBase);

        $t = self::packPoint($p);
        $diff = 0;
        for ($i = 0; $i < 32; $i++) {
            $diff |= $sigArr[$i] ^ $t[$i];
        }
        return $diff === 0;
    }

    private static function ed25519PublicKeyFromSeedPure(string $seed): string
    {
        $d = hash('sha512', $seed, true);
        $s = array_values(unpack('C32', substr($d, 0, 32)));
        $s[0] &= 248;
        $s[31] &= 127;
        $s[31] |= 64;
        $p = self::scalarbase($s);
        $pk = self::packPoint($p);
        return pack('C*', ...$pk);
    }

    private static function ed25519SignDetachedPure(string $msg, string $seed): string
    {
        $d = hash('sha512', $seed, true);
        $s = array_values(unpack('C32', substr($d, 0, 32)));
        $s[0] &= 248;
        $s[31] &= 127;
        $s[31] |= 64;
        $p = self::scalarbase($s);
        $pkArr = self::packPoint($p);
        $pkBytes = pack('C*', ...$pkArr);

        $rRaw = hash('sha512', substr($d, 32, 32) . $msg, true);
        $rArr = array_values(unpack('C64', $rRaw));
        $rReduced = self::modL($rArr);

        $Rpoint = self::scalarbase($rReduced);
        $Rarr = self::packPoint($Rpoint);
        $Rbytes = pack('C*', ...$Rarr);

        $hramRaw = hash('sha512', $Rbytes . $pkBytes . $msg, true);
        $hramArr = array_values(unpack('C64', $hramRaw));
        $k = self::modL($hramArr);

        $x = array_fill(0, 64, 0);
        for ($i = 0; $i < 32; $i++) {
            $x[$i] = $rReduced[$i];
        }
        for ($i = 0; $i < 32; $i++) {
            for ($j = 0; $j < 32; $j++) {
                $x[$i + $j] += $k[$i] * $s[$j];
            }
        }
        $Sarr = self::modL($x);
        $Sbytes = pack('C*', ...$Sarr);

        return $Rbytes . $Sbytes;
    }
}
