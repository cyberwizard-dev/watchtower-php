<?php

declare(strict_types=1);

namespace WatchTower\Tests\Unit;

use WatchTower\Client;
use WatchTower\Tests\Support\FakeHttpClient;
use WatchTower\Tests\Support\TestCase;
use WatchTower\Tests\Support\WireFixtures;
use WatchTower\Transport\Spool;
use WatchTower\WatchTower;

final class TransportTest extends TestCase
{
    public function test_batches_are_posted_gzipped_with_the_contract_headers(): void
    {
        $client = $this->client(['connect_timeout' => 1.0, 'timeout' => 2.0]);
        $client->startRequest(self::server());
        $client->finish();

        $this->assertCount(1, $this->http->requests);
        $request = $this->http->requests[0];

        $this->assertSame('https://watchtower.test/api/ingest/v1', $request['url']);
        $this->assertSame('Bearer test-token', $request['headers']['Authorization']);
        $this->assertSame('application/json', $request['headers']['Content-Type']);
        $this->assertSame('gzip', $request['headers']['Content-Encoding']);
        $this->assertSame('watchtower-php/'.WatchTower::VERSION, $request['headers']['User-Agent']);
        $this->assertLessThanOrEqual(1.0, $request['connect_timeout']);
        $this->assertLessThanOrEqual(2.0, $request['timeout']);

        $json = gzdecode($request['body']);
        $this->assertIsString($json);
        $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(['records'], array_keys($decoded));
        $this->assertSame('request', $decoded['records'][0]['t']);
        $this->assertStringContainsString('"timestamp":'.sprintf('%d', (int) $decoded['records'][0]['timestamp']).'.', $json, 'timestamps stay floats');
    }

    public function test_unauthorized_backs_off_for_ten_minutes_and_spools(): void
    {
        $this->http->respond(401);

        $client = $this->client();
        $client->startRequest(self::server());
        $client->finish();

        $this->assertCount(1, $this->http->requests);
        $this->assertTrue($client->ingest->isBackedOff());
        $backoff = (float) file_get_contents(glob($this->spoolDir.'/auth-backoff-*')[0]);
        $this->assertEqualsWithDelta(microtime(true) + 600, $backoff, 5);
        $this->assertCount(1, self::ofType(self::spooled($this->spoolDir), 'request'));

        // A later request during the back-off does not hit the network.
        $second = $this->client();
        $second->startRequest(self::server());
        $second->finish();

        $this->assertCount(1, $this->http->requests);
        $this->assertCount(2, self::ofType(self::spooled($this->spoolDir), 'request'));
    }

    public function test_payload_too_large_is_split_in_half(): void
    {
        $this->http->respond(
            static fn (string $body): int => count(FakeHttpClient::decode($body)) > 2 ? 413 : 202,
            static fn (string $body): int => count(FakeHttpClient::decode($body)) > 2 ? 413 : 202,
            static fn (string $body): int => count(FakeHttpClient::decode($body)) > 2 ? 413 : 202,
        );

        $client = $this->client();
        $client->startRequest(self::server());
        $client->log('info', 'one');
        $client->log('info', 'two');
        $client->log('info', 'three');
        $client->finish();

        $this->assertCount(3, $this->http->requests);
        $this->assertCount(4, FakeHttpClient::decode($this->http->requests[0]['body']), 'first attempt is rejected');
        $this->assertCount(2, FakeHttpClient::decode($this->http->requests[1]['body']));
        $this->assertCount(2, FakeHttpClient::decode($this->http->requests[2]['body']));
        $this->assertCount(0, self::spooled($this->spoolDir));
    }

    public function test_server_errors_spool_and_the_next_success_drains(): void
    {
        $this->http->respond(500);

        $failing = $this->client();
        $failing->startRequest(self::server());
        $failing->log('error', 'kept for later');
        $failing->finish();

        $this->assertCount(2, self::spooled($this->spoolDir));

        $client = $this->client();
        $client->startRequest(self::server());
        $client->finish();

        $this->assertCount(3, $this->http->requests);
        $this->assertCount(0, self::spooled($this->spoolDir));
        $this->assertSame('kept for later', self::one(FakeHttpClient::decode($this->http->requests[2]['body']), 'log')['message']);
    }

    public function test_network_failures_never_throw(): void
    {
        $this->http->always(0);

        $client = $this->client();
        $client->startRequest(self::server());
        $client->finish();

        $this->assertCount(1, self::ofType(self::spooled($this->spoolDir), 'request'));
    }

    public function test_spool_mode_never_sends_inline(): void
    {
        $client = $this->client(['mode' => 'spool']);
        $client->startRequest(self::server());
        $client->finish();

        $this->assertCount(0, $this->http->requests);
        $this->assertCount(1, self::spooled($this->spoolDir));

        $sent = $client->ingest->drain(10);
        $this->assertSame(1, $sent);
        $this->assertCount(1, $this->http->requests);
        $this->assertCount(0, self::spooled($this->spoolDir));
    }

    public function test_drain_stops_on_failure_and_keeps_batches(): void
    {
        $client = $this->client(['mode' => 'spool']);

        for ($i = 0; $i < 3; $i++) {
            $client->startRequest(self::server());
            $client->finish();
        }

        $this->http->respond(202, 500);
        $this->assertSame(1, $client->ingest->drain(10));
        $this->assertSame(2, (new Spool($this->spoolDir, 1 << 20))->count());
    }

    public function test_the_buffer_is_capped_with_a_summary_log(): void
    {
        $client = $this->client(['max_records' => 10]);
        $client->startRequest(self::server());

        for ($i = 0; $i < 25; $i++) {
            $client->query('select 1', microtime(true), 5, 'mysql');
        }

        $client->finish();

        $records = $this->http->records();
        $this->assertCount(10, self::ofType($records, 'query'));
        $summary = self::one($records, 'log');
        $this->assertSame('warning', $summary['level']);
        $this->assertStringContainsString('dropped 15 records', $summary['message']);
        $this->assertSame(25, self::one($records, 'request')['queries'], 'counters still count everything');
    }

    public function test_missing_configuration_spools_instead_of_sending(): void
    {
        $client = $this->client(['token' => null, 'base_url' => '']);
        putenv('WATCHTOWER_TOKEN');
        $client->startRequest(self::server());
        $client->finish();

        $this->assertCount(0, $this->http->requests);
    }

    public function test_disabled_sdk_records_nothing(): void
    {
        $client = $this->client(['enabled' => false]);
        $client->startRequest(self::server());
        $client->finish();

        $this->assertCount(0, $this->http->requests);
        $this->assertCount(0, self::spooled($this->spoolDir));
    }

    public function test_ignored_paths_are_not_reported(): void
    {
        $client = $this->client(['ignore_paths' => ['/health', '/assets/*']]);

        foreach (['/health', '/assets/app.css'] as $path) {
            $client->startRequest(self::server(['REQUEST_URI' => $path]));
            $client->log('info', 'ignored');
            $client->finish();
        }

        $this->assertCount(0, $this->http->requests);
    }

    public function test_hello_is_sent_once_per_day(): void
    {
        $options = ['capture' => ['hello' => true], 'framework' => 'wordpress', 'service' => 'Blog', 'environment' => 'production'];
        $client = $this->client($options);
        $client->startRequest(self::server());
        $client->finish();

        $hello = self::one($this->http->records(), 'wt-hello');
        WireFixtures::assertShape($hello);
        $this->assertSame('php', $hello['sdk']);
        $this->assertSame(WatchTower::VERSION, $hello['sdk_version']);
        $this->assertSame(PHP_VERSION, $hello['php_version']);
        $this->assertSame('wordpress', $hello['framework']);
        $this->assertSame('Blog', $hello['service']);
        $this->assertContains('trace_context', $hello['features']);

        // A new process (flag reset) still respects the daily marker file.
        Client::resetProcessState();
        $again = $this->client($options);
        $again->startRequest(self::server());
        $again->finish();
        $this->assertCount(1, self::ofType($this->http->records(), 'wt-hello'));

        // An expired marker sends it again.
        touch(glob($this->spoolDir.'/hello-*')[0], time() - 90000);
        Client::resetProcessState();
        $later = $this->client($options);
        $later->startRequest(self::server());
        $later->finish();
        $this->assertCount(2, self::ofType($this->http->records(), 'wt-hello'));
    }
}
