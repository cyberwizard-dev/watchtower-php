<?php

declare(strict_types=1);

namespace WatchTower\Tests\Support;

use WatchTower\Transport\HttpClient;

/**
 * Records every POST and answers with queued status codes (default 202).
 */
final class FakeHttpClient implements HttpClient
{
    /** @var list<array{url: string, headers: array<string, string>, body: string, connect_timeout: float, timeout: float}> */
    public array $requests = [];

    /** @var list<int|\Closure(string): int> */
    private array $statuses = [];

    public function __construct(private int $default = 202) {}

    public function respond(int|\Closure ...$statuses): self
    {
        array_push($this->statuses, ...$statuses);

        return $this;
    }

    public function always(int $status): self
    {
        $this->default = $status;

        return $this;
    }

    public function post(string $url, array $headers, string $body, float $connectTimeout, float $timeout): int
    {
        $this->requests[] = ['url' => $url, 'headers' => $headers, 'body' => $body, 'connect_timeout' => $connectTimeout, 'timeout' => $timeout];
        $status = array_shift($this->statuses) ?? $this->default;

        return $status instanceof \Closure ? $status($body) : $status;
    }

    /**
     * Records of every request, decoded (gunzipped when needed).
     *
     * @return list<array<string, mixed>>
     */
    public function records(): array
    {
        $records = [];

        foreach ($this->requests as $request) {
            array_push($records, ...self::decode($request['body'], $request['headers']));
        }

        return $records;
    }

    /**
     * @param  array<string, string>  $headers
     * @return list<array<string, mixed>>
     */
    public static function decode(string $body, array $headers = ['Content-Encoding' => 'gzip']): array
    {
        if (($headers['Content-Encoding'] ?? null) === 'gzip') {
            $body = (string) gzdecode($body);
        }

        return json_decode($body, true, 512, JSON_THROW_ON_ERROR)['records'];
    }
}
