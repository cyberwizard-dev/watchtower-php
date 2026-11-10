<?php

declare(strict_types=1);

namespace WatchTower\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use WatchTower\Config;
use WatchTower\Support\ClientIp;
use WatchTower\Tests\Support\TestCase;
use WatchTower\WordPress\Integration;

final class ClientIpTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $clientIp
     * @param  array<string, mixed>  $server
     */
    private static function resolve(array $clientIp, array $server, ?string $legacyHeader = null): string
    {
        return ClientIp::fromOptions($clientIp, $legacyHeader)->resolve($server);
    }

    public function test_without_a_header_only_remote_addr_counts(): void
    {
        $this->assertSame('203.0.113.4', self::resolve([], ['REMOTE_ADDR' => '203.0.113.4', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4']));
        $this->assertSame('', self::resolve([], []));
    }

    public function test_a_spoofed_header_from_an_untrusted_peer_is_ignored(): void
    {
        $options = ['header' => 'X-Forwarded-For', 'trusted_proxies' => ['10.0.0.0/8']];

        $this->assertSame('198.51.100.9', self::resolve($options, ['REMOTE_ADDR' => '198.51.100.9', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4']));
        $this->assertSame('198.51.100.9', self::resolve(['header' => 'CF-Connecting-IP'], ['REMOTE_ADDR' => '198.51.100.9', 'HTTP_CF_CONNECTING_IP' => '1.2.3.4']));
    }

    public function test_x_forwarded_for_is_walked_right_to_left_past_trusted_hops(): void
    {
        $options = ['header' => 'X-Forwarded-For', 'trusted_proxies' => 'private'];

        // Spoofed left-most entry, real client, then two internal hops.
        $server = ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 203.0.113.50, 172.16.4.1, 10.0.0.9'];
        $this->assertSame('203.0.113.50', self::resolve($options, $server));

        // Every hop trusted: the left-most one.
        $this->assertSame('192.168.1.20', self::resolve($options, ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '192.168.1.20, 10.0.0.9']));

        // Garbage entries are skipped; nothing valid falls back to the peer.
        $this->assertSame('203.0.113.50', self::resolve($options, ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => '203.0.113.50, unknown, ,_hidden']));
        $this->assertSame('10.0.0.2', self::resolve($options, ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_X_FORWARDED_FOR' => 'unknown, nonsense']));
        $this->assertSame('10.0.0.2', self::resolve($options, ['REMOTE_ADDR' => '10.0.0.2']));
    }

    /**
     * @return array<string, array{0: string, 1: ?string}>
     */
    public static function entries(): array
    {
        return [
            'ipv4' => ['203.0.113.7', '203.0.113.7'],
            'ipv4 with port' => ['203.0.113.7:8080', '203.0.113.7'],
            'bare ipv6' => ['2001:db8::1', '2001:db8::1'],
            'bracketed ipv6' => ['[2001:db8::1]', '2001:db8::1'],
            'bracketed ipv6 with port' => ['[2001:db8::1]:443', '2001:db8::1'],
            'quoted' => ['"[2001:db8:cafe::17]:4711"', '2001:db8:cafe::17'],
            'unknown' => ['unknown', null],
            'obfuscated' => ['_gazonk', null],
            'bad port form' => ['2001:db8::1:443:x', null],
            'out of range' => ['999.1.1.1', null],
        ];
    }

    #[DataProvider('entries')]
    public function test_normalizes_header_entries(string $entry, ?string $expected): void
    {
        $this->assertSame($expected, ClientIp::normalize($entry));
    }

    public function test_ipv6_chains_and_peers(): void
    {
        $options = ['header' => 'X-Forwarded-For', 'trusted_proxies' => 'private'];

        $this->assertSame('2001:db8::1', self::resolve($options, ['REMOTE_ADDR' => 'fd00::1', 'HTTP_X_FORWARDED_FOR' => '[2001:db8::1]:443, fd00::2']));
        $this->assertSame('2001:db8::5', self::resolve($options, ['REMOTE_ADDR' => '::1', 'HTTP_X_FORWARDED_FOR' => '2001:db8::5']));
        $this->assertSame('2001:db8::9', self::resolve($options, ['REMOTE_ADDR' => '2001:db8::9', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4']));
    }

    public function test_rfc_7239_forwarded_header(): void
    {
        $options = ['header' => 'Forwarded', 'trusted_proxies' => 'private'];
        $server = ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_FORWARDED' => 'for=6.6.6.6, for="[2001:db8:cafe::17]:4711";proto=https, for=10.1.1.1;by=10.0.0.2'];

        $this->assertSame('2001:db8:cafe::17', self::resolve($options, $server));
        $this->assertSame('10.0.0.2', self::resolve($options, ['REMOTE_ADDR' => '10.0.0.2', 'HTTP_FORWARDED' => 'for=unknown']));
    }

    public function test_single_value_headers_are_used_as_is_from_a_trusted_peer(): void
    {
        $this->assertSame('203.0.113.8', self::resolve(['header' => 'X-Real-IP'], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_REAL_IP' => '203.0.113.8']));
        $this->assertSame('203.0.113.8', self::resolve(['header' => 'True-Client-IP', 'trusted_proxies' => ['198.51.100.0/24']], ['REMOTE_ADDR' => '198.51.100.3', 'HTTP_TRUE_CLIENT_IP' => '203.0.113.8']));
        $this->assertSame('127.0.0.1', self::resolve(['header' => 'X-Real-IP'], ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_REAL_IP' => '1.2.3.4, 5.6.7.8']));
    }

    public function test_star_trusts_any_peer_but_takes_the_right_most_forwarded_entry(): void
    {
        $options = ['header' => 'X-Forwarded-For', 'trusted_proxies' => '*'];

        $this->assertSame('203.0.113.50', self::resolve($options, ['REMOTE_ADDR' => '198.51.100.1', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 203.0.113.50']));
        $this->assertSame('203.0.113.9', self::resolve(['header' => 'Fly-Client-IP', 'trusted_proxies' => '*'], ['REMOTE_ADDR' => '172.19.0.1', 'HTTP_FLY_CLIENT_IP' => '203.0.113.9']));
    }

    public function test_private_keyword_covers_rfc1918_ula_loopback_and_link_local(): void
    {
        $resolver = ClientIp::fromOptions(['header' => 'X-Real-IP', 'trusted_proxies' => 'private']);

        foreach (['10.1.2.3', '172.31.255.255', '192.168.0.1', '127.0.0.1', '169.254.1.1', '100.64.0.1', '::1', 'fd12::1', 'fe80::1', '::ffff:10.0.0.1'] as $ip) {
            $this->assertTrue($resolver->isTrustedProxy($ip), $ip);
        }

        foreach (['172.32.0.1', '8.8.8.8', '2001:db8::1', '11.0.0.1'] as $ip) {
            $this->assertFalse($resolver->isTrustedProxy($ip), $ip);
        }
    }

    public function test_cloudflare_preset_trusts_only_cloudflare_ranges(): void
    {
        $resolver = ClientIp::fromOptions(['preset' => 'cloudflare']);

        $this->assertSame('CF-Connecting-IP', $resolver->header);
        foreach (['173.245.48.1', '104.16.0.1', '172.71.255.254', '162.159.1.1', '2606:4700::6810:84e5', '2a06:98c7::1'] as $ip) {
            $this->assertTrue($resolver->isTrustedProxy($ip), $ip);
        }
        foreach (['104.32.0.1', '172.63.255.255', '10.0.0.1', '2a06:98d0::1', '1.1.1.1'] as $ip) {
            $this->assertFalse($resolver->isTrustedProxy($ip), $ip);
        }

        $this->assertSame('203.0.113.5', $resolver->resolve(['REMOTE_ADDR' => '172.68.1.1', 'HTTP_CF_CONNECTING_IP' => '203.0.113.5']));
        $this->assertSame('198.51.100.66', $resolver->resolve(['REMOTE_ADDR' => '198.51.100.66', 'HTTP_CF_CONNECTING_IP' => '203.0.113.5']), 'direct-to-origin requests cannot spoof');
    }

    public function test_preset_trusted_proxies_can_be_overridden(): void
    {
        $resolver = ClientIp::fromOptions(['preset' => 'cloudflare', 'trusted_proxies' => ['cloudflare', '10.0.0.0/8']]);

        $this->assertSame('203.0.113.5', $resolver->resolve(['REMOTE_ADDR' => '10.0.0.4', 'HTTP_CF_CONNECTING_IP' => '203.0.113.5']));
        $this->assertSame('203.0.113.5', $resolver->resolve(['REMOTE_ADDR' => '104.16.0.1', 'HTTP_CF_CONNECTING_IP' => '203.0.113.5']));
    }

    /**
     * @return array<string, array{0: string, 1: string, 2: array<string, string>, 3: string}>
     */
    public static function presets(): array
    {
        return [
            'digitalocean' => ['digitalocean', 'do-connecting-ip', ['REMOTE_ADDR' => '162.243.1.1', 'HTTP_DO_CONNECTING_IP' => '203.0.113.1'], '203.0.113.1'],
            'aws_alb' => ['aws_alb', 'X-Forwarded-For', ['REMOTE_ADDR' => '10.0.3.7', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 203.0.113.2'], '203.0.113.2'],
            'aws_alb untrusted peer' => ['aws_alb', 'X-Forwarded-For', ['REMOTE_ADDR' => '198.51.100.2', 'HTTP_X_FORWARDED_FOR' => '203.0.113.2'], '198.51.100.2'],
            'heroku' => ['heroku', 'X-Forwarded-For', ['REMOTE_ADDR' => '10.1.2.3', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 203.0.113.3'], '203.0.113.3'],
            'fly' => ['fly', 'Fly-Client-IP', ['REMOTE_ADDR' => '172.19.0.1', 'HTTP_FLY_CLIENT_IP' => '203.0.113.4'], '203.0.113.4'],
            'vercel' => ['vercel', 'X-Forwarded-For', ['REMOTE_ADDR' => '3.3.3.3', 'HTTP_X_FORWARDED_FOR' => '203.0.113.5'], '203.0.113.5'],
            'nginx' => ['nginx', 'X-Forwarded-For', ['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '203.0.113.6'], '203.0.113.6'],
            'traefik' => ['traefik', 'X-Forwarded-For', ['REMOTE_ADDR' => '172.18.0.2', 'HTTP_X_FORWARDED_FOR' => '6.6.6.6, 203.0.113.7'], '203.0.113.7'],
        ];
    }

    /**
     * @param  array<string, string>  $server
     */
    #[DataProvider('presets')]
    public function test_presets(string $preset, string $header, array $server, string $expected): void
    {
        $resolver = ClientIp::fromOptions(['preset' => strtoupper($preset)]);

        $this->assertSame($header, $resolver->header);
        $this->assertSame($preset, $resolver->preset);
        $this->assertSame($expected, $resolver->resolve($server));
    }

    public function test_unknown_preset_uses_remote_addr(): void
    {
        $this->assertSame('198.51.100.1', self::resolve(['preset' => 'nope'], ['REMOTE_ADDR' => '198.51.100.1', 'HTTP_X_FORWARDED_FOR' => '1.2.3.4']));
    }

    public function test_legacy_trusted_proxy_header_is_honoured_only_from_private_peers(): void
    {
        $server = ['HTTP_X_FORWARDED_FOR' => '6.6.6.6, 203.0.113.9'];

        $this->assertSame('203.0.113.9', self::resolve([], ['REMOTE_ADDR' => '10.0.0.1', ...$server], 'X-Forwarded-For'), 'right-most untrusted, no longer the first entry');
        $this->assertSame('198.51.100.1', self::resolve([], ['REMOTE_ADDR' => '198.51.100.1', ...$server], 'X-Forwarded-For'));
        $this->assertSame('203.0.113.9', self::resolve(['trusted_proxies' => '*'], ['REMOTE_ADDR' => '198.51.100.1', ...$server], 'X-Forwarded-For'));
        $this->assertSame('203.0.113.1', self::resolve(['header' => 'X-Real-IP'], ['REMOTE_ADDR' => '10.0.0.1', 'HTTP_X_REAL_IP' => '203.0.113.1', ...$server], 'X-Forwarded-For'), 'client_ip.header wins');
    }

    public function test_config_builds_the_resolver_and_the_request_record_uses_it(): void
    {
        $config = new Config(['trusted_proxy_header' => 'X-Forwarded-For']);
        $this->assertSame('X-Forwarded-For', $config->trustedProxyHeader);
        $this->assertSame('X-Forwarded-For', $config->clientIp->header);

        $client = $this->client(['client_ip' => ['preset' => 'cloudflare']]);
        $client->startRequest(self::server(['REMOTE_ADDR' => '198.51.100.66', 'HTTP_CF_CONNECTING_IP' => '203.0.113.5']));
        $client->finish(['status_code' => 200]);
        $this->assertSame('198.51.100.66', self::ofType($this->http->records(), 'request')[0]['ip']);

        $client = $this->client(['client_ip' => ['preset' => 'cloudflare']]);
        $client->startRequest(self::server(['REMOTE_ADDR' => '104.16.0.1', 'HTTP_CF_CONNECTING_IP' => '203.0.113.5']));
        $client->finish(['status_code' => 200]);
        $this->assertSame('203.0.113.5', self::ofType($this->http->records(), 'request')[1]['ip']);
    }

    public function test_environment_variables_set_preset_and_trusted_proxies(): void
    {
        putenv('WATCHTOWER_CLIENT_IP_PRESET=aws_alb');
        putenv('WATCHTOWER_TRUSTED_PROXIES=192.0.2.0/24, 10.0.0.0/8');

        try {
            $resolver = (new Config)->clientIp;
        } finally {
            putenv('WATCHTOWER_CLIENT_IP_PRESET');
            putenv('WATCHTOWER_TRUSTED_PROXIES');
        }

        $this->assertSame('aws_alb', $resolver->preset);
        $this->assertSame(['192.0.2.0/24', '10.0.0.0/8'], $resolver->trustedRanges);
    }

    public function test_wordpress_constants_fill_in_client_ip(): void
    {
        $constants = ['WATCHTOWER_CLIENT_IP_PRESET' => 'cloudflare', 'WATCHTOWER_TRUSTED_PROXIES' => ['cloudflare', '10.0.0.0/8']];
        $read = static fn (string $name): mixed => $constants[$name] ?? null;

        $this->assertSame(['preset' => 'cloudflare', 'trusted_proxies' => ['cloudflare', '10.0.0.0/8']], Integration::clientIpOptions([], $read));
        $this->assertSame(
            ['preset' => 'digitalocean', 'trusted_proxies' => ['cloudflare', '10.0.0.0/8']],
            Integration::clientIpOptions(['client_ip' => ['preset' => 'digitalocean']], $read),
            'WATCHTOWER_OPTIONS wins',
        );
        $this->assertSame([], Integration::clientIpOptions([], static fn (): mixed => null));
    }
}
