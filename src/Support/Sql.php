<?php

declare(strict_types=1);

namespace WatchTower\Support;

/**
 * SQL helpers: literal stripping (so values never leave the host) and the
 * Nightwatch query group hash.
 */
final class Sql
{
    /**
     * Replace quoted string literals exactly like Watch Tower's server-side
     * Redactor::sql() does ('...' becomes '?'), and optionally bare numeric
     * literals (42 becomes ?), so raw queries group like prepared ones.
     */
    public static function stripLiterals(string $sql, bool $numbers = true): string
    {
        $sql = (string) preg_replace("/'(?:[^'\\\\]|\\\\.|'')*'/", "'?'", $sql);

        if ($numbers) {
            $sql = (string) preg_replace('/(?<![\w$.:`"\]\[?])-?\b\d+(?:\.\d+)?\b(?![\w`"])/', '?', $sql);
        }

        return $sql;
    }

    /**
     * Nightwatch QuerySensor::hash(): xxh128("{connection},{sql}") with
     * `in (?, ?)` and multi-row `values (...)` collapsed.
     */
    public static function group(string $connection, string $sql): string
    {
        $normalized = preg_replace('/in \([\d?\s,]+\)/', 'in (...?)', $sql) ?? $sql;

        if (str_contains($normalized, 'insert')) {
            $normalized = preg_replace('/values [(?,\s)]+/', 'values ...', $normalized) ?? $normalized;
        }

        return hash('xxh128', "{$connection},{$normalized}");
    }
}
