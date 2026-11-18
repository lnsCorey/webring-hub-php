#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Refreshes src/data/public_suffix_list.dat from publicsuffix.org. The hub uses it to work out
 * registrable domains for the per-domain listing cap. Run it occasionally (for example monthly)
 * and commit the result. The file is replaced atomically, and only after basic sanity checks.
 *
 * Run with: php scripts/update-psl.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "Forbidden: CLI execution only.\n";
    exit(1);
}

const PSL_URL = 'https://publicsuffix.org/list/public_suffix_list.dat';
$target = __DIR__ . '/../src/data/public_suffix_list.dat';

$ch = curl_init(PSL_URL);
if ($ch === false) {
    fwrite(STDERR, "cURL is required.\n");
    exit(1);
}
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_FOLLOWLOCATION => false,
    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    CURLOPT_TIMEOUT => 60,
    CURLOPT_USERAGENT => 'webring-hub-php psl-updater',
]);
$body = curl_exec($ch);
$status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if (!is_string($body) || $status !== 200) {
    fwrite(STDERR, "Download failed (HTTP {$status}) {$error}\n");
    exit(1);
}
if (strlen($body) < 100000 || !str_contains($body, '// ===BEGIN ICANN DOMAINS===') || !str_contains($body, '// ===BEGIN PRIVATE DOMAINS===')) {
    fwrite(STDERR, "The downloaded file does not look like the Public Suffix List; keeping the current copy.\n");
    exit(1);
}

$tmp = $target . '.tmp';
if (file_put_contents($tmp, $body) === false || !rename($tmp, $target)) {
    @unlink($tmp);
    fwrite(STDERR, "Could not write {$target}\n");
    exit(1);
}
echo 'Updated ' . realpath($target) . ' (' . strlen($body) . " bytes)\n";
