<?php

declare(strict_types=1);

namespace WatchTower\Shield;

use WatchTower\Config;

/**
 * Shield rules cached in a file (atomic write: temp file + rename). The last good copy is used
 * past its ttl when Watch Tower can't be reached; with no copy at all there are no rules
 * (fail open). Refreshes happen after the response, never while a request waits.
 */
final class RulesStore
{
    /** Seconds between two refresh attempts from requests, across workers. */
    public const REFRESH_EVERY_SECONDS = 15;

    /** @var array{rules: array<string, mixed>, fetched_at: int}|false|null */
    private array|false|null $entry = null;

    /**
     * @param  \Closure(): int  $clock
     */
    public function __construct(
        private readonly Config $config,
        private readonly ShieldHttp $http,
        private readonly \Closure $clock,
    ) {}

    public function path(): string
    {
        return $this->config->shield['cache_dir'].DIRECTORY_SEPARATOR.'shield-rules-'.$this->config->tokenId().'.json';
    }

    public function current(): ?Rules
    {
        $entry = $this->entry();

        return $entry === null ? null : Rules::fromArray($entry['rules']);
    }

    /** No copy, or a copy older than its ttl. */
    public function stale(): bool
    {
        $entry = $this->entry();

        if ($entry === null) {
            return true;
        }

        return $entry['fetched_at'] + max(5, (int) ($entry['rules']['ttl_seconds'] ?? 60)) <= ($this->clock)();
    }

    /**
     * Pull the rules now: "updated", "not_modified", "failed" or "unconfigured".
     */
    public function refresh(): string
    {
        if (! $this->config->canSend()) {
            return 'unconfigured';
        }

        $entry = $this->entry();
        $headers = ['Authorization' => 'Bearer '.$this->config->token, 'Accept' => 'application/json', 'User-Agent' => 'watchtower-php'];
        $version = (string) ($entry['rules']['version'] ?? '');

        if ($entry !== null && $version !== '') {
            $headers['If-None-Match'] = '"'.$version.'"';
        }

        $response = $this->http->request('GET', $this->config->shieldUrl(), $headers, null, $this->config->connectTimeout, $this->config->timeout);

        if ($response['status'] === 304 && $entry !== null) {
            $this->write($entry['rules']);

            return 'not_modified';
        }

        $data = $response['status'] >= 200 && $response['status'] < 300 ? json_decode($response['body'], true) : null;

        if (! is_array($data)) {
            return 'failed';
        }

        $this->write(Rules::fromArray($data)->toArray());

        return 'updated';
    }

    /**
     * Refresh when the copy is stale, at most once per REFRESH_EVERY_SECONDS across workers
     * (a non-blocking lock plus the lock file's mtime).
     */
    public function refreshIfStale(): void
    {
        if (! $this->stale() || ! $this->config->canSend() || ! $this->ensureDirectory()) {
            return;
        }

        $lock = $this->path().'.lock';
        $handle = @fopen($lock, 'c');

        if ($handle === false) {
            return;
        }

        try {
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                return;
            }

            clearstatcache(true, $lock);
            $last = (int) @filemtime($lock);
            $now = ($this->clock)();

            if (@filesize($lock) > 0 && $last > $now - self::REFRESH_EVERY_SECONDS) {
                return;
            }

            ftruncate($handle, 0);
            fwrite($handle, (string) $now);
            fflush($handle);
            @touch($lock, $now);

            $this->refresh();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    /**
     * @param  array<string, mixed>  $rules
     */
    private function write(array $rules): void
    {
        if (! $this->ensureDirectory()) {
            return;
        }

        $entry = ['rules' => $rules, 'fetched_at' => ($this->clock)()];
        $temp = $this->path().'.'.bin2hex(random_bytes(4)).'.tmp';

        if (@file_put_contents($temp, (string) json_encode($entry, JSON_UNESCAPED_SLASHES)) === false) {
            return;
        }

        if (@rename($temp, $this->path())) {
            $this->entry = $entry;
        } else {
            @unlink($temp);
        }
    }

    /**
     * @return array{rules: array<string, mixed>, fetched_at: int}|null
     */
    private function entry(): ?array
    {
        if ($this->entry === null) {
            $raw = is_file($this->path()) ? @file_get_contents($this->path()) : false;
            $entry = is_string($raw) ? json_decode($raw, true) : null;

            $this->entry = is_array($entry) && is_array($entry['rules'] ?? null) && is_int($entry['fetched_at'] ?? null) ? $entry : false;
        }

        return $this->entry === false ? null : $this->entry;
    }

    private function ensureDirectory(): bool
    {
        $directory = $this->config->shield['cache_dir'];

        return is_dir($directory) || @mkdir($directory, 0775, true) || is_dir($directory);
    }
}
