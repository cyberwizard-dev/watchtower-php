<?php

declare(strict_types=1);

namespace WatchTower\Shield;

/**
 * The small HTTP client Shield (rules pull, Turnstile siteverify) and the browser proxy (script
 * fetch, beacon forwarding) use. It bypasses the traced curl and Guzzle wrappers, so the SDK's
 * own calls are never recorded as outgoing requests. Implementations never throw.
 */
interface ShieldHttp
{
    /**
     * @param  'GET'|'POST'  $method
     * @param  array<string, string>  $headers
     * @return array{status: int, body: string} status 0 when no response was received
     */
    public function request(string $method, string $url, array $headers, ?string $body, float $connectTimeout, float $timeout): array;
}
