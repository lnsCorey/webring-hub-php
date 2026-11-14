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
     * Deletes an entry and emits `entry.removed`. `$reason` is `self` (signed by the site) or
     * `admin`. Extensions must delete what they hold for this DID.
     */
    public static function remove(PDO $pdo, Extensions $ext, string $did, string $reason): bool
    {
        $row = Database::getEntryByDid($pdo, $did);
        if (!Database::deleteEntry($pdo, $did)) {
            return false;
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
        $ext->emit('entry.status_changed', ['did' => $did, 'status' => $status]);
        return true;
    }
}
