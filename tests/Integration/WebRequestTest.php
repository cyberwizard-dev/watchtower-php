<?php

declare(strict_types=1);

namespace WatchTower\Tests\Integration;

use WatchTower\Tests\Support\BuiltInServer;
use WatchTower\Tests\Support\FakeHttpClient;
use WatchTower\Tests\Support\TestCase;
use WatchTower\Tests\Support\WireFixtures;

/**
 * A plain PHP app served by `php -S` reports to a stand-in Watch Tower
 * after each response.
 */
final class WebRequestTest extends TestCase
{
    private static BuiltInServer $ingest;

    private static BuiltInServer $app;

    private static string $appSpool;

    public static function setUpBeforeClass(): void
    {
        self::$ingest = new BuiltInServer;
        self::$appSpool = sys_get_temp_dir().'/watchtower-app-'.bin2hex(random_bytes(6));
        self::$app = new BuiltInServer(dirname(__DIR__).'/Fixtures/app/router.php', [
            'WT_URL' => self::$ingest->url,
            'WT_MODE' => 'auto',
            'WT_SPOOL_DIR' => self::$appSpool,
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        self::$app->stop();
        self::$ingest->stop();
        self::removeDirectory(self::$appSpool);
    }

    /**
     * @return array{status: int, body: string}
     */
    private static function get(string $path, array $headers = []): array
    {
        $handle = curl_init(self::$app->url.$path);
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers]);
        $body = (string) curl_exec($handle);

        return ['status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $body];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function recordsAfter(int $before): array
    {
        $deadline = microtime(true) + 5;

        while (count(self::$ingest->requests()) <= $before && microtime(true) < $deadline) {
            usleep(20_000);
        }

        $records = [];

        foreach (array_slice(self::$ingest->requests(), $before) as $request) {
            array_push($records, ...FakeHttpClient::decode($request['body']));
        }

        return $records;
    }

    public function test_requests_are_reported_after_the_response(): void
    {
        $before = count(self::$ingest->requests());
        $response = self::get('/orders/42?coupon=x&password=hunter2', ['traceparent: 00-'.str_repeat('c', 32).'-'.str_repeat('d', 16).'-01', 'Authorization: Bearer abc']);

        $this->assertSame(201, $response['status']);
        $this->assertSame(1000, strlen($response['body']));

        $records = self::recordsAfter($before);
        $request = self::one($records, 'request');
        WireFixtures::assertShape($request);

        $this->assertSame('/orders/{id}', $request['route_path']);
        $this->assertSame('orders.show', $request['route_name']);
        $this->assertSame('orders.php', $request['route_action']);
        $this->assertSame(201, $request['status_code']);
        $this->assertSame(1000, $request['response_size']);
        $this->assertSame('9', $request['user']);
        $this->assertSame('127.0.0.1', $request['ip']);
        $this->assertSame(1, $request['logs']);
        $this->assertStringEndsWith('/orders/42?coupon=x&password=[redacted]', $request['url']);
        $this->assertSame(['[redacted]'], json_decode($request['headers'], true)['authorization']);

        $this->assertSame('Order {id} is late', self::one($records, 'log')['message']);
        $this->assertSame('Grace Hopper', self::one($records, 'user')['name']);
        $this->assertSame(str_repeat('c', 32), self::one($records, 'wt-trace-link')['parent_trace_id']);
    }

    public function test_errors_mark_the_request_as_failed(): void
    {
        $before = count(self::$ingest->requests());
        $this->assertSame(500, self::get('/boom')['status']);

        $records = self::recordsAfter($before);
        $request = self::one($records, 'request');
        $this->assertSame(500, $request['status_code']);
        $this->assertSame('Checkout exploded', $request['exception_preview']);
        $this->assertSame(2, $request['exceptions']);

        $exceptions = self::ofType($records, 'exception');
        $this->assertSame(['ErrorException', 'LogicException'], array_column($exceptions, 'class'));
        $this->assertSame([true, false], array_column($exceptions, 'handled'));
    }

    public function test_ignored_paths_send_nothing(): void
    {
        $before = count(self::$ingest->requests());
        $this->assertSame('ok', self::get('/health')['body']);
        usleep(300_000);

        $this->assertCount($before, self::$ingest->requests());
    }
}
