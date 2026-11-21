<?php
declare(strict_types=1);

/**
 * Smoke tests for phase 9 security hardening in webring-hub-php (no network, temporary SQLite):
 * - A block outlives the listing: self-removal or admin delete keeps it; unblock lifts it (H-1)
 * - Deleting a listing also deletes its candidate key (H-6)
 * - Failed migrations are retried once after the code version changes, not on every run (H-2)
 *
 * These run at the function level. POST /api/entries refuses a site when Database::isBlocked
 * is true (public/index.php), so the isBlocked checks here cover the re-submission.
 *
 * Run with: php scripts/smoke-security-phase9.php
 */

error_reporting(E_ALL);
set_error_handler(static function (int $no, string $str, string $file, int $line): bool {
    throw new ErrorException($str, 0, $no, $file, $line);
});

require_once __DIR__ . '/../src/AppError.php';
require_once __DIR__ . '/../src/Config.php';
require_once __DIR__ . '/../src/Verify.php';
require_once __DIR__ . '/../src/Net.php';
require_once __DIR__ . '/../src/Site.php';
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Extensions.php';
require_once __DIR__ . '/../src/Entries.php';

use WebringHub\Database;
use WebringHub\Entries;
use WebringHub\Extensions;
use WebringHub\Verify;

$failures = 0;

function check(bool $condition, string $label): void
{
    global $failures;
    if ($condition) {
        echo "  ok   {$label}\n";
    } else {
        echo "  FAIL {$label}\n";
        $failures++;
    }
}

function freshPdo(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    Database::migrate($pdo);
    return $pdo;
}

/** @return array<string, mixed> a verified-site array as Site::verifySite returns it */
function fakeSite(string $siteUrl, string $publicKeyPem): array
{
    return [
        'siteUrl' => $siteUrl,
        'did' => Verify::siteUrlToDidWeb($siteUrl),
        'manifest' => [],
        'profile' => ['displayName' => 'Spammer', 'updatedAt' => '2026-11-21T00:00:00.000Z'],
        'publicKeyPem' => $publicKeyPem,
        'keyRotations' => [],
        'fetchedAt' => '2026-11-21T00:00:00.000Z',
    ];
}

$ext = new Extensions(__DIR__ . '/../extensions');
$siteUrl = 'https://spam.example';
$did = Verify::siteUrlToDidWeb($siteUrl);
$key = Verify::generateKeyPair();

echo "H-1: blocks outlive the listing\n";
$pdo = freshPdo();
Database::upsertEntry($pdo, fakeSite($siteUrl, $key['publicKeyPem']));
check(!Database::isBlocked($pdo, $did, $siteUrl), 'a new listing is not blocked');
Entries::setStatus($pdo, $ext, $did, 'blocked');
check(Database::isBlocked($pdo, $did, $siteUrl), 'blocking adds the site to the block list');
// The owner's signed self-removal ends in Entries::remove, as does the admin delete
Entries::remove($pdo, $ext, $did, 'self');
check(Database::getEntryByDid($pdo, $did) === null, 'self-removal deletes the listing');
check(Database::isBlocked($pdo, $did, $siteUrl), 'the block survives the self-removal (re-submitting is refused)');
check(Database::isBlocked($pdo, 'did:web:other', $siteUrl), 'the block also matches by site URL');
$orphans = Database::blockedSitesWithoutListing($pdo);
check(count($orphans) === 1 && $orphans[0]['did'] === $did && $orphans[0]['siteUrl'] === $siteUrl, 'the admin page lists the blocked site with no listing');
check(Database::unblockSite($pdo, $did), 'an operator can unblock it');
check(!Database::isBlocked($pdo, $did, $siteUrl), 'after unblocking, the site can be listed again');
check(!Database::unblockSite($pdo, $did), 'unblocking twice reports there was nothing to lift');

echo "H-1: unblocking a listed site through its status\n";
Database::upsertEntry($pdo, fakeSite($siteUrl, $key['publicKeyPem']));
Entries::setStatus($pdo, $ext, $did, 'blocked');
Entries::setStatus($pdo, $ext, $did, 'active');
check(!Database::isBlocked($pdo, $did, $siteUrl), 'setting a blocked entry active lifts the block');
Entries::setStatus($pdo, $ext, $did, 'blocked');
Entries::remove($pdo, $ext, $did, 'admin');
check(Database::isBlocked($pdo, $did, $siteUrl), 'an admin delete of a blocked listing keeps the block');

echo "H-1: the upgrade backfills existing blocks\n";
$pdo = freshPdo();
Database::upsertEntry($pdo, fakeSite($siteUrl, $key['publicKeyPem']));
$pdo->prepare("UPDATE entries SET status = 'blocked' WHERE did = ?")->execute([$did]);
$pdo->exec('DROP TABLE blocked_sites');
$pdo->prepare('DELETE FROM schema_migrations WHERE id = ?')->execute(['2026_11_blocked_sites']);
Database::runMigrations($pdo);
check(Database::isBlocked($pdo, $did, $siteUrl), 'sites blocked before the upgrade are on the block list');

echo "H-6: candidate keys go with the listing\n";
$pdo = freshPdo();
Database::upsertEntry($pdo, fakeSite($siteUrl, $key['publicKeyPem']));
$other = Verify::generateKeyPair();
$pdo->prepare('INSERT INTO key_candidates (did, public_key_pem, fingerprint, first_seen_at, last_seen_at, conflict) VALUES (?, ?, ?, ?, ?, 0)')
    ->execute([$did, $other['publicKeyPem'], Verify::spkiFingerprint($other['publicKeyPem']), '2026-11-21T00:00:00.000Z', '2026-11-21T00:00:00.000Z']);
Entries::remove($pdo, $ext, $did, 'self');
$left = $pdo->prepare('SELECT COUNT(*) FROM key_candidates WHERE did = ?');
$left->execute([$did]);
check((int) $left->fetchColumn() === 0, 'deleting a listing deletes its candidate key');

echo "H-2: failed upgrades are retried after new code is deployed\n";
$simulateFailure = static function (PDO $pdo, string $codeVersion): void {
    // As if 2026_11_blocked_sites had failed under older code
    $pdo->exec('DROP TABLE IF EXISTS blocked_sites');
    $pdo->prepare('DELETE FROM schema_migrations WHERE id = ?')->execute(['2026_11_blocked_sites']);
    $pdo->prepare('DELETE FROM settings WHERE `key` IN (?, ?)')->execute(['migration_error:2026_11_blocked_sites', 'migrations:code_version']);
    $ins = $pdo->prepare('INSERT INTO settings (`key`, `value`, updated_at) VALUES (?, ?, ?)');
    $ins->execute(['migration_error:2026_11_blocked_sites', 'old failure', '2026-11-20T00:00:00.000Z']);
    $ins->execute(['migrations:code_version', $codeVersion, '2026-11-20T00:00:00.000Z']);
};
$tableExists = static fn (PDO $pdo): bool => $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'blocked_sites'")->fetchColumn() !== false;

$pdo = freshPdo();
$simulateFailure($pdo, Database::MIGRATION_CODE_VERSION);
Database::runMigrations($pdo);
check(!$tableExists($pdo), 'with the same code version, a recorded failure is not retried on every run');

$simulateFailure($pdo, '2026-11-19');
Database::runMigrations($pdo);
check($tableExists($pdo), 'after a code upgrade, the failed migration is retried and applied');
$err = $pdo->prepare('SELECT COUNT(*) FROM settings WHERE `key` = ?');
$err->execute(['migration_error:2026_11_blocked_sites']);
check((int) $err->fetchColumn() === 0, 'the retried migration clears its recorded error');
$ver = $pdo->prepare('SELECT `value` FROM settings WHERE `key` = ?');
$ver->execute(['migrations:code_version']);
check($ver->fetchColumn() === Database::MIGRATION_CODE_VERSION, 'the current code version is recorded');

echo "Admin page: every POST sends a JSON Content-Type\n";
// The hub answers 415 to a POST without one, and the Refresh now button sends no body. The
// script's api() helper must therefore send an empty JSON object for a bodiless POST.
$adminJs = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../public/admin.js'));
check(
    str_contains($adminJs, "method === 'POST' ? {} : undefined")
        && str_contains($adminJs, "if (payload !== undefined) headers['content-type'] = 'application/json';"),
    'admin.js api() sends {} as JSON for a POST with no body (Refresh now returned 415 before)'
);

echo "Static scripts and styles are revalidated, not cached for a week\n";
// The host adds a 7-day expiry to files Apache serves directly, so an old admin.js stayed in browsers.
// php -S doesn't read .htaccess, so this checks the rule is present; verify it live with curl -I.
$htaccess = str_replace("\r\n", "\n", (string) file_get_contents(__DIR__ . '/../public/.htaccess'));
check(
    preg_match('/<FilesMatch "\\\\\\.\\(js\\|css\\)\\$">\s*Header always set Cache-Control "no-cache"\s*Header always unset Expires\s*<\\/FilesMatch>/', $htaccess) === 1,
    'public/.htaccess sets Cache-Control: no-cache and drops Expires for .js and .css'
);

echo $failures === 0 ? "all phase 9 checks passed\n" : "{$failures} phase 9 check(s) FAILED\n";
exit($failures === 0 ? 0 : 1);
