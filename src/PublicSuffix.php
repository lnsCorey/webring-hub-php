<?php
declare(strict_types=1);

namespace WebringHub;

/**
 * Registrable-domain lookup backed by the Public Suffix List (publicsuffix.org), so that
 * `alice.github.io` and `bob.github.io` (or `x.co.uk` and `y.co.uk`) count as different
 * domains for the per-domain listing cap. The list ships in `src/data/public_suffix_list.dat`;
 * refresh it with `php scripts/update-psl.php`.
 */
final class PublicSuffix
{
    public const DATA_FILE = __DIR__ . '/data/public_suffix_list.dat';

    /** @var array{rules: array<string, true>, wildcards: array<string, true>, exceptions: array<string, true>}|null */
    private static ?array $parsed = null;

    /**
     * @return array{rules: array<string, true>, wildcards: array<string, true>, exceptions: array<string, true>}
     */
    private static function load(): array
    {
        if (self::$parsed !== null) {
            return self::$parsed;
        }
        $rules = [];
        $wildcards = [];
        $exceptions = [];
        $raw = is_file(self::DATA_FILE) ? (string) file_get_contents(self::DATA_FILE) : '';
        foreach (preg_split('/\R/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '//')) {
                continue;
            }
            // Rules end at the first whitespace.
            $rule = strtolower((string) preg_split('/\s/', $line)[0]);
            if (preg_match('/[^\x20-\x7E]/', $rule) && function_exists('idn_to_ascii')) {
                $ascii = idn_to_ascii($rule, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
                if (is_string($ascii) && $ascii !== '') {
                    $rule = $ascii;
                }
            }
            if (str_starts_with($rule, '!')) {
                $exceptions[substr($rule, 1)] = true;
            } elseif (str_starts_with($rule, '*.')) {
                $wildcards[substr($rule, 2)] = true;
            } else {
                $rules[$rule] = true;
            }
        }
        self::$parsed = ['rules' => $rules, 'wildcards' => $wildcards, 'exceptions' => $exceptions];
        return self::$parsed;
    }

    /** True when the bundled list loaded with at least one rule. */
    public static function isAvailable(): bool
    {
        return count(self::load()['rules']) > 0;
    }

    /**
     * The public suffix of an ASCII, lowercase hostname (e.g. `co.uk` for `x.co.uk`).
     * Follows the PSL algorithm: exception rules win, then the longest matching rule,
     * and the implicit `*` rule when nothing matches.
     */
    public static function publicSuffix(string $host): string
    {
        $data = self::load();
        $labels = explode('.', $host);
        $count = count($labels);
        for ($i = 0; $i < $count; $i++) {
            $candidate = implode('.', array_slice($labels, $i));
            if (isset($data['exceptions'][$candidate])) {
                // An exception rule means the suffix is the candidate minus its first label.
                return implode('.', array_slice($labels, $i + 1));
            }
            if (isset($data['rules'][$candidate])) {
                return $candidate;
            }
            $parent = implode('.', array_slice($labels, $i + 1));
            if ($parent !== '' && isset($data['wildcards'][$parent])) {
                return $candidate;
            }
        }
        return $labels[$count - 1];
    }

    /**
     * The registrable domain (public suffix plus one label) of `$host`, or the host itself
     * when it is an IP literal, a single label, or is itself a public suffix.
     */
    public static function registrableDomain(string $host): string
    {
        $host = strtolower(trim($host, ".[] \t"));
        if ($host === '' || filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }
        if (!self::isAvailable()) {
            // Without the list, fall back to the last two labels (the pre-phase-5 behaviour).
            $parts = explode('.', $host);
            return count($parts) <= 2 ? $host : implode('.', array_slice($parts, -2));
        }
        $suffix = self::publicSuffix($host);
        if ($suffix === $host || $suffix === '') {
            return $host;
        }
        $prefix = substr($host, 0, -strlen($suffix) - 1);
        $labels = explode('.', $prefix);
        return end($labels) . '.' . $suffix;
    }
}
