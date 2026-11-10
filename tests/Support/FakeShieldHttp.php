<?php

declare(strict_types=1);

namespace WatchTower\Tests\Support;

use WatchTower\Shield\ShieldHttp;

/**
 * Answers Shield's GET/POST calls from per-URL queues and records every request.
 */
final class FakeShieldHttp implements ShieldHttp
{
    /** @var list<array{method: string, url: string, headers: array<string, string>, body: ?string, connect_timeout: float, timeout: float}> */
    public array $requests = [];

    /** @var array<string, list<array{status: int, body: string}>> */
    private array $queues = [];

    /** @var array<string, array{status: int, body: string}> */
    private array $defaults = [];

    public function queue(string $url, int $status, string|array $body = ''): self
    {
        $this->queues[$url][] = ['status' => $status, 'body' => is_array($body) ? (string) json_encode($body) : $body];

        return $this;
    }

    public function always(string $url, int $status, string|array $body = ''): self
    {
        $this->defaults[$url] = ['status' => $status, 'body' => is_array($body) ? (string) json_encode($body) : $body];

        return $this;
    }

    public function request(string $method, string $url, array $headers, ?string $body, float $connectTimeout, float $timeout): array
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body, 'connect_timeout' => $connectTimeout, 'timeout' => $timeout];

        if (($this->queues[$url] ?? []) !== []) {
            return array_shift($this->queues[$url]);
        }

        return $this->defaults[$url] ?? ['status' => 0, 'body' => ''];
    }

    /**
     * @return list<array{method: string, url: string, headers: array<string, string>, body: ?string, connect_timeout: float, timeout: float}>
     */
    public function to(string $url): array
    {
        return array_values(array_filter($this->requests, static fn (array $request): bool => $request['url'] === $url));
    }
}
