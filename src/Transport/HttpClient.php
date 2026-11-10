<?php

declare(strict_types=1);

namespace WatchTower\Transport;

/**
 * Minimal HTTP POST used to deliver batches. Implementations never throw.
 */
interface HttpClient
{
    /**
     * @param  array<string, string>  $headers
     * @return int The HTTP status code, or 0 when no response was received.
     */
    public function post(string $url, array $headers, string $body, float $connectTimeout, float $timeout): int;
}
