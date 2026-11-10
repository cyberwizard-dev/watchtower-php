<?php

declare(strict_types=1);

namespace WatchTower\Shield;

/**
 * IP ranges, user agent expressions and path globs as Shield matches them. Nothing here throws
 * or emits warnings: an invalid CIDR or regular expression simply never matches.
 */
final class Patterns
{
    /** User agents longer than this are cut before matching, which bounds regex work. */
    public const MAX_SUBJECT_LENGTH = 1024;

    private const DELIMITERS = ['~', '#', '%', '!', '@', '`', ';', '|'];

    /**
     * IPv4 or IPv6 address inside a CIDR (or equal to a bare address). IPv4-mapped IPv6
     * addresses (::ffff:203.0.113.9) match IPv4 ranges.
     */
    public static function ipInRange(string $ip, string $cidr): bool
    {
        if (str_starts_with(strtolower($ip), '::ffff:') && filter_var(substr($ip, 7), FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            $ip = substr($ip, 7);
        }

        $address = @inet_pton($ip);
        [$network, $bits] = array_pad(explode('/', trim($cidr), 2), 2, null);
        $range = filter_var($network, FILTER_VALIDATE_IP) !== false ? @inet_pton((string) $network) : false;

        if ($address === false || $range === false || strlen($address) !== strlen($range)) {
            return false;
        }

        $max = strlen($range) * 8;

        if ($bits === null) {
            $bits = $max;
        } elseif (! ctype_digit($bits) || (int) $bits > $max) {
            return false;
        } else {
            $bits = (int) $bits;
        }

        $bytes = intdiv($bits, 8);

        if (substr($address, 0, $bytes) !== substr($range, 0, $bytes)) {
            return false;
        }

        $remainder = $bits % 8;

        if ($remainder === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remainder)) & 0xFF;

        return (ord($address[$bytes]) & $mask) === (ord($range[$bytes]) & $mask);
    }

    /**
     * A PCRE written without delimiters ("(?i)sqlmap|nikto"). Invalid expressions and
     * expressions that hit the backtracking limit do not match.
     */
    public static function regexMatches(string $pattern, string $subject): bool
    {
        $delimiter = null;

        foreach (self::DELIMITERS as $candidate) {
            if (! str_contains($pattern, $candidate)) {
                $delimiter = $candidate;

                break;
            }
        }

        if ($delimiter === null) {
            return false;
        }

        $previous = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '100000');

        try {
            $result = @preg_match($delimiter.$pattern.$delimiter, substr($subject, 0, self::MAX_SUBJECT_LENGTH));
        } finally {
            if ($previous !== false) {
                ini_set('pcre.backtrack_limit', $previous);
            }
        }

        return $result === 1;
    }

    /**
     * Path glob: "*" matches anything (slashes included), "?" one character. Case-insensitive,
     * so "/WP-LOGIN.PHP" can't slip past "/wp-login.php".
     */
    public static function globMatches(string $glob, string $path): bool
    {
        $regex = '';
        $length = strlen($glob);

        for ($i = 0; $i < $length; $i++) {
            $regex .= match ($glob[$i]) {
                '*' => '.*',
                '?' => '.',
                default => preg_quote($glob[$i], '~'),
            };
        }

        return preg_match('~^'.$regex.'$~is', $path) === 1;
    }

    /**
     * The request path as globs see it: percent-decoded, duplicate slashes collapsed.
     */
    public static function normalizePath(string $path): string
    {
        $path = '/'.ltrim(rawurldecode($path), '/');

        return (string) preg_replace('~/{2,}~', '/', $path);
    }
}
