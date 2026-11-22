<?php
declare(strict_types=1);

namespace WebringHub;

use PDO;

/**
 * The one place the hub saves, removes or re-flags an entry, so extensions hear about
 * every change. Events fire only after the core change has committed.
 */
final class Entries
{
    /**
     * Saves a verified site (from `Site::verifySite`) and emits `entry.verified`. When the core
     * refuses a new key (409 `key_changed`) it emits `entry.key_rejected` and rethrows.
     *
     * @param array<string, mixed> $site
     * @return array{inserted: bool, keyRotated: bool, keyAcceptedBy: 'chain'|'operator'|null, previousKeyPem: string|null}
     */
    public static function saveVerified(PDO $pdo, Extensions $ext, array $site, bool $acceptKeyChange = false): array
    {
        try {
            $result = Database::upsertEntry($pdo, $site, null, $acceptKeyChange);
        } catch (AppError $err) {
            if ($err->errorCode === 'key_changed') {
                $row = Database::getEntryByDid($pdo, (string) ($site['did'] ?? ''));
                $ext->emit('entry.key_rejected', ['site' => $site, 'row' => $row]);
            }
            throw $err;
        }
        $row = Database::getEntryByDid($pdo, (string) $site['did']);
        $ext->emit('entry.verified', [
            'site' => $site,
            'row' => $row,
            'inserted' => $result['inserted'],
            'keyRotated' => $result['keyRotated'],
            'keyAcceptedBy' => $result['keyAcceptedBy'],
            'previousKeyPem' => $result['previousKeyPem'],
        ]);
        return $result;
    }

    /**
     * Accepts a site's new signing key after the operator reviewed it (item 24 of the security
     * audit). Only the candidate key recorded when the change was first detected can be trusted,
     * only when the operator names its fingerprint, and only while the site still serves it.
     * Otherwise whoever controls the site at the moment of the click could slip in their own key.
     *
     * @param array<string, mixed> $row the entry as stored
     * @param callable(): array<string, mixed> $fetchSite fetches and verifies the site now (Site::verifySite)
     * @return array{inserted: bool, keyRotated: bool, keyAcceptedBy: 'chain'|'operator'|null, previousKeyPem: string|null}
     */
    public static function trustCandidateKey(PDO $pdo, Extensions $ext, array $row, mixed $expectedFingerprint, callable $fetchSite): array
    {
        if (!is_string($expectedFingerprint) || !preg_match('/^[0-9a-f]{64}$/', strtolower($expectedFingerprint))) {
            throw new AppError(400, 'invalid_request', 'Send the fingerprint of the key you reviewed as expectedFingerprint (64 hex characters).');
        }
        $expected = strtolower($expectedFingerprint);
        $did = (string) $row['did'];
        $candidate = Database::getCandidateKey($pdo, $did);
        if ($candidate === null) {
            throw new AppError(409, 'no_candidate', 'There is no new key waiting for review for this site. Refresh it first; if its key changed, the new key will appear here.');
        }
        if (!hash_equals($candidate['fingerprint'], $expected)) {
            throw new AppError(409, 'candidate_mismatch', 'That fingerprint is not the new key recorded for this site. Reload the page and review the key again.');
        }
        $site = $fetchSite();
        if (($site['did'] ?? '') !== $did) {
            throw new AppError(409, 'identity_changed', 'The site now publishes a different did:web identity.');
        }
        $servedFp = Verify::spkiFingerprint((string) ($site['publicKeyPem'] ?? ''));
        if ($servedFp !== '' && hash_equals(Verify::spkiFingerprint((string) ($row['public_key_pem'] ?? '')), $servedFp)) {
            // The site serves its pinned key again: nothing to trust. A normal re-verify clears the
            // stale candidate instead of recording the pinned key as a second "new" key.
            return self::saveVerified($pdo, $ext, $site);
        }
        if (!hash_equals($candidate['fingerprint'], $servedFp)) {
            Database::recordCandidateKey($pdo, $did, (string) ($site['publicKeyPem'] ?? ''));
            throw new AppError(409, 'served_key_changed', 'The site is now serving a different key from the one you reviewed. Nothing was changed; review the site again.');
        }
        return self::saveVerified($pdo, $ext, $site, true);
    }

    /**
     * Deletes an entry and emits `entry.removed`. `$reason` is `self` (signed by the site) or
     * `admin`. Extensions must delete what they hold for this DID.
     */
    public static function remove(PDO $pdo, Extensions $ext, string $did, string $reason): bool
    {
        $row = Database::getEntryByDid($pdo, $did);
        if (!Database::deleteEntry($pdo, $did)) {
            return false;
        }
        if (class_exists(Auth::class)) {
            try {
                Auth::purgeUser($pdo, $did);
            } catch (\Throwable) {
                // Auth tables may not exist yet when auto_migrate is off.
            }
        }
        $ext->emit('entry.removed', [
            'did' => $did,
            'siteUrl' => $row !== null ? (string) $row['site_url'] : '',
            'reason' => $reason,
        ]);
        return true;
    }

    public static function setStatus(PDO $pdo, Extensions $ext, string $did, string $status): bool
    {
        if (!Database::setEntryStatus($pdo, $did, $status)) {
            return false;
        }
        // The block list outlives the listing, so a blocked site can't return by deleting itself
        // and re-submitting. Only making the entry active again (Unblock) lifts it.
        if ($status === 'blocked') {
            $row = Database::getEntryByDid($pdo, $did);
            Database::blockSite($pdo, $did, $row !== null ? (string) $row['site_url'] : '', 'blocked by the operator');
        } elseif ($status === 'active') {
            Database::unblockSite($pdo, $did);
        }
        $ext->emit('entry.status_changed', ['did' => $did, 'status' => $status]);
        return true;
    }
}
