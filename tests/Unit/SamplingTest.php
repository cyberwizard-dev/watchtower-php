<?php

declare(strict_types=1);

namespace WatchTower\Tests\Unit;

use WatchTower\Tests\Support\TestCase;

final class SamplingTest extends TestCase
{
    public function test_unsampled_executions_are_dropped(): void
    {
        $client = $this->client(['sample_rate' => 0.0]);
        $client->startRequest(self::server());
        $client->log('info', 'dropped');
        $client->finish();

        $this->assertCount(0, $this->http->requests);
    }

    public function test_unhandled_exceptions_keep_an_unsampled_execution(): void
    {
        $client = $this->client(['sample_rate' => 0.0]);
        $client->startRequest(self::server());
        $client->log('info', 'context for the error');
        $client->captureException(new \RuntimeException('boom'), false);
        $client->finish();

        $records = $this->http->records();
        $this->assertCount(1, self::ofType($records, 'request'));
        $this->assertCount(1, self::ofType($records, 'log'));
        $this->assertCount(1, self::ofType($records, 'exception'));
    }

    public function test_exception_sample_rate_applies_to_unhandled_exceptions(): void
    {
        $client = $this->client(['sample_rate' => 0.0, 'exception_sample_rate' => 0.0]);
        $client->startRequest(self::server());
        $client->captureException(new \RuntimeException('boom'), false);
        $client->finish();

        $this->assertCount(0, $this->http->requests);
    }

    public function test_handled_exceptions_do_not_force_sampling(): void
    {
        $client = $this->client(['sample_rate' => 0.0]);
        $client->startRequest(self::server());
        $client->captureException(new \RuntimeException('handled'), true);
        $client->finish();

        $this->assertCount(0, $this->http->requests);
    }

    public function test_slow_requests_are_always_kept(): void
    {
        $client = $this->client(['sample_rate' => 0.0, 'slow_request_ms' => 10]);
        $client->startRequest(self::server(['REQUEST_TIME_FLOAT' => microtime(true) - 0.5]));
        $client->finish();

        $this->assertCount(1, self::ofType($this->http->records(), 'request'));
    }

    public function test_slow_queries_keep_the_execution(): void
    {
        $client = $this->client(['sample_rate' => 0.0, 'slow_query_ms' => 100]);
        $client->startRequest(self::server());
        $client->query('select 1', microtime(true), 50_000, 'mysql');
        $client->finish();
        $this->assertCount(0, $this->http->requests);

        $client->startRequest(self::server());
        $client->query('select sleep(1)', microtime(true), 150_000, 'mysql');
        $client->finish();
        $this->assertCount(1, self::ofType($this->http->records(), 'query'));
    }

    public function test_sample_rate_is_roughly_honoured(): void
    {
        $client = $this->client(['sample_rate' => 0.5]);
        $kept = 0;

        for ($i = 0; $i < 400; $i++) {
            $kept += $client->startRequest(self::server())->sampled ? 1 : 0;
        }

        $this->assertGreaterThan(120, $kept);
        $this->assertLessThan(280, $kept);
    }
}
