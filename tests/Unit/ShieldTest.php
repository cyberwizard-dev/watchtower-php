<?php

declare(strict_types=1);

namespace WatchTower\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use WatchTower\Client;
use WatchTower\Shield\ApcuCounter;
use WatchTower\Shield\FileCounter;
use WatchTower\Shield\PassCookie;
use WatchTower\Shield\Patterns;
use WatchTower\Shield\Rules;
use WatchTower\Shield\RulesStore;
use WatchTower\Shield\Shield;
use WatchTower\Shield\ShieldResponse;
use WatchTower\Tests\Support\FakeShieldHttp;
use WatchTower\Tests\Support\TestCase;
use WatchTower\Tests\Support\WireFixtures;
use WatchTower\WatchTower;
use WatchTower\WordPress\Integration;

final class ShieldTest extends TestCase
{
    private const RULES_URL = 'https://watchtower.test/api/shield/v1/rules';

    private const SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    private int $now = 1794000000;

    private FakeShieldHttp $shieldHttp;

    /** @var list<array<string, mixed>> */
    private array $records = [];

    /** @var array<string, mixed> Extra client options (e.g. client_ip) for every hit. */
    private array $clientOptions = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->shieldHttp = new FakeShieldHttp;
    }

    /**
     * @param  array<string, mixed>  $shield
     */
    private function shieldClient(array $shield = []): Client
    {
        $client = $this->client([...$this->clientOptions, 'shield' => [
            'enabled' => true,
            'cache_dir' => $this->spoolDir.'/shield',
            'turnstile_secret' => 'turnstile-secret',
            'counters' => 'file',
            'http_client' => $this->shieldHttp,
            'throttle_seconds' => 0,
            ...$shield,
        ]]);
        $client->useShield(new Shield($client->config, $client, fn (): int => $this->now));

        return $client;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function rules(array $overrides = []): array
    {
        return array_replace([
            'version' => 'v1',
            'ttl_seconds' => 60,
            'mode' => 'enforce',
            'allow' => ['ips' => []],
            'block' => ['ips' => [], 'user_agents' => [], 'paths' => [], 'countries' => []],
            'rate_limits' => [],
            'honeypot' => null,
            'challenge' => null,
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function cacheRules(array $overrides = [], ?int $fetchedAt = null): void
    {
        $path = $this->shieldClient()->shield()->rules->path();
        @mkdir(dirname($path), 0775, true);
        file_put_contents($path, json_encode(['rules' => Rules::fromArray(self::rules($overrides))->toArray(), 'fetched_at' => $fetchedAt ?? $this->now]));
    }

    /**
     * One request through a fresh client (like a new PHP worker), keeping its wt-shield records.
     *
     * @param  array<string, mixed>  $server
     * @param  array<array-key, mixed>  $post
     * @param  array<array-key, mixed>  $cookies
     * @param  array<string, mixed>  $shield
     */
    private function hit(array $server = [], array $post = [], array $cookies = [], array $shield = []): ?ShieldResponse
    {
        $client = $this->shieldClient($shield);
        $server = self::server(['REQUEST_URI' => '/', 'HTTP_ACCEPT' => 'text/html', 'HTTP_USER_AGENT' => 'Mozilla/5.0', ...$server]);
        $client->startRequest($server);
        $response = $client->shield()->handle($server, $post, $cookies);

        array_push($this->records, ...self::ofType($client->buffered(), 'wt-shield'));

        return $response;
    }

    private static function loginLimit(array $overrides = []): array
    {
        return array_replace([
            'name' => 'login', 'methods' => ['POST'], 'paths' => ['/login', '/api/login'], 'limit' => 3,
            'window_seconds' => 60, 'by' => 'ip', 'action' => 'block', 'block_seconds' => 0,
        ], $overrides);
    }

    public function test_blocks_a_listed_ipv4_address_with_a_403_and_a_record(): void
    {
        $this->cacheRules(['block' => ['ips' => [['cidr' => '198.51.100.7/32', 'reason' => 'suspicious score 92', 'expires_at' => null]]]]);

        $response = $this->hit(['REMOTE_ADDR' => '198.51.100.7', 'REQUEST_URI' => '/pricing?ref=x', 'HTTP_USER_AGENT' => 'Mozilla/5.0 (Bad)']);

        $this->assertSame(403, $response?->status);
        $this->assertSame('Forbidden.', $response->body);
        $this->assertSame('text/plain; charset=UTF-8', $response->header('Content-Type'));
        $this->assertCount(1, $this->records);
        WireFixtures::assertShape($this->records[0]);
        $this->assertSame([
            'action' => 'blocked', 'rule' => 'ip:198.51.100.7/32', 'ip' => '198.51.100.7', 'method' => 'GET', 'path' => '/pricing',
            'user_agent' => 'Mozilla/5.0 (Bad)', 'user_id' => null, 'rules_version' => 'v1',
        ], array_intersect_key($this->records[0], array_flip(['action', 'rule', 'ip', 'method', 'path', 'user_agent', 'user_id', 'rules_version'])));

        $this->assertNull($this->hit(['REMOTE_ADDR' => '198.51.100.8']));
    }

    public function test_a_spoofed_forwarded_header_cannot_dodge_an_ip_block(): void
    {
        $this->clientOptions = ['client_ip' => ['header' => 'X-Forwarded-For', 'trusted_proxies' => 'private']];
        $this->cacheRules(['block' => ['ips' => [['cidr' => '198.51.100.7/32', 'reason' => null, 'expires_at' => null]]]]);

        // Direct (untrusted) peer claiming another address: still blocked by its REMOTE_ADDR.
        $this->assertSame(403, $this->hit(['REMOTE_ADDR' => '198.51.100.7', 'HTTP_X_FORWARDED_FOR' => '203.0.113.1'])?->status);
        $this->assertSame('198.51.100.7', $this->records[0]['ip']);

        // Through a trusted proxy, prepending a fake hop: the right-most untrusted entry is used.
        $this->assertSame(403, $this->hit(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '203.0.113.1, 198.51.100.7'])?->status);
        $this->assertSame('198.51.100.7', $this->records[1]['ip']);

        // A spoofer can't get blocked IPs' traffic attributed to them either.
        $this->assertNull($this->hit(['REMOTE_ADDR' => '203.0.113.20', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7']));
        $this->assertNull($this->hit(['REMOTE_ADDR' => '10.0.0.5', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 203.0.113.20']));
    }

    public function test_rate_limits_and_facts_use_the_resolved_ip(): void
    {
        $this->clientOptions = ['client_ip' => ['preset' => 'cloudflare']];
        $this->cacheRules(['rate_limits' => [self::loginLimit(['limit' => 1])]]);
        $login = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/login'];

        $this->assertNull($this->hit([...$login, 'REMOTE_ADDR' => '198.51.100.30', 'HTTP_CF_CONNECTING_IP' => '203.0.113.1']));
        // Rotating CF-Connecting-IP from outside Cloudflare doesn't reset the counter.
        $this->assertSame(429, $this->hit([...$login, 'REMOTE_ADDR' => '198.51.100.30', 'HTTP_CF_CONNECTING_IP' => '203.0.113.2'])?->status);
        // Behind Cloudflare each visitor has their own counter.
        $this->assertNull($this->hit([...$login, 'REMOTE_ADDR' => '172.64.0.9', 'HTTP_CF_CONNECTING_IP' => '203.0.113.3']));
        $this->assertNull($this->hit([...$login, 'REMOTE_ADDR' => '172.64.0.9', 'HTTP_CF_CONNECTING_IP' => '203.0.113.4']));

        $shield = $this->shieldClient()->shield();
        $this->assertSame('203.0.113.5', $shield->facts(['REMOTE_ADDR' => '104.16.0.1', 'HTTP_CF_CONNECTING_IP' => '203.0.113.5'])['ip'], 'no execution yet: same resolver');
        $this->assertSame('198.51.100.1', $shield->facts(['REMOTE_ADDR' => '198.51.100.1', 'HTTP_CF_CONNECTING_IP' => '203.0.113.5'])['ip']);
    }

    public function test_matches_ipv6_ranges_and_ipv4_mapped_addresses(): void
    {
        $this->cacheRules(['block' => ['ips' => [
            ['cidr' => '2001:db8::/32', 'reason' => null, 'expires_at' => null],
            ['cidr' => '203.0.113.0/25', 'reason' => null, 'expires_at' => null],
        ]]]);

        $this->assertSame(403, $this->hit(['REMOTE_ADDR' => '2001:db8:85a3::8a2e:370:7334'])?->status);
        $this->assertNull($this->hit(['REMOTE_ADDR' => '2001:db9::1']));
        $this->assertSame(403, $this->hit(['REMOTE_ADDR' => '::ffff:203.0.113.100'])?->status);
        $this->assertNull($this->hit(['REMOTE_ADDR' => '203.0.113.200']));
        $this->assertFalse(Patterns::ipInRange('10.0.0.1', '10.0.0.0/33'));
        $this->assertFalse(Patterns::ipInRange('10.0.0.1', 'nonsense/8'));
        $this->assertFalse(Patterns::ipInRange('garbage', '10.0.0.0/8'));
        $this->assertFalse(Patterns::ipInRange('10.0.0.1', '2001:db8::/32'));
        $this->assertTrue(Patterns::ipInRange('10.1.2.3', '10.0.0.0/8'));
        $this->assertTrue(Patterns::ipInRange('10.1.2.3', '10.1.2.3'));
        $this->assertTrue(Patterns::ipInRange('192.168.1.130', '192.168.1.128/26'));
        $this->assertFalse(Patterns::ipInRange('192.168.1.200', '192.168.1.128/26'));
    }

    public function test_the_allow_list_wins_over_every_block(): void
    {
        $this->cacheRules([
            'allow' => ['ips' => ['203.0.113.0/24', '2001:db8::/32']],
            'block' => ['ips' => [['cidr' => '203.0.113.7/32', 'reason' => null, 'expires_at' => null]], 'user_agents' => ['(?i)sqlmap'], 'paths' => ['/.env']],
            'rate_limits' => [self::loginLimit(['limit' => 1])],
            'honeypot' => ['field' => 'wt_hp_website', 'action' => 'block'],
        ]);

        $this->assertNull($this->hit(['REMOTE_ADDR' => '203.0.113.7', 'HTTP_USER_AGENT' => 'sqlmap/1.7', 'REQUEST_URI' => '/.env']));
        $this->assertNull($this->hit(['REMOTE_ADDR' => '2001:db8::1', 'REQUEST_URI' => '/.env']));

        foreach (range(1, 3) as $attempt) {
            $this->assertNull($this->hit(['REMOTE_ADDR' => '203.0.113.7', 'REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/login'], ['wt_hp_website' => 'spam']));
        }

        $this->assertSame([], $this->records);
    }

    public function test_expired_ip_blocks_are_ignored(): void
    {
        $this->cacheRules(['block' => ['ips' => [
            ['cidr' => '198.51.100.7/32', 'reason' => null, 'expires_at' => $this->now - 1],
            ['cidr' => '198.51.100.8/32', 'reason' => null, 'expires_at' => $this->now + 60],
        ]]]);

        $this->assertNull($this->hit(['REMOTE_ADDR' => '198.51.100.7']));
        $this->assertSame(403, $this->hit(['REMOTE_ADDR' => '198.51.100.8'])?->status);
    }

    public function test_user_agents_by_expression_with_invalid_expressions_skipped(): void
    {
        $this->cacheRules(['block' => ['user_agents' => ['(unclosed', '[z-a]', '(?i)sqlmap|nikto|masscan|zgrab', 'a~b#c%d!e@f`g;h|i']]]);

        $this->assertSame(403, $this->hit(['HTTP_USER_AGENT' => 'SQLMap/1.7 (https://sqlmap.org)'])?->status);
        $this->assertNull($this->hit(['HTTP_USER_AGENT' => 'Mozilla/5.0 Firefox']));
        $this->assertSame(403, $this->hit(['HTTP_USER_AGENT' => "nikto\xC3\x28"])?->status);
        $this->assertSame(['ua', 'ua'], array_column($this->records, 'rule'));

        $this->assertFalse(Patterns::regexMatches('(a+)+$', str_repeat('a', 40).'b'));
        $this->assertFalse(Patterns::regexMatches('(', 'anything'));
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function paths(): iterable
    {
        yield 'exact' => ['/.env', true];
        yield 'double slash' => ['//.env', true];
        yield 'encoded' => ['/%2Eenv', true];
        yield 'case' => ['/WP-LOGIN.PHP', true];
        yield 'query ignored' => ['/wp-login.php?action=register', true];
        yield 'glob' => ['/.git/config', true];
        yield 'deep glob' => ['/vendor/phpunit/phpunit/src/Util/PHP/eval-stdin.php', true];
        yield 'similar' => ['/.envy', false];
        yield 'nested' => ['/docs/.env.example', false];
        yield 'not git' => ['/git/config', false];
    }

    #[DataProvider('paths')]
    public function test_path_globs(string $uri, bool $blocked): void
    {
        $this->cacheRules(['block' => ['paths' => ['/.env', '/wp-login.php', '/.git/*', '/vendor/phpunit/*']]]);

        $response = $this->hit(['REQUEST_URI' => $uri]);

        $blocked ? $this->assertSame(403, $response?->status) : $this->assertNull($response);
    }

    public function test_json_clients_get_a_json_body(): void
    {
        $this->cacheRules(['block' => ['paths' => ['/api/*']]]);

        $response = $this->hit(['REQUEST_URI' => '/api/users', 'HTTP_ACCEPT' => 'application/json']);

        $this->assertSame(403, $response?->status);
        $this->assertSame('{"message":"Forbidden."}', $response->body);
        $this->assertSame('application/json', $response->header('Content-Type'));
    }

    public function test_rate_limits_per_window(): void
    {
        $this->cacheRules(['rate_limits' => [self::loginLimit()]]);
        $login = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/login', 'REMOTE_ADDR' => '203.0.113.1'];

        foreach (range(1, 3) as $attempt) {
            $this->assertNull($this->hit($login));
        }

        $this->assertNull($this->hit([...$login, 'REQUEST_METHOD' => 'GET']));
        $this->assertNull($this->hit([...$login, 'REQUEST_URI' => '/register']));
        $this->assertNull($this->hit([...$login, 'REMOTE_ADDR' => '203.0.113.2']));

        $this->now += 10;
        $response = $this->hit($login);
        $this->assertSame(429, $response?->status);
        $this->assertSame('Too Many Requests.', $response->body);
        $this->assertSame('50', $response->header('Retry-After'));
        $this->assertCount(1, $this->records);
        WireFixtures::assertShape($this->records[0]);
        $this->assertSame(['rate_limited', 'rate:login', 'POST', '/login'], [$this->records[0]['action'], $this->records[0]['rule'], $this->records[0]['method'], $this->records[0]['path']]);

        $this->now += 51;
        $this->assertNull($this->hit([...$login, 'REQUEST_URI' => '/api/login']));
    }

    public function test_block_seconds_keeps_the_client_out(): void
    {
        $this->cacheRules(['rate_limits' => [self::loginLimit(['limit' => 2, 'block_seconds' => 600])]], $this->now + 100000);
        $login = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/login'];

        $this->assertNull($this->hit($login));
        $this->assertNull($this->hit($login));
        $this->assertSame('600', $this->hit($login)?->header('Retry-After'));

        $this->now += 120;
        $this->assertSame('480', $this->hit($login)?->header('Retry-After'));

        $this->now += 481;
        $this->assertNull($this->hit($login));
    }

    public function test_rate_limits_by_ip_and_path_and_by_user_with_an_ip_fallback(): void
    {
        $this->cacheRules(['rate_limits' => [
            self::loginLimit(['name' => 'paths', 'methods' => [], 'paths' => ['/posts/*'], 'limit' => 1, 'by' => 'ip_path']),
            self::loginLimit(['name' => 'users', 'methods' => ['PUT'], 'paths' => [], 'limit' => 1, 'by' => 'user']),
        ]]);

        $this->assertNull($this->hit(['REQUEST_URI' => '/posts/1']));
        $this->assertNull($this->hit(['REQUEST_URI' => '/posts/2']));
        $this->assertSame(429, $this->hit(['REQUEST_URI' => '/posts/1'])?->status);

        $this->assertNull($this->hit(['REQUEST_METHOD' => 'PUT', 'REQUEST_URI' => '/a', 'REMOTE_ADDR' => '203.0.113.5']));
        $this->assertSame(429, $this->hit(['REQUEST_METHOD' => 'PUT', 'REQUEST_URI' => '/b', 'REMOTE_ADDR' => '203.0.113.5'])?->status);
        $this->assertNull($this->hit(['REQUEST_METHOD' => 'PUT', 'REQUEST_URI' => '/b', 'REMOTE_ADDR' => '203.0.113.6']));

        $this->assertSame(['rate:paths', 'rate:users'], array_column($this->records, 'rule'));
    }

    public function test_honeypot_blocks_filled_form_posts(): void
    {
        $this->cacheRules(['honeypot' => ['field' => 'wt_hp_website', 'action' => 'block']]);
        $post = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/contact'];

        $this->assertNull($this->hit($post, ['message' => 'hi', 'wt_hp_website' => '']));
        $this->assertNull($this->hit($post, ['message' => 'hi', 'wt_hp_website' => '   ']));
        $this->assertNull($this->hit(['REQUEST_URI' => '/contact?wt_hp_website=x'], ['wt_hp_website' => 'x']));
        $this->assertSame(403, $this->hit($post, ['wt_hp_website' => 'http://spam.example'])?->status);

        $this->assertCount(1, $this->records);
        WireFixtures::assertShape($this->records[0]);
        $this->assertSame(['honeypot', 'honeypot'], [$this->records[0]['action'], $this->records[0]['rule']]);
    }

    public function test_honeypot_flag_only_reports(): void
    {
        $this->cacheRules(['honeypot' => ['field' => 'wt_hp_website', 'action' => 'flag']]);

        $this->assertNull($this->hit(['REQUEST_METHOD' => 'POST'], ['wt_hp_website' => 'spam']));
        $this->assertSame(['honeypot'], array_column($this->records, 'action'));
    }

    public function test_monitor_mode_reports_would_block(): void
    {
        $this->cacheRules([
            'mode' => 'monitor',
            'block' => ['ips' => [['cidr' => '198.51.100.7/32', 'reason' => null, 'expires_at' => null]], 'paths' => ['/.env']],
            'rate_limits' => [self::loginLimit(['limit' => 1])],
            'honeypot' => ['field' => 'wt_hp_website', 'action' => 'block'],
        ]);

        $this->assertNull($this->hit(['REMOTE_ADDR' => '198.51.100.7']));
        $this->assertNull($this->hit(['REQUEST_URI' => '/.env']));
        $this->assertNull($this->hit(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/login']));
        $this->assertNull($this->hit(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/login']));
        $this->assertNull($this->hit(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/contact'], ['wt_hp_website' => 'spam']));

        $this->assertSame(['would_block', 'would_block', 'would_block', 'would_block'], array_column($this->records, 'action'));
        $this->assertSame(['ip:198.51.100.7/32', 'path:/.env', 'rate:login', 'honeypot'], array_column($this->records, 'rule'));
    }

    public function test_mode_override_replaces_the_mode(): void
    {
        $this->cacheRules(['mode' => 'monitor', 'block' => ['paths' => ['/.env']]]);
        $this->assertSame(403, $this->hit(['REQUEST_URI' => '/.env'], shield: ['mode_override' => 'enforce'])?->status);

        $this->cacheRules(['mode' => 'enforce', 'block' => ['paths' => ['/.env']]]);
        $this->assertNull($this->hit(['REQUEST_URI' => '/.env'], shield: ['mode_override' => 'monitor']));

        $this->assertSame(['blocked', 'would_block'], array_column($this->records, 'action'));
    }

    private function cacheChallengeRules(): void
    {
        $this->cacheRules([
            'rate_limits' => [self::loginLimit(['name' => 'catalog-burst', 'methods' => ['GET'], 'paths' => ['/catalog*'], 'limit' => 1, 'action' => 'challenge'])],
            'challenge' => ['provider' => 'turnstile', 'site_key' => '0x4AAAAsitekey'],
        ], $this->now + 100000);
    }

    public function test_browsers_get_a_turnstile_interstitial(): void
    {
        $this->cacheChallengeRules();

        $this->assertNull($this->hit(['REQUEST_URI' => '/catalog']));
        $response = $this->hit(['REQUEST_URI' => '/catalog?page=2&q="x"']);

        $this->assertSame(429, $response?->status);
        $this->assertSame('no-store, private', $response->header('Cache-Control'));
        $this->assertStringContainsString('text/html', (string) $response->header('Content-Type'));
        $this->assertStringContainsString('data-sitekey="0x4AAAAsitekey"', $response->body);
        $this->assertStringContainsString('challenges.cloudflare.com/turnstile/v0/api.js', $response->body);
        $this->assertStringContainsString('action="/catalog?page=2&amp;q=&quot;x&quot;"', $response->body);
        $this->assertStringContainsString('name="'.Shield::VERIFY_PARAM.'"', $response->body);
        $this->assertStringNotContainsString('catalog-burst', $response->body);
        $this->assertSame(['challenged'], array_column($this->records, 'action'));
        WireFixtures::assertShape($this->records[0]);
    }

    public function test_non_browser_clients_get_a_429_instead_of_a_challenge(): void
    {
        $this->cacheChallengeRules();

        $this->hit(['REQUEST_URI' => '/catalog', 'HTTP_ACCEPT' => 'application/json']);
        $json = $this->hit(['REQUEST_URI' => '/catalog', 'HTTP_ACCEPT' => 'application/json']);
        $this->assertSame([429, '{"message":"Too Many Requests."}'], [$json?->status, $json?->body]);
        $this->assertNotNull($json->header('Retry-After'));

        $this->assertSame(429, $this->hit(['REQUEST_URI' => '/catalog', 'HTTP_ACCEPT' => '*/*'])?->status);
        $this->assertSame(429, $this->hit(['REQUEST_URI' => '/catalog', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'])?->status);
        $this->assertSame('Too Many Requests.', $this->hit(['REQUEST_URI' => '/catalog'], shield: ['turnstile_secret' => null])?->body);

        $this->assertSame(['rate_limited', 'rate_limited', 'rate_limited', 'rate_limited'], array_column($this->records, 'action'));
    }

    public function test_a_solved_challenge_sets_the_pass_cookie(): void
    {
        $this->cacheChallengeRules();
        $this->shieldHttp->always(self::SITEVERIFY_URL, 200, ['success' => true]);

        $this->hit(['REQUEST_URI' => '/catalog']);
        $this->assertSame(429, $this->hit(['REQUEST_URI' => '/catalog?page=2'])?->status);

        $response = $this->hit(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/catalog?page=2', 'HTTPS' => 'on'], [Shield::VERIFY_PARAM => '1', Shield::TOKEN_PARAM => 'token-123']);

        $this->assertSame(303, $response?->status);
        $this->assertSame('/catalog?page=2', $response->header('Location'));

        $verify = $this->shieldHttp->to(self::SITEVERIFY_URL);
        $this->assertCount(1, $verify);
        parse_str((string) $verify[0]['body'], $form);
        $this->assertSame(['secret' => 'turnstile-secret', 'response' => 'token-123', 'remoteip' => '203.0.113.4'], $form);

        $cookie = (string) $response->header('Set-Cookie');
        $this->assertMatchesRegularExpression('/^wt_shield_pass=(\d+\.[0-9a-f]{64}); Expires=.+ GMT; Max-Age=1800; Path=\/; HttpOnly; SameSite=Lax; Secure$/', $cookie);
        $value = explode(';', substr($cookie, strlen('wt_shield_pass=')))[0];

        $this->assertNull($this->hit(['REQUEST_URI' => '/catalog'], [], [PassCookie::NAME => $value]));
        $this->assertSame(['challenged', 'challenge_passed'], array_column($this->records, 'action'));
        $this->assertSame('challenge', $this->records[1]['rule']);
        WireFixtures::assertShape($this->records[1]);
    }

    public function test_a_failed_challenge_shows_the_page_again(): void
    {
        $this->cacheChallengeRules();
        $this->shieldHttp->always(self::SITEVERIFY_URL, 200, ['success' => false, 'error-codes' => ['invalid-input-response']]);

        $response = $this->hit(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/catalog'], [Shield::VERIFY_PARAM => '1', Shield::TOKEN_PARAM => 'bad']);

        $this->assertSame(403, $response?->status);
        $this->assertNull($response->header('Set-Cookie'));
        $this->assertStringContainsString("didn't succeed", htmlspecialchars_decode($response->body, ENT_QUOTES));

        // No token: Cloudflare is not called. Unreachable Cloudflare: failed.
        $this->assertSame(403, $this->hit(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/catalog'], [Shield::VERIFY_PARAM => '1'])?->status);
        $this->assertCount(1, $this->shieldHttp->to(self::SITEVERIFY_URL));
        $this->shieldHttp->always(self::SITEVERIFY_URL, 0);
        $this->assertSame(403, $this->hit(['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/catalog'], [Shield::VERIFY_PARAM => '1', Shield::TOKEN_PARAM => 't'])?->status);

        $this->assertSame(['challenge_failed', 'challenge_failed', 'challenge_failed'], array_column($this->records, 'action'));
    }

    public function test_verification_attempts_are_throttled(): void
    {
        $this->cacheChallengeRules();
        $this->shieldHttp->always(self::SITEVERIFY_URL, 200, ['success' => false]);
        $verify = ['REQUEST_METHOD' => 'POST', 'REQUEST_URI' => '/catalog'];

        foreach (range(1, Shield::VERIFY_ATTEMPTS_PER_MINUTE) as $attempt) {
            $this->assertSame(403, $this->hit($verify, [Shield::VERIFY_PARAM => '1', Shield::TOKEN_PARAM => 't'])?->status);
        }

        $this->assertSame(429, $this->hit($verify, [Shield::VERIFY_PARAM => '1', Shield::TOKEN_PARAM => 't'])?->status);
        $this->assertCount(Shield::VERIFY_ATTEMPTS_PER_MINUTE, $this->shieldHttp->to(self::SITEVERIFY_URL));
    }

    public function test_forged_expired_or_moved_pass_cookies_are_refused(): void
    {
        $this->cacheChallengeRules();
        $this->hit(['REQUEST_URI' => '/catalog']);

        $pass = new PassCookie($this->shieldClient()->config);
        $expires = $this->now + 1800;
        $valid = $expires.'.'.$pass->signature($expires, '203.0.113.4');

        $this->assertSame(429, $this->hit(['REQUEST_URI' => '/catalog'], [], [PassCookie::NAME => ($expires + 3600).'.'.$pass->signature($expires, '203.0.113.4')])?->status);
        $this->assertSame(429, $this->hit(['REQUEST_URI' => '/catalog'], [], [PassCookie::NAME => 'garbage'])?->status);
        $this->hit(['REQUEST_URI' => '/catalog', 'REMOTE_ADDR' => '198.51.100.20']);
        $this->assertSame(429, $this->hit(['REQUEST_URI' => '/catalog', 'REMOTE_ADDR' => '198.51.100.20'], [], [PassCookie::NAME => $valid])?->status);
        $this->assertNull($this->hit(['REQUEST_URI' => '/catalog'], [], [PassCookie::NAME => $valid]));

        $this->now += 1801;
        $this->hit(['REQUEST_URI' => '/catalog']);
        $this->assertSame(429, $this->hit(['REQUEST_URI' => '/catalog'], [], [PassCookie::NAME => $valid])?->status);
    }

    public function test_first_request_without_a_copy_is_allowed_and_refreshes_after_the_response(): void
    {
        $this->shieldHttp->always(self::RULES_URL, 200, self::rules(['version' => 'v2', 'block' => ['paths' => ['/.env']]]));
        $client = $this->shieldClient();
        $server = self::server(['REQUEST_URI' => '/.env']);
        $client->startRequest($server);

        $this->assertNull($client->shield()->handle($server));
        $this->assertSame([], $this->shieldHttp->requests, 'Rules are never fetched while the request waits');

        $client->shield()->afterResponse();

        $this->assertCount(1, $this->shieldHttp->requests);
        $request = $this->shieldHttp->requests[0];
        $this->assertSame(['GET', 'Bearer test-token'], [$request['method'], $request['headers']['Authorization']]);
        $this->assertArrayNotHasKey('If-None-Match', $request['headers']);
        $this->assertSame([1.0, 2.0], [$request['connect_timeout'], $request['timeout']]);

        $this->assertSame(403, $this->hit(['REQUEST_URI' => '/.env'])?->status);
        $this->assertSame('v2', $this->records[0]['rules_version']);
        $this->assertSame([], glob($this->spoolDir.'/shield/*.tmp') ?: [], 'The cache is written atomically');
    }

    public function test_watch_tower_down_without_a_copy_fails_open_and_retries_sparingly(): void
    {
        $client = $this->shieldClient();

        $this->assertNull($this->hit(['REQUEST_URI' => '/.env']));
        $client->shield()->afterResponse();
        $this->shieldClient()->shield()->afterResponse();

        $this->assertCount(1, $this->shieldHttp->requests);
        $this->assertNull($this->shieldClient()->shield()->rules->current());

        $this->now += RulesStore::REFRESH_EVERY_SECONDS + 1;
        $this->shieldClient()->shield()->afterResponse();
        $this->assertCount(2, $this->shieldHttp->requests);
    }

    public function test_the_last_good_copy_survives_failures(): void
    {
        $this->cacheRules(['block' => ['paths' => ['/.env']]], $this->now - 3600);
        $this->shieldHttp->always(self::RULES_URL, 502, 'Bad gateway');

        $store = $this->shieldClient()->shield()->rules;
        $this->assertTrue($store->stale());
        $this->assertSame('failed', $store->refresh());
        $this->assertSame(403, $this->hit(['REQUEST_URI' => '/.env'])?->status);
    }

    public function test_304_keeps_the_copy_and_renews_it(): void
    {
        $this->cacheRules(['version' => 'abc123', 'block' => ['paths' => ['/.env']]], $this->now - 120);
        $this->shieldHttp->always(self::RULES_URL, 304);

        $store = $this->shieldClient()->shield()->rules;
        $this->assertTrue($store->stale());
        $this->assertSame('not_modified', $store->refresh());
        $this->assertSame('"abc123"', $this->shieldHttp->requests[0]['headers']['If-None-Match']);
        $this->assertFalse($this->shieldClient()->shield()->rules->stale());
        $this->assertSame('abc123', $this->shieldClient()->shield()->rules->current()?->version);
        $this->assertSame(403, $this->hit(['REQUEST_URI' => '/.env'])?->status);
    }

    public function test_200_replaces_the_copy_and_honours_its_ttl(): void
    {
        $this->shieldHttp->always(self::RULES_URL, 200, self::rules(['version' => 'v9', 'ttl_seconds' => 30]));

        $this->assertSame('updated', $this->shieldClient()->shield()->rules->refresh());
        $this->assertFalse($this->shieldClient()->shield()->rules->stale());

        $this->now += 31;
        $this->assertTrue($this->shieldClient()->shield()->rules->stale());
    }

    public function test_malformed_rules_are_dropped_one_by_one(): void
    {
        $rules = Rules::fromArray([
            'version' => 'v1',
            'mode' => 'something-else',
            'block' => ['ips' => ['198.51.100.1', ['cidr' => ''], ['reason' => 'no cidr']], 'user_agents' => [null, '', 'ok'], 'paths' => 'not-a-list'],
            'rate_limits' => [['name' => 'x', 'limit' => 0, 'window_seconds' => 60], ['name' => 'y', 'limit' => 5, 'window_seconds' => 10, 'by' => 'weird']],
            'honeypot' => ['field' => ''],
            'challenge' => ['provider' => 'recaptcha', 'site_key' => 'k'],
        ]);

        $this->assertSame('monitor', $rules->mode);
        $this->assertSame(['198.51.100.1'], array_column($rules->blockIps, 'cidr'));
        $this->assertSame(['ok'], $rules->userAgents);
        $this->assertSame([], $rules->paths);
        $this->assertSame(['y'], array_column($rules->rateLimits, 'name'));
        $this->assertSame('ip', $rules->rateLimits[0]['by']);
        $this->assertNull($rules->honeypot);
        $this->assertNull($rules->challenge);
    }

    public function test_disabled_shield_does_nothing(): void
    {
        $this->cacheRules(['block' => ['paths' => ['/.env']]]);

        $this->assertNull($this->hit(['REQUEST_URI' => '/.env'], shield: ['enabled' => false]));
        $client = $this->shieldClient(['enabled' => false]);
        $client->shield()->afterResponse();

        $this->assertSame([], $this->shieldHttp->requests);
        $this->assertSame('', $client->shield()->honeypotField());
    }

    public function test_honeypot_field_helper(): void
    {
        $this->cacheRules(['honeypot' => ['field' => 'wt_hp_company', 'action' => 'block']]);

        WatchTower::init([
            'token' => 'test-token',
            'base_url' => 'https://watchtower.test',
            'spool_dir' => $this->spoolDir,
            'install_handlers' => false,
            'capture' => ['hello' => false, 'commands' => false],
            'shield' => ['enabled' => true, 'cache_dir' => $this->spoolDir.'/shield', 'http_client' => $this->shieldHttp],
        ]);

        $html = WatchTower::honeypotField();

        $this->assertStringContainsString('name="wt_hp_company"', $html);
        $this->assertStringContainsString('tabindex="-1"', $html);
        $this->assertStringContainsString('autocomplete="off"', $html);
        $this->assertStringContainsString('aria-hidden="true"', $html);
        $this->assertStringContainsString('left:-10000px', $html);

        WatchTower::reset();
        $this->assertSame('', WatchTower::honeypotField());
    }

    public function test_hello_lists_shield_when_enabled(): void
    {
        $client = $this->client(['capture' => ['hello' => true], 'shield' => ['enabled' => true, 'cache_dir' => $this->spoolDir.'/shield', 'http_client' => $this->shieldHttp]]);
        $client->startRequest(self::server());
        $client->finish(['status_code' => 200]);

        $this->assertContains('shield', self::one($this->http->records(), 'wt-hello')['features']);
    }

    public function test_wordpress_forms_get_the_honeypot(): void
    {
        require_once dirname(__DIR__).'/Fixtures/wordpress-stubs.php';
        $GLOBALS['wt_hooks'] = [];
        $GLOBALS['wt_current_user'] = (object) ['ID' => 0];

        try {
            $client = $this->shieldClient();
            $client->startRequest(self::server(['REQUEST_URI' => '/wp-login.php']));
            Integration::register($client);

            foreach (Integration::HONEYPOT_FORMS as $hook) {
                ob_start();
                do_action($hook);
                $this->assertStringContainsString('name="wt_hp_website"', (string) ob_get_clean(), $hook);
            }

            Integration::reset();
            $GLOBALS['wt_hooks'] = [];
            Integration::register($this->shieldClient(['enabled' => false]));

            ob_start();
            do_action('comment_form');
            $this->assertSame('', ob_get_clean());
        } finally {
            Integration::reset();
            $GLOBALS['wt_hooks'] = [];
        }
    }

    public function test_file_counters_are_safe_under_concurrency(): void
    {
        $directory = $this->spoolDir.'/counters';
        $workers = 6;
        $hits = 200;
        $processes = [];

        foreach (range(1, $workers) as $worker) {
            $processes[] = proc_open(
                [PHP_BINARY, dirname(__DIR__).'/Fixtures/scripts/shield-counter.php', $directory, (string) $hits],
                [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
                $pipes,
            );
        }

        foreach ($processes as $process) {
            while (proc_get_status($process)['running']) {
                usleep(10_000);
            }

            proc_close($process);
        }

        $this->assertSame($workers * $hits, (new FileCounter($directory))->count('concurrency'));
    }

    public function test_apcu_counters(): void
    {
        if (! ApcuCounter::available()) {
            $this->markTestSkipped('APCu is not enabled for the CLI.');
        }

        $counter = new ApcuCounter;
        $key = 'test-'.bin2hex(random_bytes(4));

        $this->assertNull($counter->attempt($key, 2, 60, 0, $this->now));
        $this->assertNull($counter->attempt($key, 2, 60, 0, $this->now));
        $this->assertSame(60, $counter->attempt($key, 2, 60, 0, $this->now));
        $this->assertSame(300, $counter->attempt($key.'-b', 0, 60, 300, $this->now));
        $this->assertSame(300, $counter->attempt($key.'-b', 0, 60, 300, $this->now));
    }

    /**
     * One request through a fresh client that is finished (delivered) afterwards.
     *
     * @param  array<string, mixed>  $server
     * @param  array<string, mixed>  $shield
     */
    private function deliveredHit(array $server, array $shield = []): ?ShieldResponse
    {
        $client = $this->shieldClient($shield);
        $server = self::server(['REQUEST_URI' => '/', 'HTTP_ACCEPT' => 'text/html', 'HTTP_USER_AGENT' => 'Mozilla/5.0', ...$server]);
        $client->startRequest($server);
        $response = $client->shield()->handle($server);
        $client->finish(['status_code' => $response?->status ?? 200]);

        return $response;
    }

    public function test_keeps_decisions_and_the_blocked_request_of_unsampled_executions(): void
    {
        $this->clientOptions = ['sample_rate' => 0.0];
        $this->cacheRules(['block' => ['ips' => [['cidr' => '198.51.100.7/32', 'reason' => null, 'expires_at' => null]]]]);

        $this->assertSame(403, $this->deliveredHit(['REMOTE_ADDR' => '198.51.100.7'])?->status);

        $records = $this->http->records();
        $this->assertSame('blocked', self::one($records, 'wt-shield')['action']);
        WireFixtures::assertShape(self::one($records, 'wt-shield'));
        $this->assertSame(403, self::one($records, 'request')['status_code']);
        $this->assertSame(self::one($records, 'request')['trace_id'], self::one($records, 'wt-shield')['trace_id']);

        // An allowed request of an unsampled execution is still dropped.
        $requests = count($this->http->requests);
        $this->assertNull($this->deliveredHit(['REMOTE_ADDR' => '198.51.100.8']));
        $this->assertCount($requests, $this->http->requests);
    }

    public function test_keeps_monitor_mode_decisions_of_unsampled_executions(): void
    {
        $this->clientOptions = ['sample_rate' => 0.0];
        $this->cacheRules(['mode' => 'monitor', 'block' => ['ips' => [['cidr' => '198.51.100.7/32', 'reason' => null, 'expires_at' => null]]]]);

        $this->assertNull($this->deliveredHit(['REMOTE_ADDR' => '198.51.100.7']));

        $this->assertSame('would_block', self::one($this->http->records(), 'wt-shield')['action']);
        $this->assertCount(1, self::ofType($this->http->records(), 'request'));
    }

    public function test_throttles_repeats_of_the_same_action_rule_and_ip(): void
    {
        $this->clientOptions = ['sample_rate' => 0.0];
        $this->cacheRules(['block' => ['ips' => [['cidr' => '198.51.100.0/24', 'reason' => null, 'expires_at' => null]]]]);
        $shield = ['throttle_seconds' => 10];

        foreach (range(1, 5) as $attempt) {
            $this->assertSame(403, $this->deliveredHit(['REMOTE_ADDR' => '198.51.100.7'], $shield)?->status);
        }

        $this->deliveredHit(['REMOTE_ADDR' => '198.51.100.8'], $shield);

        // Throttled decisions aren't recorded and don't keep their unsampled execution.
        $this->assertSame(['198.51.100.7', '198.51.100.8'], array_column(self::ofType($this->http->records(), 'wt-shield'), 'ip'));
        $this->assertCount(2, self::ofType($this->http->records(), 'request'));

        $this->now += 9;
        $this->deliveredHit(['REMOTE_ADDR' => '198.51.100.7'], $shield);
        $this->assertCount(2, self::ofType($this->http->records(), 'wt-shield'));

        $this->now += 2;
        $this->deliveredHit(['REMOTE_ADDR' => '198.51.100.7'], $shield);
        $this->assertCount(3, self::ofType($this->http->records(), 'wt-shield'));
    }

    public function test_throttles_by_default(): void
    {
        $this->cacheRules(['block' => ['ips' => [['cidr' => '198.51.100.7/32', 'reason' => null, 'expires_at' => null]]]]);

        $this->assertSame(10, $this->client()->config->shield['throttle_seconds']);

        // null drops the suite's throttle_seconds => 0 so the default applies.
        $this->hit(['REMOTE_ADDR' => '198.51.100.7'], shield: ['throttle_seconds' => null]);
        $this->hit(['REMOTE_ADDR' => '198.51.100.7'], shield: ['throttle_seconds' => null]);

        $this->assertCount(1, $this->records);
    }
}
