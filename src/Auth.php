<?php
declare(strict_types=1);

namespace WebringHub;

use PDO;

/**
 * Cryptographic DID authentication, HttpOnly user sessions, CSRF checks, and per-extension
 * opt-in / opt-out data consent for Webring Hub.
 */
final class Auth
{
    public const SESSION_COOKIE = 'webring_hub_session';
    public const SESSION_TTL_SEC = 7 * 86400;
    public const CHALLENGE_TTL_SEC = 5 * 60;
    public const LAUNCH_TTL_SEC = 2 * 60;
    public const MAX_CLOCK_SKEW_SEC = 10 * 60;

    /**
     * Creates a short-lived browser sign-in challenge to be approved by Webring Studio (or CLI).
     *
     * @return array{challengeId: string, challengeSecret: string, nonce: string, hubUrl: string, requestedExt: string, expiresAt: string}
     */
    public static function createChallenge(PDO $pdo, string $hubUrl, string $requestedExt = ''): array
    {
        $expectedHubUrl = Verify::normalizeSiteUrl($hubUrl);
        if ($expectedHubUrl === '') {
            throw new AppError(
                503,
                'hub_url_not_configured',
                'This hub has not configured its public URL, so DID sign-in is unavailable. Contact the hub operator.'
            );
        }

        $cleanExt = preg_match('/^[a-z][a-z0-9-]{1,40}$/', $requestedExt) ? $requestedExt : '';
        $challengeId = bin2hex(random_bytes(16));
        $challengeSecret = bin2hex(random_bytes(24));
        $nonce = bin2hex(random_bytes(16));
        $nowTs = time();
        $nowIso = gmdate('Y-m-d\TH:i:s.000\Z', $nowTs);
        $expiresIso = gmdate('Y-m-d\TH:i:s.000\Z', $nowTs + self::CHALLENGE_TTL_SEC);

        // Opportunistic cleanup of expired challenges
        $pdo->prepare('DELETE FROM auth_challenges WHERE expires_at < ?')->execute([$nowIso]);

        $stmt = $pdo->prepare(
            'INSERT INTO auth_challenges (id, secret_hash, nonce, requested_ext, approved_did, approved_opt_in_json, expires_at, claimed_at, created_at)
             VALUES (?, ?, ?, ?, NULL, NULL, ?, NULL, ?)'
        );
        $stmt->execute([
            $challengeId,
            hash('sha256', $challengeSecret),
            $nonce,
            $cleanExt,
            $expiresIso,
            $nowIso,
        ]);

        return [
            'challengeId' => $challengeId,
            'challengeSecret' => $challengeSecret,
            'nonce' => $nonce,
            'hubUrl' => $expectedHubUrl,
            'requestedExt' => $cleanExt,
            'expiresAt' => $expiresIso,
        ];
    }

    /**
     * Verifies an Ed25519-signed `hub_auth` payload from Webring Studio (or CLI).
     * Supports both:
     *  - approving an existing browser `challengeId` (deep-link handoff), and
     *  - issuing a one-time direct-launch code when `challengeId` is empty (Studio → Hub launch).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function verifySignedAuth(PDO $pdo, array $body, string $hubUrl, Extensions $extensions): array
    {
        $expectedHubUrl = Verify::normalizeSiteUrl($hubUrl);
        if ($expectedHubUrl === '') {
            throw new AppError(
                503,
                'hub_url_not_configured',
                'This hub has not configured its public URL, so DID sign-in is unavailable.'
            );
        }

        $did = is_string($body['did'] ?? null) ? trim($body['did']) : '';
        $siteUrl = is_string($body['siteUrl'] ?? null) ? trim($body['siteUrl']) : '';
        $reqHubUrl = is_string($body['hubUrl'] ?? null) ? trim($body['hubUrl']) : '';
        $nonce = is_string($body['nonce'] ?? null) ? trim($body['nonce']) : '';
        $createdAt = is_string($body['createdAt'] ?? null) ? trim($body['createdAt']) : '';
        $signature = is_string($body['signature'] ?? null) ? trim($body['signature']) : '';
        $challengeId = is_string($body['challengeId'] ?? null) ? trim($body['challengeId']) : '';

        if ($did === '' || $siteUrl === '' || $reqHubUrl === '' || $nonce === '' || $createdAt === '' || $signature === '') {
            throw new AppError(400, 'invalid_request', 'Signed auth requires did, siteUrl, hubUrl, nonce, createdAt and signature.');
        }
        if (Verify::normalizeSiteUrl($reqHubUrl) !== $expectedHubUrl) {
            throw new AppError(403, 'hub_mismatch', 'The auth request is not addressed to this hub.');
        }

        $createdTs = strtotime($createdAt);
        if ($createdTs === false || abs(time() - $createdTs) > self::MAX_CLOCK_SKEW_SEC) {
            throw new AppError(400, 'stale_request', 'The signed auth request is expired or has an invalid timestamp.');
        }

        $row = Database::getEntryByDid($pdo, $did);
        if ($row === null) {
            throw new AppError(404, 'not_found', 'This DID is not listed on this hub.');
        }
        if (($row['status'] ?? '') !== 'active') {
            throw new AppError(403, 'entry_not_active', 'Only active listed sites can sign in.');
        }
        if (Verify::normalizeSiteUrl($siteUrl) !== (string) $row['site_url']) {
            throw new AppError(403, 'site_mismatch', 'siteUrl does not match the listed entry for this DID.');
        }

        $rawOptIns = is_array($body['optInExtensions'] ?? null) ? $body['optInExtensions'] : [];
        $optInExtensions = [];
        foreach ($rawOptIns as $extId) {
            if (is_string($extId) && preg_match('/^[a-z][a-z0-9-]{1,40}$/', $extId) && $extensions->isLoaded($extId)) {
                $optInExtensions[] = $extId;
            }
        }
        $optInExtensions = array_values(array_unique($optInExtensions));
        sort($optInExtensions);

        $payload = [
            'action' => 'hub_auth',
            'hubUrl' => $expectedHubUrl,
            'did' => (string) $row['did'],
            'siteUrl' => (string) $row['site_url'],
            'nonce' => $nonce,
            'challengeId' => $challengeId,
            'optInExtensions' => $optInExtensions,
            'createdAt' => $createdAt,
        ];
        if (!Verify::verifyPayloadSignature($payload, $signature, (string) $row['public_key_pem'])) {
            throw new AppError(403, 'invalid_signature', 'The Ed25519 signature did not verify for this entry.');
        }

        $nowIso = Database::isoNow();
        $optInJson = json_encode($optInExtensions, JSON_UNESCAPED_SLASHES) ?: '[]';

        if ($challengeId !== '') {
            $stmt = $pdo->prepare('SELECT id, nonce, approved_did, expires_at, claimed_at FROM auth_challenges WHERE id = ?');
            $stmt->execute([$challengeId]);
            $ch = $stmt->fetch();
            if (!is_array($ch) || (string) $ch['nonce'] !== $nonce) {
                throw new AppError(404, 'invalid_challenge', 'Auth challenge not found or nonce mismatch.');
            }
            if ((string) $ch['expires_at'] < $nowIso || !empty($ch['claimed_at']) || !empty($ch['approved_did'])) {
                throw new AppError(409, 'challenge_expired', 'Auth challenge has expired or was already used.');
            }
            $upd = $pdo->prepare(
                'UPDATE auth_challenges SET approved_did = ?, approved_opt_in_json = ? WHERE id = ? AND approved_did IS NULL AND claimed_at IS NULL'
            );
            $upd->execute([(string) $row['did'], $optInJson, $challengeId]);
            if ($upd->rowCount() !== 1) {
                throw new AppError(409, 'challenge_expired', 'Auth challenge was already approved.');
            }
            return [
                'ok' => true,
                'mode' => 'challenge',
                'did' => (string) $row['did'],
                'optInExtensions' => $optInExtensions,
            ];
        }

        // Direct launch from Studio: consume core single-use nonce and create a pre-approved launch code
        if (!Database::consumeNonce($pdo, $nonce)) {
            throw new AppError(409, 'invalid_nonce', 'The nonce is unknown, expired, or already used.');
        }
        $launchCode = bin2hex(random_bytes(16));
        $launchSecret = bin2hex(random_bytes(24));
        $expiresIso = gmdate('Y-m-d\TH:i:s.000\Z', time() + self::LAUNCH_TTL_SEC);

        $ins = $pdo->prepare(
            'INSERT INTO auth_challenges (id, secret_hash, nonce, requested_ext, approved_did, approved_opt_in_json, expires_at, claimed_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, NULL, ?)'
        );
        $ins->execute([
            $launchCode,
            hash('sha256', $launchSecret),
            $nonce,
            '',
            (string) $row['did'],
            $optInJson,
            $expiresIso,
            $nowIso,
        ]);

        return [
            'ok' => true,
            'mode' => 'launch',
            'code' => $launchCode,
            'secret' => $launchSecret,
            'expiresAt' => $expiresIso,
            'did' => (string) $row['did'],
        ];
    }

    /**
     * Claims an approved challenge (or returns `pending` while awaiting Studio approval).
     *
     * @return array{status: string, token?: string, csrfToken?: string, did?: string, expiresAt?: string}
     */
    public static function claimChallenge(PDO $pdo, string $challengeId, string $challengeSecret, Extensions $extensions): array
    {
        if ($challengeId === '' || $challengeSecret === '') {
            throw new AppError(400, 'invalid_request', 'challengeId and challengeSecret are required.');
        }
        $stmt = $pdo->prepare('SELECT * FROM auth_challenges WHERE id = ?');
        $stmt->execute([$challengeId]);
        $ch = $stmt->fetch();
        if (!is_array($ch) || !hash_equals((string) $ch['secret_hash'], hash('sha256', $challengeSecret))) {
            throw new AppError(404, 'invalid_challenge', 'Auth challenge not found.');
        }
        $nowIso = Database::isoNow();
        if ((string) $ch['expires_at'] < $nowIso) {
            throw new AppError(410, 'challenge_expired', 'Auth challenge has expired.');
        }
        if (!empty($ch['claimed_at'])) {
            throw new AppError(409, 'challenge_used', 'Auth challenge has already been claimed.');
        }
        if (empty($ch['approved_did'])) {
            return ['status' => 'pending'];
        }

        $upd = $pdo->prepare('UPDATE auth_challenges SET claimed_at = ? WHERE id = ? AND claimed_at IS NULL');
        $upd->execute([$nowIso, $challengeId]);
        if ($upd->rowCount() !== 1) {
            throw new AppError(409, 'challenge_used', 'Auth challenge has already been claimed.');
        }

        $did = (string) $ch['approved_did'];
        $entry = Database::getEntryByDid($pdo, $did);
        if ($entry === null || ($entry['status'] ?? '') !== 'active') {
            throw new AppError(403, 'entry_not_active', 'This site is no longer active on this hub.');
        }

        $optIns = json_decode((string) ($ch['approved_opt_in_json'] ?? '[]'), true);
        if (is_array($optIns)) {
            foreach ($optIns as $extId) {
                if (is_string($extId) && $extensions->isLoaded($extId)) {
                    self::setConsent($pdo, $did, $extId, true, null);
                }
            }
        }

        $rawToken = bin2hex(random_bytes(32));
        $csrfToken = bin2hex(random_bytes(24));
        $expiresIso = gmdate('Y-m-d\TH:i:s.000\Z', time() + self::SESSION_TTL_SEC);

        // Clean up expired sessions
        $pdo->prepare('DELETE FROM user_sessions WHERE expires_at < ?')->execute([$nowIso]);

        $ins = $pdo->prepare(
            'INSERT INTO user_sessions (token_hash, did, csrf_token, created_at, last_seen_at, expires_at)
             VALUES (?, ?, ?, ?, ?, ?)'
        );
        $ins->execute([
            hash('sha256', $rawToken),
            $did,
            $csrfToken,
            $nowIso,
            $nowIso,
            $expiresIso,
        ]);

        return [
            'status' => 'authenticated',
            'token' => $rawToken,
            'csrfToken' => $csrfToken,
            'did' => $did,
            'expiresAt' => $expiresIso,
        ];
    }

    /**
     * Resolves a raw session cookie token to an active session + entry, or null.
     *
     * @return array{did: string, csrfToken: string, expiresAt: string, entry: array<string, mixed>}|null
     */
    public static function getSession(PDO $pdo, string $rawToken): ?array
    {
        if ($rawToken === '' || strlen($rawToken) < 32) {
            return null;
        }
        $hash = hash('sha256', $rawToken);
        $stmt = $pdo->prepare('SELECT did, csrf_token, expires_at FROM user_sessions WHERE token_hash = ?');
        $stmt->execute([$hash]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            return null;
        }
        $nowIso = Database::isoNow();
        if ((string) $row['expires_at'] < $nowIso) {
            $pdo->prepare('DELETE FROM user_sessions WHERE token_hash = ?')->execute([$hash]);
            return null;
        }
        $entry = Database::getEntryByDid($pdo, (string) $row['did']);
        if ($entry === null || ($entry['status'] ?? '') !== 'active') {
            $pdo->prepare('DELETE FROM user_sessions WHERE token_hash = ?')->execute([$hash]);
            return null;
        }
        return [
            'did' => (string) $row['did'],
            'csrfToken' => (string) $row['csrf_token'],
            'expiresAt' => (string) $row['expires_at'],
            'entry' => $entry,
        ];
    }

    public static function revokeSession(PDO $pdo, string $rawToken): void
    {
        if ($rawToken === '') {
            return;
        }
        $pdo->prepare('DELETE FROM user_sessions WHERE token_hash = ?')->execute([hash('sha256', $rawToken)]);
    }

    public static function purgeUser(PDO $pdo, string $did): void
    {
        if ($did === '') {
            return;
        }
        $pdo->prepare('DELETE FROM user_sessions WHERE did = ?')->execute([$did]);
        $pdo->prepare('DELETE FROM user_extension_consents WHERE did = ?')->execute([$did]);
        $pdo->prepare('DELETE FROM auth_challenges WHERE approved_did = ?')->execute([$did]);
    }

    /**
     * Validates the CSRF header + Sec-Fetch-Site on cookie-authenticated POST/DELETE requests.
     *
     * @param array{did: string, csrfToken: string} $session
     * @param array<string, mixed> $server
     */
    public static function requireCsrf(array $session, array $server): void
    {
        $fetchSite = strtolower((string) ($server['HTTP_SEC_FETCH_SITE'] ?? ''));
        if ($fetchSite !== '' && $fetchSite !== 'same-origin' && $fetchSite !== 'none') {
            throw new AppError(403, 'csrf_rejected', 'Cross-site request rejected.');
        }
        $header = trim((string) ($server['HTTP_X_HUB_CSRF'] ?? ''));
        if ($header === '' || !hash_equals($session['csrfToken'], $header)) {
            throw new AppError(403, 'csrf_invalid', 'Missing or invalid CSRF token.');
        }
    }

    public static function isOptedIn(PDO $pdo, string $did, string $extId): bool
    {
        if ($did === '' || $extId === '') {
            return false;
        }
        $stmt = $pdo->prepare('SELECT opted_in FROM user_extension_consents WHERE did = ? AND extension_id = ?');
        $stmt->execute([$did, $extId]);
        return (int) ($stmt->fetchColumn() ?: 0) === 1;
    }

    /**
     * Sets opt-in state for one `(did, extension_id)`. When opting out (`$optedIn === false`),
     * fires `user.extension_opt_out` so the extension deletes all user-specific data for that DID.
     *
     * @return array{extensionId: string, optedIn: bool, updatedAt: string}
     */
    public static function setConsent(PDO $pdo, string $did, string $extId, bool $optedIn, ?Extensions $extensions = null): array
    {
        $now = Database::isoNow();
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        $optInt = $optedIn ? 1 : 0;
        $inAt = $optedIn ? $now : null;
        $outAt = $optedIn ? null : $now;

        if ($driver === 'sqlite') {
            $stmt = $pdo->prepare(
                'INSERT INTO user_extension_consents (did, extension_id, opted_in, opted_in_at, opted_out_at)
                 VALUES (?, ?, ?, ?, ?)
                 ON CONFLICT(did, extension_id) DO UPDATE SET
                   opted_in = excluded.opted_in,
                   opted_in_at = COALESCE(excluded.opted_in_at, user_extension_consents.opted_in_at),
                   opted_out_at = excluded.opted_out_at'
            );
        } else {
            $stmt = $pdo->prepare(
                'INSERT INTO user_extension_consents (did, extension_id, opted_in, opted_in_at, opted_out_at)
                 VALUES (?, ?, ?, ?, ?)
                 ON DUPLICATE KEY UPDATE
                   opted_in = VALUES(opted_in),
                   opted_in_at = COALESCE(VALUES(opted_in_at), opted_in_at),
                   opted_out_at = VALUES(opted_out_at)'
            );
        }
        $stmt->execute([$did, $extId, $optInt, $inAt, $outAt]);

        if (!$optedIn && $extensions !== null) {
            $extensions->emit('user.extension_opt_out', [
                'did' => $did,
                'extensionId' => $extId,
            ]);
        }

        return [
            'extensionId' => $extId,
            'optedIn' => $optedIn,
            'updatedAt' => $now,
        ];
    }

    /**
     * Lists every enabled extension that declares `userData.optInRequired`, with this user's consent state.
     *
     * @return list<array{extensionId: string, name: string, summary: string, optedIn: bool, optedInAt: ?string, optedOutAt: ?string}>
     */
    public static function listConsents(PDO $pdo, string $did, Extensions $extensions): array
    {
        $stmt = $pdo->prepare(
            'SELECT extension_id, opted_in, opted_in_at, opted_out_at FROM user_extension_consents WHERE did = ?'
        );
        $stmt->execute([$did]);
        $rowsByExt = [];
        foreach ($stmt->fetchAll() as $r) {
            $rowsByExt[(string) $r['extension_id']] = $r;
        }

        $out = [];
        foreach ($extensions->userDataExtensions() as $ext) {
            $id = $ext['id'];
            $r = $rowsByExt[$id] ?? null;
            $out[] = [
                'extensionId' => $id,
                'name' => $ext['name'],
                'summary' => $ext['summary'],
                'optedIn' => $r !== null && (int) $r['opted_in'] === 1,
                'optedInAt' => $r['opted_in_at'] ?? null,
                'optedOutAt' => $r['opted_out_at'] ?? null,
            ];
        }
        return $out;
    }

    /**
     * Stateless Ed25519-signed opt-out + data wipe (for Webring Studio or CLI without browser session).
     *
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public static function verifySignedOptOut(PDO $pdo, array $body, string $hubUrl, string $extId, Extensions $extensions): array
    {
        $expectedHubUrl = Verify::normalizeSiteUrl($hubUrl);
        if ($expectedHubUrl === '') {
            throw new AppError(503, 'hub_url_not_configured', 'This hub has not configured its public URL.');
        }
        if (!$extensions->isLoaded($extId)) {
            throw new AppError(404, 'not_found', 'Extension not found.');
        }

        $did = is_string($body['did'] ?? null) ? trim($body['did']) : '';
        $siteUrl = is_string($body['siteUrl'] ?? null) ? trim($body['siteUrl']) : '';
        $reqHubUrl = is_string($body['hubUrl'] ?? null) ? trim($body['hubUrl']) : '';
        $nonce = is_string($body['nonce'] ?? null) ? trim($body['nonce']) : '';
        $createdAt = is_string($body['createdAt'] ?? null) ? trim($body['createdAt']) : '';
        $signature = is_string($body['signature'] ?? null) ? trim($body['signature']) : '';

        if ($did === '' || $siteUrl === '' || $reqHubUrl === '' || $nonce === '' || $createdAt === '' || $signature === '') {
            throw new AppError(400, 'invalid_request', 'Signed opt-out requires did, siteUrl, hubUrl, nonce, createdAt and signature.');
        }
        if (Verify::normalizeSiteUrl($reqHubUrl) !== $expectedHubUrl) {
            throw new AppError(403, 'hub_mismatch', 'The opt-out request is not addressed to this hub.');
        }
        $createdTs = strtotime($createdAt);
        if ($createdTs === false || abs(time() - $createdTs) > self::MAX_CLOCK_SKEW_SEC) {
            throw new AppError(400, 'stale_request', 'The signed opt-out request is expired.');
        }
        $row = Database::getEntryByDid($pdo, $did);
        if ($row === null) {
            throw new AppError(404, 'not_found', 'No such entry.');
        }
        if (Verify::normalizeSiteUrl($siteUrl) !== (string) $row['site_url']) {
            throw new AppError(403, 'site_mismatch', 'siteUrl does not match this DID.');
        }

        $payload = [
            'action' => 'ext_opt_out',
            'hubUrl' => $expectedHubUrl,
            'did' => (string) $row['did'],
            'siteUrl' => (string) $row['site_url'],
            'extensionId' => $extId,
            'nonce' => $nonce,
            'createdAt' => $createdAt,
        ];
        if (!Verify::verifyPayloadSignature($payload, $signature, (string) $row['public_key_pem'])) {
            throw new AppError(403, 'invalid_signature', 'The opt-out signature did not verify.');
        }
        if (!Database::consumeNonce($pdo, $nonce)) {
            throw new AppError(409, 'invalid_nonce', 'The nonce is unknown, expired, or already used.');
        }

        return self::setConsent($pdo, (string) $row['did'], $extId, false, $extensions);
    }

    public static function setSessionCookie(string $token, bool $isHttps): void
    {
        setcookie(self::SESSION_COOKIE, $token, [
            'expires' => time() + self::SESSION_TTL_SEC,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function clearSessionCookie(bool $isHttps): void
    {
        setcookie(self::SESSION_COOKIE, '', [
            'expires' => time() - 3600,
            'path' => '/',
            'secure' => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}
