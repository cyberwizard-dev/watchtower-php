<?php

declare(strict_types=1);

namespace WatchTower\Transport;

use WatchTower\Config;
use WatchTower\Support\Guard;
use WatchTower\WatchTower;

/**
 * Delivers record batches to `POST {base_url}/api/ingest/v1`.
 *
 * Contract: gzip-compressed `{"records":[...]}` with a Bearer environment token.
 * 2xx = accepted; 401 = stop sending for 10 minutes; 413 = split the batch in
 * half and retry; anything else (or no response) = spool to disk. Every
 * successful send also drains a few spooled batches. Nothing here throws and
 * no call blocks longer than the configured total timeout.
 */
final class Ingest
{
    public const AUTH_BACKOFF_SECONDS = 600;

    private const OK = 'ok';

    private const AUTH = 'auth';

    private const FAILED = 'failed';

    public function __construct(
        private readonly Config $config,
        private readonly HttpClient $http,
        private readonly Spool $spool,
    ) {}

    public static function defaultHttpClient(): HttpClient
    {
        return function_exists('curl_init') ? new CurlHttpClient : new StreamHttpClient;
    }

    public function spool(): Spool
    {
        return $this->spool;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    public function deliver(array $records): void
    {
        Guard::safely(function () use ($records): void {
            if ($records === []) {
                return;
            }

            $batches = array_chunk($records, $this->config->maxRecords);

            if ($this->config->mode === 'spool' || ! $this->config->canSend() || $this->isBackedOff()) {
                foreach ($batches as $batch) {
                    $this->spool->push($this->encode($batch));
                }

                return;
            }

            $deadline = microtime(true) + $this->config->timeout;
            $delivered = false;
            $stop = false;

            foreach ($batches as $batch) {
                $payload = $this->encode($batch);
                $result = $stop ? self::FAILED : $this->send($payload, $deadline);

                if ($result === self::OK) {
                    $delivered = true;

                    continue;
                }

                $stop = true;
                $this->spool->push($payload);
            }

            if ($delivered && ! $stop) {
                $this->drain($this->config->spoolDrainBatches, $deadline);
            }
        });
    }

    /**
     * Send spooled batches until $limit is reached, the deadline passes or a send fails.
     *
     * @return int Batches delivered.
     */
    public function drain(int $limit, ?float $deadline = null, bool $ignoreBackoff = false): int
    {
        return Guard::safely(function () use ($limit, $deadline, $ignoreBackoff): int {
            if (! $this->config->canSend() || (! $ignoreBackoff && $this->isBackedOff())) {
                return 0;
            }

            $sent = 0;

            foreach ($this->spool->claim($limit) as $claimed) {
                if ($deadline !== null && microtime(true) >= $deadline) {
                    $this->spool->release($claimed);

                    continue;
                }

                $ok = true;

                foreach ($this->spool->read($claimed) as $payload) {
                    if ($ok && $this->send($payload, $deadline ?? microtime(true) + $this->config->timeout) !== self::OK) {
                        $ok = false;
                    }
                }

                if ($ok) {
                    $this->spool->remove($claimed);
                    $sent++;
                } else {
                    $this->spool->release($claimed);
                    $deadline = 0.0;
                }
            }

            return $sent;
        }, 0);
    }

    public function isBackedOff(): bool
    {
        $file = $this->backoffFile();

        return is_file($file) && (float) @file_get_contents($file) > microtime(true);
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    public function encode(array $records): string
    {
        $json = json_encode(['records' => $records], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR);

        return is_string($json) ? $json : '{"records":[]}';
    }

    /**
     * @return array<string, string>
     */
    public function headers(bool $gzip): array
    {
        $headers = [
            'Authorization' => 'Bearer '.$this->config->token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'User-Agent' => 'watchtower-php/'.WatchTower::VERSION,
        ];

        if ($gzip) {
            $headers['Content-Encoding'] = 'gzip';
        }

        return $headers;
    }

    private function send(string $payload, float $deadline): string
    {
        $remaining = $deadline - microtime(true);

        if ($remaining < 0.05) {
            return self::FAILED;
        }

        $gzip = function_exists('gzencode');
        $body = $gzip ? gzencode($payload, 6) : $payload;

        if ($body === false) {
            [$gzip, $body] = [false, $payload];
        }

        $timeout = min($this->config->timeout, $remaining);
        $status = $this->http->post($this->config->ingestUrl(), $this->headers($gzip), $body, min($this->config->connectTimeout, $timeout), $timeout);

        if ($status >= 200 && $status < 300) {
            return self::OK;
        }

        if ($status === 401) {
            $this->backOff();

            return self::AUTH;
        }

        if ($status === 413) {
            return $this->split($payload, $deadline);
        }

        return self::FAILED;
    }

    /** 413: halve the batch and send both parts; a single oversized record is dropped. */
    private function split(string $payload, float $deadline): string
    {
        $records = json_decode($payload, true)['records'] ?? null;

        if (! is_array($records) || count($records) <= 1) {
            return self::OK;
        }

        $half = intdiv(count($records), 2);

        foreach ([array_slice($records, 0, $half), array_slice($records, $half)] as $part) {
            $encoded = $this->encode(array_values($part));

            // A half that cannot be delivered is spooled on its own, so the
            // half that was accepted is never sent twice.
            if ($this->send($encoded, $deadline) !== self::OK) {
                $this->spool->push($encoded);
            }
        }

        return self::OK;
    }

    private function backOff(): void
    {
        if ($this->spool->ensureDirectory()) {
            @file_put_contents($this->backoffFile(), (string) (microtime(true) + self::AUTH_BACKOFF_SECONDS), LOCK_EX);
        }
    }

    private function backoffFile(): string
    {
        return $this->spool->directory().DIRECTORY_SEPARATOR.'auth-backoff-'.$this->config->tokenId();
    }
}
