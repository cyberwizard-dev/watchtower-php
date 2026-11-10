<?php

declare(strict_types=1);

namespace WatchTower\Tests\Unit;

use WatchTower\Tests\Support\TestCase;
use WatchTower\WatchTower;

final class FacadeTest extends TestCase
{
    public function test_everything_is_a_safe_no_op_before_init(): void
    {
        WatchTower::route('/users/{id}');
        WatchTower::user(1, 'Ada', 'ada');
        WatchTower::captureException(new \RuntimeException('ignored'));
        WatchTower::logger()->error('ignored');
        WatchTower::exitCode(2);
        WatchTower::ignore();
        WatchTower::flush();

        $this->assertNull(WatchTower::client());
        $this->assertNull(WatchTower::traceparent());
        $this->assertSame(42, WatchTower::command('task', static fn (): int => 42));
    }

    public function test_init_returns_the_same_client_and_reads_the_environment(): void
    {
        putenv('WATCHTOWER_TOKEN=env-token');
        putenv('WATCHTOWER_URL=https://wt.example.com/');

        try {
            $client = WatchTower::init(['install_handlers' => false, 'mode' => 'spool', 'spool_dir' => $this->spoolDir, 'capture' => ['hello' => false]]);
        } finally {
            putenv('WATCHTOWER_TOKEN');
            putenv('WATCHTOWER_URL');
        }

        $this->assertNotNull($client);
        $this->assertSame('env-token', $client->config->token);
        $this->assertSame('https://wt.example.com/api/ingest/v1', $client->config->ingestUrl());
        $this->assertSame($client, WatchTower::init(['token' => 'other']));
        $this->assertSame($client, WatchTower::client());
        $this->assertSame('command', $client->execution()?->source);

        // The shutdown function registered by init() must not report the test runner itself.
        WatchTower::ignore();
    }

    public function test_an_unusable_spool_never_throws(): void
    {
        $this->http->always(500);
        $client = $this->client(['spool_dir' => '/dev/null/watchtower']);
        $client->startRequest(self::server());
        $client->captureException(new \RuntimeException('boom'), false);
        $client->finish();

        $this->assertCount(1, $this->http->requests);
    }
}
