<?php

declare(strict_types=1);

namespace WatchTower\Tests\Integration;

use PHPUnit\Framework\Attributes\Depends;
use WatchTower\Tests\Support\BuiltInServer;
use WatchTower\Tests\Support\FakeHttpClient;
use WatchTower\Tests\Support\TestCase;
use WatchTower\Tests\Support\WireFixtures;

/**
 * Shield in a real `php -S` app: rules pulled from a stand-in Watch Tower after the first
 * response, then enforced inside WatchTower::init() before the application runs.
 */
final class ShieldWebTest extends TestCase
{
    private static BuiltInServer $watchTower;

    private static BuiltInServer $app;

    private static string $appSpool;

    private static string $shieldDir;

    public static function setUpBeforeClass(): void
    {
        self::$watchTower = new BuiltInServer;
        file_put_contents(self::$watchTower->captureDir.'/shield-rules.json', json_encode([
            'version' => 'web-v1',
            'ttl_seconds' => 300,
            'mode' => 'enforce',
            'allow' => ['ips' => []],
            'block' => ['ips' => [], 'user_agents' => ['(?i)sqlmap'], 'paths' => ['/.env', '/.git/*'], 'countries' => []],
            'rate_limits' => [],
            'honeypot' => ['field' => 'wt_hp_website', 'action' => 'block'],
            'challenge' => null,
        ]));

        self::$appSpool = sys_get_temp_dir().'/watchtower-shield-app-'.bin2hex(random_bytes(6));
        self::$shieldDir = self::$appSpool.'/shield';
        self::$app = new BuiltInServer(dirname(__DIR__).'/Fixtures/app/router.php', [
            'WT_URL' => self::$watchTower->url,
            'WT_MODE' => 'auto',
            'WT_SPOOL_DIR' => self::$appSpool,
            'WT_SHIELD_DIR' => self::$shieldDir,
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
     * @return array{status: int, body: string}
     */
    private static function request(string $path, array $headers = [], ?array $post = null): array
    {
        $handle = curl_init(self::$app->url.$path);
        curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers]);

        if ($post !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($post));
        }

        $body = (string) curl_exec($handle);

        return ['status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $body];
    }

    /**
     * @return list<string>
     */
    private static function rulePulls(): array
    {
        return glob(self::$watchTower->captureDir.'/shield-request-*.json') ?: [];
    }

    private static function waitFor(callable $condition): void
    {
        $deadline = microtime(true) + 5;

        while (! $condition() && microtime(true) < $deadline) {
            usleep(20_000);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function recordsAfter(int $before): array
    {
        self::waitFor(static fn (): bool => count(self::$watchTower->requests()) > $before);

        $records = [];

        foreach (array_slice(self::$watchTower->requests(), $before) as $request) {
            array_push($records, ...FakeHttpClient::decode($request['body']));
        }

        return $records;
    }

    public function test_rules_are_pulled_after_the_first_response_then_enforced_before_the_app_runs(): void
    {
        // No copy yet: the first request reaches the app.
        $first = self::request('/.env');
        $this->assertSame([404, 'missing'], [$first['status'], $first['body']]);

        self::waitFor(static fn (): bool => self::rulePulls() !== [] && glob(self::$shieldDir.'/shield-rules-*.json') !== []);
        $pull = json_decode((string) file_get_contents(self::rulePulls()[0]), true);
        $this->assertSame('Bearer script-token', $pull['headers']['authorization']);

        $before = count(self::$watchTower->requests());
        $blocked = self::request('/.env', ['User-Agent: curl/8']);

        $this->assertSame([403, 'Forbidden.'], [$blocked['status'], $blocked['body']]);

        $records = self::recordsAfter($before);
        $shield = self::one($records, 'wt-shield');
        WireFixtures::assertShape($shield);
        $this->assertSame(['blocked', 'path:/.env', '127.0.0.1', 'GET', '/.env', 'curl/8', 'web-v1'], [
            $shield['action'], $shield['rule'], $shield['ip'], $shield['method'], $shield['path'], $shield['user_agent'], $shield['rules_version'],
        ]);

        // Reported with the request it belongs to.
        $request = self::one($records, 'request');
        $this->assertSame(403, $request['status_code']);
        $this->assertSame($request['trace_id'], $shield['trace_id']);

        $this->assertSame(403, self::request('/.git/config')['status']);
        $this->assertSame(403, self::request('/', ['User-Agent: sqlmap/1.7'])['status']);
        $this->assertSame(404, self::request('/somewhere')['status']);
    }

    #[Depends('test_rules_are_pulled_after_the_first_response_then_enforced_before_the_app_runs')]
    public function test_honeypot_posts_are_blocked_and_the_field_is_rendered(): void
    {
        $form = self::request('/form');
        $this->assertStringContainsString('name="wt_hp_website"', $form['body']);
        $this->assertStringContainsString('tabindex="-1"', $form['body']);

        $this->assertSame(403, self::request('/contact', [], ['message' => 'buy', 'wt_hp_website' => 'http://spam.example'])['status']);
        $this->assertSame(404, self::request('/contact', [], ['message' => 'hi', 'wt_hp_website' => ''])['status']);
        $this->assertSame('{"message":"Forbidden."}', self::request('/.env', ['Accept: application/json'])['body']);
    }
}
