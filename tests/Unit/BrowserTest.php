<?php

declare(strict_types=1);

namespace WatchTower\Tests\Unit;

use WatchTower\Browser\Browser;
use WatchTower\Client;
use WatchTower\Config;
use WatchTower\Shield\ShieldHttp;
use WatchTower\Tests\Support\FakeShieldHttp;
use WatchTower\Tests\Support\TestCase;
use WatchTower\WatchTower;
use WatchTower\WordPress\Integration;

final class BrowserTest extends TestCase
{
    private const SCRIPT_URL = 'https://watchtower.test/watchtower-browser.js';

    private const BEACON_URL = 'https://watchtower.test/api/browser/v1/browser-key-123';

    private int $now = 1794000000;

    private FakeShieldHttp $browserHttp;

    protected function setUp(): void
    {
        parent::setUp();
        $this->browserHttp = new FakeShieldHttp;
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function browser(array $options = []): Browser
    {
        return new Browser($this->browserClient($options)->config, $this->browserHttp, fn (): int => $this->now);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    private function browserClient(array $options = []): Client
    {
        $client = $this->client(['browser_key' => 'browser-key-123', ...$options]);
        $client->useBrowser(new Browser($client->config, $this->browserHttp, fn (): int => $this->now));

        return $client;
    }

    /**
     * @param  array<string, mixed>  $server
     * @return array<string, mixed>
     */
    private static function beacon(array $server = []): array
    {
        return self::server([
            'REQUEST_METHOD' => 'POST',
            'REQUEST_URI' => '/_t/e',
            'CONTENT_TYPE' => 'text/plain;charset=UTF-8',
            'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/131.0',
            'REMOTE_ADDR' => '198.51.100.23',
            ...$server,
        ]);
    }

    public function test_tag_points_at_the_first_party_proxy_and_never_contains_the_token(): void
    {
        $html = $this->browser()->tag();

        $this->assertSame('<script src="/_t/s.js" data-key="browser-key-123" data-endpoint="/_t/e" data-release="v1.0.0" defer></script>', $html);
        $this->assertStringNotContainsString('test-token', $html);
    }

    public function test_tag_loads_the_script_from_watch_tower_when_the_proxy_is_off(): void
    {
        $this->assertSame(
            '<script src="https://watchtower.test/watchtower-browser.js" data-key="browser-key-123" data-release="sha-abc" data-sample="0.25" defer></script>',
            $this->browser(['browser_proxy' => false, 'browser_release' => 'sha-abc', 'browser_sample' => '0.25'])->tag(),
        );
    }

    public function test_the_proxy_needs_the_environment_token(): void
    {
        $browser = new Browser(new Config(['browser_key' => 'k', 'base_url' => 'https://watchtower.test', 'deploy' => '']), $this->browserHttp);

        $this->assertFalse($browser->proxied());
        $this->assertSame('<script src="https://watchtower.test/watchtower-browser.js" data-key="k" defer></script>', $browser->tag());
        $this->assertNull($browser->handle(self::beacon(), static fn (): string => '{}'));
    }

    public function test_tag_uses_the_configured_path(): void
    {
        $this->assertStringStartsWith('<script src="/rum/s.js" data-key="browser-key-123" data-endpoint="/rum/e"', $this->browser(['browser_path' => 'rum/'])->tag());
    }

    public function test_tag_escapes_attributes(): void
    {
        $html = $this->browser(['browser_key' => 'k"><script>alert(1)</script>', 'browser_release' => "v1 & 'two'"])->tag();

        $this->assertSame('<script src="/_t/s.js" data-key="k&quot;&gt;&lt;script&gt;alert(1)&lt;/script&gt;" data-endpoint="/_t/e" data-release="v1 &amp; &#039;two&#039;" defer></script>', $html);
    }

    public function test_nothing_is_rendered_without_a_browser_key_or_when_disabled(): void
    {
        $this->assertSame('', $this->browser(['browser_key' => null])->tag());
        $this->assertSame('', $this->browser(['browser_key' => ''])->tag());
        $this->assertSame('', $this->browser(['enabled' => false])->tag());
        $this->assertSame('', WatchTower::browserTag(), 'no-op before init()');
    }

    public function test_options_fall_back_to_environment_variables(): void
    {
        putenv('WATCHTOWER_BROWSER_KEY=env-key');
        putenv('WATCHTOWER_BROWSER_PROXY=false');
        putenv('WATCHTOWER_BROWSER_SAMPLE=2');

        try {
            $config = new Config(['token' => 't', 'base_url' => 'https://watchtower.test']);
        } finally {
            putenv('WATCHTOWER_BROWSER_KEY');
            putenv('WATCHTOWER_BROWSER_PROXY');
            putenv('WATCHTOWER_BROWSER_SAMPLE');
        }

        $this->assertSame('env-key', $config->browserKey);
        $this->assertFalse($config->browserProxy);
        $this->assertSame(1.0, $config->browserSample);
        $this->assertSame('/_t', $config->browserPath);
    }

    public function test_beacons_are_forwarded_with_the_token_and_the_visitors_details(): void
    {
        $response = $this->browser()->handle(self::beacon(), static fn (): string => '{"events":[{"type":"view"}]}');

        $this->assertSame(204, $response?->status);
        $this->assertSame('', $response->body);

        $sent = $this->browserHttp->to(self::BEACON_URL);
        $this->assertCount(1, $sent);
        $this->assertSame('POST', $sent[0]['method']);
        $this->assertSame('{"events":[{"type":"view"}]}', $sent[0]['body']);
        $this->assertSame('Bearer test-token', $sent[0]['headers']['Authorization']);
        $this->assertSame('text/plain', $sent[0]['headers']['Content-Type']);
        $this->assertSame('https://app.test', $sent[0]['headers']['X-Watchtower-Origin']);
        $this->assertSame('Mozilla/5.0 Chrome/131.0', $sent[0]['headers']['X-Watchtower-User-Agent']);
        $this->assertSame('198.51.100.23', $sent[0]['headers']['X-Watchtower-Client-Ip']);
        $this->assertSame([3.0, 3.0], [$sent[0]['connect_timeout'], $sent[0]['timeout']]);
    }

    public function test_the_client_ip_comes_from_the_trusted_proxy_rules(): void
    {
        $browser = $this->browser(['client_ip' => ['preset' => 'nginx']]);

        $browser->handle(self::beacon(['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 203.0.113.9']), static fn (): string => '{}');
        $browser->handle(self::beacon(['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6']), static fn (): string => '{}');

        $sent = $this->browserHttp->to(self::BEACON_URL);
        $this->assertSame('203.0.113.9', $sent[0]['headers']['X-Watchtower-Client-Ip']);
        $this->assertSame('198.51.100.7', $sent[1]['headers']['X-Watchtower-Client-Ip'], 'a spoofed header from an untrusted peer is ignored');
    }

    public function test_header_values_cannot_inject_lines(): void
    {
        $this->browser()->handle(self::beacon(['HTTP_USER_AGENT' => "Evil\r\nX-Injected: 1"]), static fn (): string => '{}');

        $this->assertSame('Evil X-Injected: 1', $this->browserHttp->to(self::BEACON_URL)[0]['headers']['X-Watchtower-User-Agent']);
    }

    public function test_empty_and_oversized_beacons_are_dropped_but_still_answered(): void
    {
        $browser = $this->browser();

        $this->assertSame(204, $browser->handle(self::beacon(), static fn (): string => '')?->status);
        $this->assertSame(204, $browser->handle(self::beacon(), static fn (): string => str_repeat('x', Browser::MAX_BEACON_BYTES + 1))?->status);
        $this->assertSame(204, $browser->handle(self::beacon(['CONTENT_LENGTH' => '1000000']), static fn (): string => throw new \LogicException('not read'))?->status);

        $this->assertSame([], $this->browserHttp->requests);
    }

    public function test_beacons_never_fail_the_visitor_when_watch_tower_is_down(): void
    {
        $this->browserHttp->always(self::BEACON_URL, 0);
        $failing = new Browser($this->browserClient()->config, new class implements ShieldHttp
        {
            public function request(string $method, string $url, array $headers, ?string $body, float $connectTimeout, float $timeout): array
            {
                throw new \RuntimeException('down');
            }
        });

        $this->assertSame(204, $this->browser()->handle(self::beacon(), static fn (): string => '{}')?->status);
        $this->assertSame(204, $failing->handle(self::beacon(), static fn (): string => '{}')?->status);
        $this->assertSame(200, $failing->handle(self::server(['REQUEST_URI' => '/_t/s.js']))?->status);
    }

    public function test_the_script_is_served_from_watch_tower_and_cached_for_an_hour(): void
    {
        $this->browserHttp->queue(self::SCRIPT_URL, 200, '/* v1 */')->queue(self::SCRIPT_URL, 200, '/* v2 */');
        $browser = $this->browser();
        $request = self::server(['REQUEST_URI' => '/_t/s.js?v=1']);

        $first = $browser->handle($request);
        $this->assertSame([200, '/* v1 */'], [$first?->status, $first->body]);
        $this->assertSame('application/javascript; charset=utf-8', $first->header('Content-Type'));
        $this->assertSame('public, max-age=3600', $first->header('Cache-Control'));
        $this->assertStringNotContainsString('test-token', json_encode($this->browserHttp->requests));

        $this->now += 3599;
        $this->assertSame('/* v1 */', $browser->handle($request)?->body);
        $this->assertCount(1, $this->browserHttp->to(self::SCRIPT_URL));

        $this->now += 2;
        $this->assertSame('/* v2 */', $browser->handle($request)?->body);
        $this->assertCount(2, $this->browserHttp->to(self::SCRIPT_URL));
        $this->assertSame([3.0, 3.0], [$this->browserHttp->requests[0]['connect_timeout'], $this->browserHttp->requests[0]['timeout']]);
    }

    public function test_the_script_is_an_empty_200_when_watch_tower_is_down(): void
    {
        $this->browserHttp->always(self::SCRIPT_URL, 503, 'Service Unavailable');
        $browser = $this->browser();
        $request = self::server(['REQUEST_URI' => '/_t/s.js']);

        $response = $browser->handle($request);
        $this->assertSame([200, ''], [$response?->status, $response->body]);
        $this->assertSame('application/javascript', $response->header('Content-Type'));
        $this->assertSame('no-store', $response->header('Cache-Control'));

        // Retried after a minute, not on every page view.
        $browser->handle($request);
        $this->assertCount(1, $this->browserHttp->to(self::SCRIPT_URL));
        $this->now += Browser::SCRIPT_RETRY_SECONDS + 1;
        $browser->handle($request);
        $this->assertCount(2, $this->browserHttp->to(self::SCRIPT_URL));
    }

    public function test_the_last_good_script_is_kept_when_a_refresh_fails(): void
    {
        $this->browserHttp->queue(self::SCRIPT_URL, 200, '/* good */')->always(self::SCRIPT_URL, 0);
        $browser = $this->browser();
        $request = self::server(['REQUEST_URI' => '/_t/s.js']);

        $browser->handle($request);
        $this->now += Browser::SCRIPT_TTL_SECONDS + 1;

        $this->assertSame('/* good */', $browser->handle($request)?->body);
        $this->assertSame('/* good */', $browser->handle($request)?->body);
        $this->assertCount(2, $this->browserHttp->to(self::SCRIPT_URL), 'one failed refresh, then the minute back-off');
    }

    public function test_the_replay_recorder_is_served_from_watch_tower_and_cached(): void
    {
        $this->browserHttp->queue('https://watchtower.test/watchtower-replay.js', 200, '/* recorder */');
        $browser = $this->browser();
        $request = self::server(['REQUEST_URI' => '/_t/r.js']);

        $this->assertSame([200, '/* recorder */'], [$browser->handle($request)?->status, $browser->handle($request)?->body]);
        $this->assertCount(1, $this->browserHttp->to('https://watchtower.test/watchtower-replay.js'));
        $this->assertFileExists($browser->recorderPath());
    }

    public function test_replay_settings_are_forwarded_and_cached_for_a_minute(): void
    {
        $url = self::BEACON_URL.'/config';
        $this->browserHttp->queue($url, 200, '{"replay":{"sample":0.1}}')->queue($url, 404, 'Not found');
        $browser = $this->browser();
        $request = self::server(['REQUEST_URI' => '/_t/c', 'HTTP_USER_AGENT' => 'Mozilla/5.0 Chrome/131.0']);

        $first = $browser->handle($request);
        $this->assertSame([200, '{"replay":{"sample":0.1}}'], [$first?->status, $first->body]);
        $this->assertSame('application/json', $first->header('Content-Type'));
        $this->assertSame('{"replay":{"sample":0.1}}', $browser->handle($request)?->body);
        $this->assertCount(1, $this->browserHttp->to($url));
        $this->assertSame('Bearer test-token', $this->browserHttp->to($url)[0]['headers']['Authorization']);
        $this->assertSame('https://app.test', $this->browserHttp->to($url)[0]['headers']['X-Watchtower-Origin']);

        // A Watch Tower without session replay answers 404: the script does not record.
        $this->now += Browser::REPLAY_CONFIG_TTL_SECONDS + 1;
        $this->assertSame('{"replay":null}', $browser->handle($request)?->body);
    }

    public function test_replay_chunks_are_forwarded_and_refusals_passed_on(): void
    {
        $url = self::BEACON_URL.'/replay';
        $this->browserHttp->queue($url, 204)->queue($url, 403, 'Session replay is off')->queue($url, 0);
        $browser = $this->browser();
        $chunk = (string) gzencode('{"session":"abc123","seq":0,"events":[]}');
        $request = self::beacon(['REQUEST_URI' => '/_t/r']);

        $this->assertSame(204, $browser->handle($request, static fn (): string => $chunk)?->status);
        $this->assertSame(403, $browser->handle($request, static fn (): string => $chunk)?->status);
        $this->assertSame(204, $browser->handle($request, static fn (): string => $chunk)?->status, 'Watch Tower down');

        $sent = $this->browserHttp->to($url);
        $this->assertCount(3, $sent);
        $this->assertSame($chunk, $sent[0]['body']);
        $this->assertSame('Bearer test-token', $sent[0]['headers']['Authorization']);
        $this->assertSame('198.51.100.23', $sent[0]['headers']['X-Watchtower-Client-Ip']);

        $this->assertSame(413, $browser->handle(self::beacon(['REQUEST_URI' => '/_t/r', 'CONTENT_LENGTH' => (string) (Browser::MAX_REPLAY_BYTES + 1)]), static fn (): string => 'x')?->status);
        $this->assertCount(3, $this->browserHttp->to($url));
    }

    public function test_other_requests_are_not_handled(): void
    {
        $browser = $this->browser();

        foreach ([
            ['REQUEST_URI' => '/'],
            ['REQUEST_URI' => '/_t/s.js', 'REQUEST_METHOD' => 'POST'],
            ['REQUEST_URI' => '/_t/e', 'REQUEST_METHOD' => 'GET'],
            ['REQUEST_URI' => '/_t/e/extra', 'REQUEST_METHOD' => 'POST'],
            ['REQUEST_URI' => '/blog/_t/s.js'],
            ['REQUEST_URI' => '/_t/other.js'],
        ] as $server) {
            $this->assertNull($browser->handle(self::server($server), static fn (): string => '{}'), json_encode($server));
        }

        $this->assertNull($this->browser(['browser_key' => null])->handle(self::beacon(), static fn (): string => '{}'));
        $this->assertNull($this->browser(['browser_proxy' => false])->handle(self::beacon(), static fn (): string => '{}'));
        $this->assertSame([], $this->browserHttp->requests);
    }

    public function test_handle_browser_proxy_does_nothing_for_other_requests(): void
    {
        $this->assertFalse(WatchTower::handleBrowserProxy(false), 'no-op before init()');

        $_SERVER['REQUEST_URI'] = '/about';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        try {
            WatchTower::init(['token' => 't', 'base_url' => 'https://watchtower.test', 'browser_key' => 'k', 'spool_dir' => $this->spoolDir, 'install_handlers' => false]);
            $this->assertFalse(WatchTower::handleBrowserProxy(false));
        } finally {
            unset($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
        }
    }

    public function test_handle_browser_proxy_answers_and_leaves_the_request_unreported(): void
    {
        $_SERVER['REQUEST_URI'] = '/_t/s.js';
        $_SERVER['REQUEST_METHOD'] = 'GET';

        try {
            $client = WatchTower::init(['token' => 't', 'base_url' => 'https://watchtower.test', 'browser_key' => 'k', 'spool_dir' => $this->spoolDir, 'install_handlers' => false, 'http_client' => $this->http, 'capture' => ['hello' => false]]);
            $client?->startRequest(self::server(['REQUEST_URI' => '/_t/s.js']));
            $client?->useBrowser(new Browser($client->config, $this->browserHttp->queue(self::SCRIPT_URL, 200, '/* wt */')));

            ob_start();
            $handled = WatchTower::handleBrowserProxy(false);
            $output = ob_get_clean();
        } finally {
            unset($_SERVER['REQUEST_URI'], $_SERVER['REQUEST_METHOD']);
        }

        $this->assertTrue($handled);
        $this->assertSame('/* wt */', $output);
        $this->assertTrue($client?->execution()?->ignored);
        $client?->finish(['status_code' => 200]);
        $this->assertSame([], $this->http->requests, 'no request record for the proxy');
    }

    public function test_wordpress_prints_the_tag_in_wp_head(): void
    {
        require_once dirname(__DIR__).'/Fixtures/wordpress-stubs.php';
        $GLOBALS['wt_hooks'] = [];

        try {
            Integration::register($this->browserClient());
            $this->assertArrayHasKey('wp_head', $GLOBALS['wt_hooks']);

            ob_start();
            do_action('wp_head');
            $this->assertSame('<script src="/_t/s.js" data-key="browser-key-123" data-endpoint="/_t/e" data-release="v1.0.0" defer></script>', ob_get_clean());

            $GLOBALS['wt_hooks'] = [];
            Integration::register($this->client());
            $this->assertArrayNotHasKey('wp_head', $GLOBALS['wt_hooks'], 'no hook without a browser key');
        } finally {
            Integration::reset();
            $GLOBALS['wt_hooks'] = [];
        }
    }

    public function test_wt_hello_lists_the_browser_feature(): void
    {
        $client = $this->browserClient(['capture' => ['hello' => true]]);
        $client->startRequest(self::server());
        $client->finish(['status_code' => 200]);

        $this->assertContains('browser', self::one($this->http->records(), 'wt-hello')['features']);
    }
}
