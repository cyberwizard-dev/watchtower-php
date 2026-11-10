<?php

declare(strict_types=1);

namespace WatchTower\Shield;

/**
 * One small JSON file per key, read and written under an exclusive flock(), so concurrent
 * workers never lose an increment.
 */
final class FileCounter implements Counter
{
    /** Counter files untouched for this long are removed now and then. */
    private const GC_AFTER_SECONDS = 86400;

    public function __construct(private readonly string $directory) {}

    public function attempt(string $key, int $limit, int $windowSeconds, int $blockSeconds, int $now): ?int
    {
        $retryAfter = null;

        $updated = $this->locked($key, function (array $state) use ($limit, $windowSeconds, $blockSeconds, $now, &$retryAfter): array {
            $blockedUntil = (int) ($state['blocked_until'] ?? 0);

            if ($blockedUntil > $now) {
                $retryAfter = $blockedUntil - $now;

                return $state;
            }

            $start = (int) ($state['start'] ?? 0);
            $count = (int) ($state['count'] ?? 0);

            if ($start + $windowSeconds <= $now) {
                $start = $now;
                $count = 0;
            }

            $count++;
            $blockedUntil = 0;

            if ($count > $limit) {
                if ($blockSeconds > 0) {
                    $blockedUntil = $now + $blockSeconds;
                    $retryAfter = $blockSeconds;
                } else {
                    $retryAfter = max(1, $start + $windowSeconds - $now);
                }
            }

            return ['start' => $start, 'count' => $count, 'blocked_until' => $blockedUntil];
        });

        // Counting is best effort: when the file can't be used the request is not limited.
        return $updated ? $retryAfter : null;
    }

    /** Hits counted in the current window (tests and debugging). */
    public function count(string $key): int
    {
        $count = 0;

        $this->locked($key, static function (array $state) use (&$count): array {
            $count = (int) ($state['count'] ?? 0);

            return $state;
        });

        return $count;
    }

    /**
     * @param  callable(array<string, int>): array<string, int>  $update
     */
    private function locked(string $key, callable $update): bool
    {
        if (! is_dir($this->directory) && ! @mkdir($this->directory, 0775, true) && ! is_dir($this->directory)) {
            return false;
        }

        $handle = @fopen($this->directory.DIRECTORY_SEPARATOR.sha1($key).'.json', 'c+');

        if ($handle === false) {
            return false;
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                return false;
            }

            $raw = stream_get_contents($handle);
            $state = is_string($raw) && $raw !== '' ? json_decode($raw, true) : [];
            $next = $update(is_array($state) ? $state : []);

            if ($next !== $state) {
                ftruncate($handle, 0);
                rewind($handle);
                fwrite($handle, (string) json_encode($next));
                fflush($handle);
            }

            flock($handle, LOCK_UN);

            return true;
        } finally {
            fclose($handle);

            if (mt_rand(1, 500) === 1) {
                $this->collectGarbage();
            }
        }
    }

    private function collectGarbage(): void
    {
        $cutoff = time() - self::GC_AFTER_SECONDS;

        foreach (glob($this->directory.DIRECTORY_SEPARATOR.'*.json') ?: [] as $file) {
            if ((@filemtime($file) ?: PHP_INT_MAX) < $cutoff) {
                @unlink($file);
            }
        }
    }
}
