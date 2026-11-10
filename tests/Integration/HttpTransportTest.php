<?php

declare(strict_types=1);

namespace WatchTower\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use WatchTower\Client;
use WatchTower\Config;
use WatchTower\Tests\Support\BuiltInServer;
use WatchTower\Tests\Support\TestCase;
use WatchTower\Transport\CurlHttpClient;
use WatchTower\Transport\Ingest;
use WatchTower\Transport\StreamHttpClient;
use WatchTower\WatchTower;

final class HttpTransportTest extends TestCase
{
    private static BuiltInServer $server;

    public static function setUpBeforeClass(): void
    {
        self::$server = new BuiltInServer;
    }

    public static function tearDownAfterClass(): void
    {
        self::$server->stop();
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function clients(): array
    {
        return ['curl' => [CurlHttpClient::class], 'streams' => [StreamHttpClient::class]];
    }

    #[DataProvider('clients')]
    public function test_posts_gzipped_batches(string $class): void
    {
        self::$server->respondWith(202);
        $before = count(self::$server->requests());

        $client = new Client(new Config([
            'token' => 'env-token',
            'base_url' => self::$server->url,
            'spool_dir' => $this->spoolDir,
            'capture' => ['hello' => false],
        ]), new $class);
        $client->startRequest(self::server());
        $client->finish(['status_code' => 200]);

        $requests = self::$server->requests();
        $this->assertCount($before + 1, $requests);
        $request = end($requests);

        $this->assertSame('POST', $request['method']);
        $this->assertSame('Bearer env-token', $request['headers']['authorization']);
        $this->assertSame('application/json', $request['headers']['content-type']);
        $this->assertSame('gzip', $request['headers']['content-encoding']);
        $this->assertSame('watchtower-php/'.WatchTower::VERSION, $request['headers']['user-agent']);
        $this->assertSame('request', json_decode((string) gzdecode($request['body']), true)['records'][0]['t']);
        $this->assertCount(0, self::spooled($this->spoolDir));
    }

    #[DataProvider('clients')]
    public function test_reports_status_codes_and_unreachable_hosts(string $class): void
    {
        $http = new $class;
        self::$server->respondWith(401);
        $this->assertSame(401, $http->post(self::$server->url.'/api/ingest/v1', [], '{}', 1.0, 2.0));
        self::$server->respondWith(413);
        $this->assertSame(413, $http->post(self::$server->url.'/api/ingest/v1', [], '{}', 1.0, 2.0));
        self::$server->respondWith(202);

        $started = microtime(true);
        $this->assertSame(0, $http->post('http://127.0.0.1:9/api/ingest/v1', [], '{}', 0.5, 0.5));
        $this->assertLessThan(2.0, microtime(true) - $started);
    }

    public function test_curl_is_preferred(): void
    {
        $this->assertInstanceOf(function_exists('curl_init') ? CurlHttpClient::class : StreamHttpClient::class, Ingest::defaultHttpClient());
    }
}
