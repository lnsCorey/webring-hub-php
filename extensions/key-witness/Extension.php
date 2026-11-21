<?php
declare(strict_types=1);

namespace WebringHub\Ext\KeyWitness;

use PDO;
use WebringHub\AppError;
use WebringHub\Extension;
use WebringHub\ExtensionContext;
use WebringHub\ExtensionRequest;
use WebringHub\Verify;

/**
 * Key Witness: records which signing key the hub saw for each listed site, when, and how a
 * change was accepted (signed rotation, operator, or refused). Webring Studio compares this with
 * the key it pinned itself. The answer is a hint: Studio never stores it or lets it replace its
 * own pin.
 *
 * Everything here comes from core events that fire after the hub verified the site, and a
 * site's whole history is deleted when it leaves the hub.
 */
final class KeyWitnessExtension implements Extension
{
    private const HISTORY_LIMIT = 50;
    private const MAX_STORED_CERTIFICATES = 10;

    private ExtensionContext $ctx;
    private string $table = '';

    public function register(ExtensionContext $ctx): void
    {
        $this->ctx = $ctx;
        $this->table = $ctx->table('observations');

        $ctx->migrate(1, function (PDO $pdo, string $driver): void {
            $this->createTable($pdo, $driver);
            $this->backfill($pdo);
        });

        // Audit item 22: byte-exact collation on MySQL `did` so case/accent look-alikes never collide.
        // SQLite is already byte-exact, so this migration is a no-op there.
        $ctx->migrate(2, function (PDO $pdo, string $driver): void {
            if ($driver === 'mysql') {
                $pdo->exec(
                    "ALTER TABLE {$this->table} MODIFY COLUMN did VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL"
                );
            }
        });

        $ctx->on('entry.verified', fn (array $e) => $this->onVerified($e));
        $ctx->on('entry.key_rejected', fn (array $e) => $this->onRejected($e));
        $ctx->on('entry.removed', fn (array $e) => $this->forget((string) ($e['did'] ?? '')));

        $ctx->get('v1/keys/{did}', fn (array $params) => $this->keysFor($params['did']));
        $ctx->get('v1/admin/summary', fn () => $this->adminSummary(), ['admin' => true]);
        $ctx->get('v1/admin/observations', fn (array $params, ExtensionRequest $req) => $this->adminObservations($req), ['admin' => true]);
    }

    /**
     * @param array<string, mixed> $e
     */
    private function onVerified(array $e): void
    {
        $site = is_array($e['site'] ?? null) ? $e['site'] : [];
        $did = (string) ($site['did'] ?? '');
        $siteUrl = (string) ($site['siteUrl'] ?? '');
        $pem = trim((string) ($site['publicKeyPem'] ?? ''));
        $fingerprint = Verify::spkiFingerprint($pem);
        if ($did === '' || $fingerprint === '') {
            return;
        }

        $outcome = null;
        $rotation = null;
        if (!empty($e['inserted'])) {
            $outcome = 'first-seen';
        } elseif (!empty($e['keyRotated'])) {
            $outcome = ($e['keyAcceptedBy'] ?? null) === 'chain' ? 'rotated' : 'operator-accepted';
            if ($outcome === 'rotated' && is_array($site['keyRotations'] ?? null)) {
                $rotation = json_encode(
                    array_slice($site['keyRotations'], 0, self::MAX_STORED_CERTIFICATES),
                    JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ) ?: null;
            }
        }

        if ($outcome === null) {
            // Same key as before: bump the newest accepted observation of it, or record it as
            // first seen if this hub had no history yet (for example, enabled later).
            if ($this->touchAccepted($did, $fingerprint, $siteUrl)) {
                return;
            }
            $outcome = 'first-seen';
        }
        $this->record($did, $siteUrl, $fingerprint, $pem, $outcome, $rotation);
    }

    /**
     * @param array<string, mixed> $e
     */
    private function onRejected(array $e): void
    {
        $site = is_array($e['site'] ?? null) ? $e['site'] : [];
        $did = (string) ($site['did'] ?? '');
        $pem = trim((string) ($site['publicKeyPem'] ?? ''));
        $fingerprint = Verify::spkiFingerprint($pem);
        if ($did === '' || $fingerprint === '') {
            return;
        }
        $this->record($did, (string) ($site['siteUrl'] ?? ''), $fingerprint, $pem, 'rejected', null);
    }

    private function forget(string $did): void
    {
        if ($did === '') {
            return;
        }
        $stmt = $this->ctx->pdo()->prepare("DELETE FROM {$this->table} WHERE did = ?");
        $stmt->execute([$did]);
    }

    /**
     * GET /api/ext/key-witness/v1/keys/{did}
     *
     * @return array<string, mixed>
     */
    private function keysFor(string $did): array
    {
        $row = $this->ctx->entryByDid($did);
        if ($row === null || ($row['status'] ?? '') !== 'active') {
            throw new AppError(404, 'not_found', 'No such entry.');
        }
        $currentFingerprint = Verify::spkiFingerprint((string) $row['public_key_pem']);

        $stmt = $this->ctx->pdo()->prepare(
            "SELECT fingerprint, outcome, first_seen_at, last_seen_at FROM {$this->table}
             WHERE did = ? ORDER BY first_seen_at DESC LIMIT " . self::HISTORY_LIMIT
        );
        $stmt->execute([$did]);
        $rows = array_reverse($stmt->fetchAll());

        $since = '';
        $history = [];
        foreach ($rows as $obs) {
            $history[] = [
                'fingerprint' => (string) $obs['fingerprint'],
                'outcome' => (string) $obs['outcome'],
                'firstSeenAt' => (string) $obs['first_seen_at'],
                'lastSeenAt' => (string) $obs['last_seen_at'],
            ];
            if ($obs['fingerprint'] === $currentFingerprint && $obs['outcome'] !== 'rejected' && $since === '') {
                $since = (string) $obs['first_seen_at'];
            }
        }

        return [
            'did' => (string) $row['did'],
            'siteUrl' => (string) $row['site_url'],
            'current' => [
                'fingerprint' => $currentFingerprint,
                'since' => $since !== '' ? $since : (string) $row['created_at'],
            ],
            'history' => $history,
            'observedAt' => (string) $row['last_checked_at'],
        ];
    }

    /**
     * GET /api/ext/key-witness/v1/admin/summary (admin only)
     *
     * @return array<string, int>
     */
    private function adminSummary(): array
    {
        $pdo = $this->ctx->pdo();
        $cutoff = gmdate('Y-m-d\TH:i:s.000\Z', time() - 30 * 86400);

        $sitesTracked = (int) $pdo->query("SELECT COUNT(DISTINCT did) FROM {$this->table}")->fetchColumn();
        $keysSeen = (int) $pdo->query("SELECT COUNT(DISTINCT fingerprint) FROM {$this->table}")->fetchColumn();
        $rotations = (int) $pdo->query("SELECT COUNT(*) FROM {$this->table} WHERE outcome = 'rotated'")->fetchColumn();
        $operatorAccepted = (int) $pdo->query("SELECT COUNT(*) FROM {$this->table} WHERE outcome = 'operator-accepted'")->fetchColumn();

        $rejStmt = $pdo->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE outcome = 'rejected' AND last_seen_at >= ?"
        );
        $rejStmt->execute([$cutoff]);
        $rejectedLast30d = (int) $rejStmt->fetchColumn();

        return [
            'sitesTracked' => $sitesTracked,
            'keysSeen' => $keysSeen,
            'rotations' => $rotations,
            'operatorAccepted' => $operatorAccepted,
            'rejectedLast30d' => $rejectedLast30d,
        ];
    }

    /**
     * GET /api/ext/key-witness/v1/admin/observations?limit= (admin only)
     *
     * @return array{observations: list<array<string, mixed>>, changedSites: list<array<string, mixed>>}
     */
    private function adminObservations(ExtensionRequest $req): array
    {
        $pdo = $this->ctx->pdo();
        $rawLimit = $req->query['limit'] ?? 50;
        $limit = is_numeric($rawLimit) ? min(max((int) $rawLimit, 1), 200) : 50;

        $stmt = $pdo->prepare(
            "SELECT did, site_url, fingerprint, outcome, first_seen_at, last_seen_at
             FROM {$this->table}
             ORDER BY last_seen_at DESC, id DESC
             LIMIT ?"
        );
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();

        $observations = [];
        foreach ($stmt->fetchAll() as $row) {
            $fp = (string) $row['fingerprint'];
            $short = strlen($fp) > 16 ? substr($fp, 0, 10) . '…' . substr($fp, -6) : $fp;
            $observations[] = [
                'siteUrl' => (string) $row['site_url'],
                'did' => (string) $row['did'],
                'fingerprint' => $fp,
                'fingerprintShort' => $short,
                'outcome' => (string) $row['outcome'],
                'firstSeenAt' => (string) $row['first_seen_at'],
                'lastSeenAt' => (string) $row['last_seen_at'],
            ];
        }

        $changedStmt = $pdo->prepare(
            "SELECT did,
                    MAX(site_url) AS site_url,
                    COUNT(DISTINCT fingerprint) AS distinct_keys,
                    SUM(CASE WHEN outcome = 'rotated' THEN 1 ELSE 0 END) AS rotations,
                    SUM(CASE WHEN outcome = 'operator-accepted' THEN 1 ELSE 0 END) AS operator_accepted,
                    SUM(CASE WHEN outcome = 'rejected' THEN 1 ELSE 0 END) AS rejected,
                    MAX(last_seen_at) AS last_seen_at
             FROM {$this->table}
             GROUP BY did
             HAVING COUNT(DISTINCT fingerprint) > 1
             ORDER BY last_seen_at DESC
             LIMIT ?"
        );
        $changedStmt->bindValue(1, $limit, PDO::PARAM_INT);
        $changedStmt->execute();

        $changedSites = [];
        foreach ($changedStmt->fetchAll() as $row) {
            $changedSites[] = [
                'siteUrl' => (string) $row['site_url'],
                'did' => (string) $row['did'],
                'distinctKeys' => (int) $row['distinct_keys'],
                'rotations' => (int) $row['rotations'],
                'operatorAccepted' => (int) $row['operator_accepted'],
                'rejected' => (int) $row['rejected'],
                'lastSeenAt' => (string) $row['last_seen_at'],
            ];
        }

        return [
            'observations' => $observations,
            'changedSites' => $changedSites,
        ];
    }

    private function touchAccepted(string $did, string $fingerprint, string $siteUrl): bool
    {
        $pdo = $this->ctx->pdo();
        // Checked with a SELECT: MySQL's rowCount() counts only rows whose values changed, so a
        // second refresh within the same second would look like "no history yet".
        $find = $pdo->prepare(
            "SELECT COUNT(*) FROM {$this->table} WHERE did = ? AND fingerprint = ? AND outcome <> 'rejected'"
        );
        $find->execute([$did, $fingerprint]);
        if ((int) $find->fetchColumn() === 0) {
            return false;
        }
        $stmt = $pdo->prepare(
            "UPDATE {$this->table} SET last_seen_at = ?, site_url = ?
             WHERE did = ? AND fingerprint = ? AND outcome <> 'rejected'"
        );
        $stmt->execute([$this->ctx->isoNow(), $siteUrl, $did, $fingerprint]);
        return true;
    }

    private function record(string $did, string $siteUrl, string $fingerprint, string $pem, string $outcome, ?string $rotation): void
    {
        $pdo = $this->ctx->pdo();
        $now = $this->ctx->isoNow();
        $find = $pdo->prepare("SELECT id FROM {$this->table} WHERE did = ? AND fingerprint = ? AND outcome = ?");
        $find->execute([$did, $fingerprint, $outcome]);
        $id = $find->fetchColumn();
        if ($id !== false) {
            $upd = $pdo->prepare("UPDATE {$this->table} SET last_seen_at = ?, site_url = ? WHERE id = ?");
            $upd->execute([$now, $siteUrl, $id]);
            return;
        }
        $ins = $pdo->prepare(
            "INSERT INTO {$this->table} (did, site_url, fingerprint, public_key_pem, outcome, first_seen_at, last_seen_at, rotation_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        $ins->execute([$did, $siteUrl, $fingerprint, $pem, $outcome, $now, $now, $rotation]);
    }

    private function createTable(PDO $pdo, string $driver): void
    {
        if ($driver === 'sqlite') {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS {$this->table} (
                  id INTEGER PRIMARY KEY AUTOINCREMENT,
                  did TEXT NOT NULL,
                  site_url TEXT NOT NULL,
                  fingerprint TEXT NOT NULL,
                  public_key_pem TEXT NOT NULL,
                  outcome TEXT NOT NULL,
                  first_seen_at TEXT NOT NULL,
                  last_seen_at TEXT NOT NULL,
                  rotation_json TEXT,
                  UNIQUE (did, fingerprint, outcome)
                );
                CREATE INDEX IF NOT EXISTS idx_{$this->table}_did ON {$this->table}(did);
            ");
            return;
        }
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS {$this->table} (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              did VARCHAR(255) NOT NULL,
              site_url VARCHAR(500) NOT NULL,
              fingerprint CHAR(64) NOT NULL,
              public_key_pem TEXT NOT NULL,
              outcome VARCHAR(20) NOT NULL,
              first_seen_at VARCHAR(40) NOT NULL,
              last_seen_at VARCHAR(40) NOT NULL,
              rotation_json MEDIUMTEXT DEFAULT NULL,
              PRIMARY KEY (id),
              UNIQUE KEY uq_obs (did, fingerprint, outcome),
              KEY idx_obs_did (did)
            ) ENGINE=InnoDB ROW_FORMAT=DYNAMIC DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /**
     * Seeds history from entries that were listed before the extension was turned on.
     */
    private function backfill(PDO $pdo): void
    {
        $rows = $pdo->query('SELECT did, site_url, public_key_pem, created_at, last_checked_at FROM entries')->fetchAll();
        $ins = $pdo->prepare(
            "INSERT INTO {$this->table} (did, site_url, fingerprint, public_key_pem, outcome, first_seen_at, last_seen_at, rotation_json)
             VALUES (?, ?, ?, ?, 'first-seen', ?, ?, NULL)"
        );
        foreach ($rows as $row) {
            $pem = trim((string) $row['public_key_pem']);
            $fingerprint = Verify::spkiFingerprint($pem);
            if ($fingerprint === '') {
                continue;
            }
            $ins->execute([
                (string) $row['did'],
                (string) $row['site_url'],
                $fingerprint,
                $pem,
                (string) $row['created_at'],
                (string) $row['last_checked_at'],
            ]);
        }
    }
}
