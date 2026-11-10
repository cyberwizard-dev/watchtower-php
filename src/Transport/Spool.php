<?php

declare(strict_types=1);

namespace WatchTower\Transport;

/**
 * On-disk fallback queue. Every undeliverable batch is written as one JSON
 * line (`{"records":[...]}`) to its own file, so concurrent PHP workers never
 * contend for a lock; batches are claimed by an atomic rename. The directory
 * is size-capped: when it is full the oldest batches are dropped.
 */
final class Spool
{
    private const PREFIX = 'batch-';

    private const SUFFIX = '.jsonl';

    private const CLAIMED = '.sending';

    /** A claim older than this is considered abandoned (worker died mid-send). */
    private const STALE_CLAIM_SECONDS = 60;

    public function __construct(
        private readonly string $directory,
        private readonly int $maxBytes,
    ) {}

    public function directory(): string
    {
        return $this->directory;
    }

    public function ensureDirectory(): bool
    {
        return is_dir($this->directory) || @mkdir($this->directory, 0775, true) || is_dir($this->directory);
    }

    /** Store one batch payload (a JSON document without newlines). */
    public function push(string $payload): bool
    {
        if (! $this->ensureDirectory()) {
            return false;
        }

        $payload = str_replace(["\r", "\n"], '', $payload)."\n";
        $this->makeRoom(strlen($payload));

        $file = $this->directory.DIRECTORY_SEPARATOR.self::PREFIX.sprintf('%.6F', microtime(true)).'-'.bin2hex(random_bytes(4)).self::SUFFIX;

        return @file_put_contents($file, $payload, LOCK_EX) !== false;
    }

    /**
     * Claim up to $limit batches, oldest first.
     *
     * @return list<string> Paths of the claimed files.
     */
    public function claim(int $limit): array
    {
        if ($limit <= 0 || ! is_dir($this->directory)) {
            return [];
        }

        $this->releaseStaleClaims();
        $claimed = [];

        foreach ($this->files() as $file) {
            if (count($claimed) >= $limit) {
                break;
            }

            $target = $file.self::CLAIMED;

            if (@rename($file, $target)) {
                @touch($target);
                $claimed[] = $target;
            }
        }

        return $claimed;
    }

    /**
     * Every line of a claimed file (normally exactly one batch).
     *
     * @return list<string>
     */
    public function read(string $claimed): array
    {
        $lines = @file($claimed, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        return is_array($lines) ? array_values($lines) : [];
    }

    public function remove(string $claimed): void
    {
        @unlink($claimed);
    }

    /** Return a claimed batch to the queue after a failed delivery. */
    public function release(string $claimed): void
    {
        if (str_ends_with($claimed, self::CLAIMED)) {
            @rename($claimed, substr($claimed, 0, -strlen(self::CLAIMED)));
        }
    }

    /** Number of queued (unclaimed) batches. */
    public function count(): int
    {
        return count($this->files());
    }

    public function size(): int
    {
        $total = 0;

        foreach ($this->files() as $file) {
            $total += (int) @filesize($file);
        }

        return $total;
    }

    /**
     * Queued batch files, oldest first.
     *
     * @return list<string>
     */
    private function files(): array
    {
        $files = glob($this->directory.DIRECTORY_SEPARATOR.self::PREFIX.'*'.self::SUFFIX) ?: [];
        sort($files, SORT_STRING);

        return array_values($files);
    }

    private function makeRoom(int $incoming): void
    {
        $files = $this->files();
        $sizes = [];
        $total = $incoming;

        foreach ($files as $file) {
            $sizes[$file] = (int) @filesize($file);
            $total += $sizes[$file];
        }

        foreach ($sizes as $file => $size) {
            if ($total <= $this->maxBytes) {
                break;
            }

            @unlink($file);
            $total -= $size;
        }
    }

    private function releaseStaleClaims(): void
    {
        foreach (glob($this->directory.DIRECTORY_SEPARATOR.self::PREFIX.'*'.self::SUFFIX.self::CLAIMED) ?: [] as $file) {
            $modified = @filemtime($file);

            if ($modified !== false && $modified < time() - self::STALE_CLAIM_SECONDS) {
                $this->release($file);
            }
        }
    }
}
