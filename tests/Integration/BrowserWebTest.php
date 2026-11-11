<?php

declare(strict_types=1);

namespace WatchTower\Tests\Integration;

use WatchTower\Tests\Support\BuiltInServer;
use WatchTower\Tests\Support\FakeHttpClient;
use WatchTower\Tests\Support\TestCase;

/**
 * The browser proxy in a real `php -S` app: WatchTower::handleBrowserProxy() right after init()
 * serves the script and forwards beacons to a stand-in Watch Tower, then exits before the app.
 */
final class BrowserWebTest extends TestCase
{
    private static BuiltInServer $watchTower;

    private static BuiltInServer $app;

    private static string $appSpool;

    public static function setUpBeforeClass(): void
    {
        self::$watchTower = new BuiltInServer;
        self::$appSpool = sys_get_temp_dir().'/watchtower-browser-app-'.bin2hex(random_bytes(6));
        self::$app = new BuiltInServer(dirname(__DIR__).'/Fixtures/app/router.php', [
            'WT_URL' => self::$watchTower->url,
            'WT_MODE' => 'auto',
            'WT_SPOOL_DIR' => self::$appSpool,
            'WT_BROWSER_KEY' => 'browser-key-123',
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        self::$app->stop();
        self::$watchTower->stop();
        self::removeDirectory(self::$appSpool);
    }

    /**
     * @param  list<string>  $headers
     * @return array{status: int, body: string, content_type: string}
     */
    private static function request(string $path, array $headers = [], ?string $post = null): array
    {
        $handle = curl_init(self::$app->url.$path);
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers]);

        if ($post !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $post);
        }

        $body = (string) curl_exec($handle);

        return [
            'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            'body' => $body,
            'content_type' => (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function captured(string $prefix): array
    {
        $files = glob(self::$watchTower->captureDir.'/'.$prefix.'-*.json') ?: [];
        sort($files);

        return array_map(static fn (string $file): array => json_decode((string) file_get_contents($file), true), $files);
    }

    public function test_the_page_gets_a_first_party_script_tag(): void
    {
        $page = self::request('/page');

        $this->assertSame('<head><script src="/_t/s.js" data-key="browser-key-123" data-endpoint="/_t/e" data-release="v2.0.0" defer></script></head>', $page['body']);
        $this->assertStringNotContainsString('script-token', $page['body']);
    }

    public function test_the_script_is_proxied_and_cached(): void
    {
        $first = self::request('/_t/s.js');
        $second = self::request('/_t/s.js');

        $this->assertSame([200, '/* watchtower-browser */'], [$first['status'], $first['body']]);
        $this->assertSame('application/javascript; charset=utf-8', $first['content_type']);
        $this->assertSame('/* watchtower-browser */', $second['body']);
        $this->assertCount(1, self::captured('browser-script'));
    }

    public function test_beacons_are_forwarded_and_the_proxy_requests_are_not_reported(): void
    {
        $before = count(self::$watchTower->requests());
        $response = self::request('/_t/e', ['Content-Type: text/plain;charset=UTF-8', 'User-Agent: Mozilla/5.0 Firefox/133.0'], '{"events":[{"type":"view"}]}');

        $this->assertSame([204, ''], [$response['status'], $response['body']]);

        $beacons = self::captured('browser-beacon');
        $this->assertCount(1, $beacons);
        $this->assertSame('POST', $beacons[0]['method']);
        $this->assertSame('/api/browser/v1/browser-key-123', $beacons[0]['path']);
        $this->assertSame('{"events":[{"type":"view"}]}', $beacons[0]['body']);
        $this->assertSame('Bearer script-token', $beacons[0]['headers']['authorization']);
        $this->assertStringStartsWith('text/plain', $beacons[0]['headers']['content-type']);
        $this->assertSame(self::$app->url, $beacons[0]['headers']['x-watchtower-origin']);
        $this->assertSame('Mozilla/5.0 Firefox/133.0', $beacons[0]['headers']['x-watchtower-user-agent']);
        $this->assertSame('127.0.0.1', $beacons[0]['headers']['x-watchtower-client-ip']);

        // A normal request afterwards is reported; the proxy requests before it were not.
        self::request('/orders/7');
        $deadline = microtime(true) + 5;

        while (count(self::$watchTower->requests()) <= $before && microtime(true) < $deadline) {
            usleep(20_000);
        }

        $records = [];

        foreach (array_slice(self::$watchTower->requests(), $before) as $request) {
            array_push($records, ...FakeHttpClient::decode($request['body']));
        }

        $paths = array_column(self::ofType($records, 'request'), 'url');
        $this->assertSame([self::$app->url.'/orders/7'], $paths);
        $this->assertSame([], self::ofType($records, 'outgoing-request'), 'the SDK\'s own calls are not recorded');
    }
}
