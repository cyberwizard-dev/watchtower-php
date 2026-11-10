<?php

declare(strict_types=1);

namespace WatchTower\Shield;

/**
 * Counters in APCu (shared by the workers of one PHP-FPM pool on one server).
 */
final class ApcuCounter implements Counter
{
    private const PREFIX = 'watchtower-shield:';

    public static function available(): bool
    {
        return function_exists('apcu_enabled') && function_exists('apcu_inc') && apcu_enabled();
    }

    public function attempt(string $key, int $limit, int $windowSeconds, int $blockSeconds, int $now): ?int
    {
        $key = self::PREFIX.sha1($key);
        $blockedUntil = apcu_fetch($key.':blocked');

        if (is_int($blockedUntil) && $blockedUntil > $now) {
            return $blockedUntil - $now;
        }

        apcu_add($key.':start', $now, $windowSeconds);
        apcu_add($key, 0, $windowSeconds);
        $count = apcu_inc($key);
        $start = apcu_fetch($key.':start');

        if (! is_int($count) || $count <= $limit) {
            return null;
        }

        if ($blockSeconds > 0) {
            apcu_store($key.':blocked', $now + $blockSeconds, $blockSeconds);

            return $blockSeconds;
        }

        return max(1, (is_int($start) ? $start : $now) + $windowSeconds - $now);
    }
}
