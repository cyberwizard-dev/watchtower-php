<?php

declare(strict_types=1);

namespace WatchTower\Support;

/**
 * Runs SDK code so that nothing it does can ever throw into the host application.
 */
final class Guard
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @param  T  $fallback
     * @return T
     */
    public static function safely(callable $callback, mixed $fallback = null): mixed
    {
        try {
            return $callback();
        } catch (\Throwable) {
            return $fallback;
        }
    }
}
