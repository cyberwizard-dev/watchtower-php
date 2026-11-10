<?php

declare(strict_types=1);

namespace WatchTower\Shield;

/**
 * Rate limit counters: a fixed window that starts with the first hit, plus an optional penalty
 * once the limit is exceeded. Shared between PHP workers (APCu or flock'ed files).
 */
interface Counter
{
    /**
     * Count one request. Returns the seconds until the client may retry when it is over the
     * limit (or inside a penalty, which is not counted), otherwise null.
     */
    public function attempt(string $key, int $limit, int $windowSeconds, int $blockSeconds, int $now): ?int;
}
