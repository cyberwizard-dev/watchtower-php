<?php

declare(strict_types=1);

namespace WatchTower\Support;

use WatchTower\Shield\Patterns;

/**
 * The one place that decides a request's client IP: the request record, Shield (IP allow and
 * block lists, rate-limit keys, the challenge pass cookie, siteverify) and wt-shield records all
 * use it.
 *
 * A client IP header is honoured only when the connecting peer (REMOTE_ADDR) is a trusted
 * proxy; anybody else gets their REMOTE_ADDR, whatever headers they send. X-Forwarded-For and
 * Forwarded (RFC 7239 `for=`) are walked right to left, skipping trusted proxies, and the first
 * untrusted address wins (the left-most one when every hop is trusted). Single-value headers
 * (CF-Connecting-IP, do-connecting-ip, X-Real-IP, Fly-Client-IP, True-Client-IP, ...) are used
 * as they are. Invalid values fall back to REMOTE_ADDR. Nothing here throws.
 */
final class ClientIp
{
    /** RFC 1918, RFC 6598 (CGNAT), loopback, link-local, IPv6 ULA and link-local. */
    public const PRIVATE_RANGES = [
        '10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16', '100.64.0.0/10', '127.0.0.0/8', '169.254.0.0/16',
        '::1/128', 'fc00::/7', 'fe80::/10',
    ];

    /**
     * Cloudflare's published edge ranges, from https://www.cloudflare.com/ips/
     * (ips-v4 and ips-v6), retrieved 2026-11-10. Override with `trusted_proxies` if they change.
     */
    public const CLOUDFLARE_RANGES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18',
        '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17',
        '162.158.0.0/15', '104.16.0.0/13', '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32', '2405:8100::/32',
        '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    /**
     * Hosting presets: the header the platform sets and who may set it.
     *
     * - cloudflare: Cloudflare overwrites CF-Connecting-IP; only its edge ranges are trusted.
     * - digitalocean: App Platform sets do-connecting-ip and apps are reachable only through its
     *   edge, so any peer is trusted.
     * - aws_alb: ALB/ELB append the client to X-Forwarded-For and reach targets from private VPC
     *   addresses.
     * - heroku: the router appends to X-Forwarded-For; dynos are reachable only through it.
     * - fly: fly-proxy sets Fly-Client-IP; public traffic reaches machines only through it.
     * - vercel: Vercel overwrites X-Forwarded-For with the client address.
     * - nginx / traefik: a reverse proxy on the same host, LAN or Docker network appending to
     *   X-Forwarded-For.
     *
     * @var array<string, array{header: string, trusted_proxies: string|list<string>}>
     */
    public const PRESETS = [
        'cloudflare' => ['header' => 'CF-Connecting-IP', 'trusted_proxies' => ['cloudflare']],
        'digitalocean' => ['header' => 'do-connecting-ip', 'trusted_proxies' => '*'],
        'aws_alb' => ['header' => 'X-Forwarded-For', 'trusted_proxies' => 'private'],
        'heroku' => ['header' => 'X-Forwarded-For', 'trusted_proxies' => '*'],
        'fly' => ['header' => 'Fly-Client-IP', 'trusted_proxies' => '*'],
        'vercel' => ['header' => 'X-Forwarded-For', 'trusted_proxies' => '*'],
        'nginx' => ['header' => 'X-Forwarded-For', 'trusted_proxies' => 'private'],
        'traefik' => ['header' => 'X-Forwarded-For', 'trusted_proxies' => 'private'],
    ];

    /**
     * @param  ?string  $header  null = REMOTE_ADDR only
     * @param  bool  $trustAnyPeer  `'*'`: every peer may set the header
     * @param  list<string>  $trustedRanges  CIDRs (or bare addresses) of trusted proxies
     */
    public function __construct(
        public readonly ?string $header = null,
        public readonly bool $trustAnyPeer = false,
        public readonly array $trustedRanges = [],
        public readonly ?string $preset = null,
    ) {}

    /**
     * Build the resolver from the `client_ip` option and the deprecated `trusted_proxy_header`.
     * Precedence: client_ip.header, then the preset's header, then trusted_proxy_header;
     * client_ip.trusted_proxies, then the preset's, then 'private'.
     */
    public static function fromOptions(mixed $options, mixed $legacyHeader = null): self
    {
        $options = is_array($options) ? $options : [];
        $presetName = is_string($options['preset'] ?? null) ? strtolower(trim($options['preset'])) : '';
        $preset = self::PRESETS[$presetName] ?? null;

        $header = self::string($options['header'] ?? null) ?? $preset['header'] ?? self::string($legacyHeader);

        if ($header === null) {
            return new self(preset: $preset !== null ? $presetName : null);
        }

        $trusted = $options['trusted_proxies'] ?? null;
        $trusted = ($trusted === null || $trusted === '' || $trusted === []) ? ($preset['trusted_proxies'] ?? 'private') : $trusted;

        [$any, $ranges] = self::trusted($trusted);

        return new self($header, $any, $ranges, $preset !== null ? $presetName : null);
    }

    /**
     * The client IP for a `$_SERVER`-like array.
     *
     * @param  array<string, mixed>  $server
     */
    public function resolve(array $server): string
    {
        $peer = is_string($server['REMOTE_ADDR'] ?? null) ? trim($server['REMOTE_ADDR']) : '';

        if ($this->header === null || $peer === '' || ! $this->peerTrusted($peer)) {
            return $peer;
        }

        $value = $server['HTTP_'.strtoupper(str_replace('-', '_', $this->header))] ?? null;

        if (! is_string($value) || trim($value) === '') {
            return $peer;
        }

        $name = strtolower($this->header);

        if ($name === 'x-forwarded-for' || $name === 'forwarded') {
            return $this->fromChain($name === 'forwarded' ? self::forwardedFor($value) : explode(',', $value)) ?? $peer;
        }

        return self::normalize($value) ?? $peer;
    }

    /** Whether an address is one of the configured trusted proxy ranges. */
    public function isTrustedProxy(string $ip): bool
    {
        foreach ($this->trustedRanges as $range) {
            if (Patterns::ipInRange($ip, $range)) {
                return true;
            }
        }

        return false;
    }

    /**
     * One address from a header entry: quotes, brackets and ports removed. Null when invalid
     * ("unknown", obfuscated identifiers, garbage).
     */
    public static function normalize(string $entry): ?string
    {
        $entry = trim(trim($entry), '"');

        if (preg_match('/^\[([^\]]+)\](?::\d+)?$/', $entry, $match) === 1) {
            $entry = $match[1];
        } elseif (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3}):\d+$/', $entry, $match) === 1) {
            $entry = $match[1];
        }

        return filter_var($entry, FILTER_VALIDATE_IP) !== false ? $entry : null;
    }

    private function peerTrusted(string $peer): bool
    {
        return $this->trustAnyPeer || $this->isTrustedProxy($peer);
    }

    /**
     * Right to left: the first address that is not a trusted proxy, else the left-most valid one.
     * With '*' only the peer is trusted, so the right-most valid entry (the one the platform
     * appended) wins.
     *
     * @param  list<string>  $entries
     */
    private function fromChain(array $entries): ?string
    {
        $leftMost = null;

        for ($i = count($entries) - 1; $i >= 0; $i--) {
            $ip = self::normalize($entries[$i]);

            if ($ip === null) {
                continue;
            }

            if (! $this->isTrustedProxy($ip)) {
                return $ip;
            }

            $leftMost = $ip;
        }

        return $leftMost;
    }

    /**
     * The `for=` values of an RFC 7239 Forwarded header, in order.
     *
     * @return list<string>
     */
    private static function forwardedFor(string $value): array
    {
        $values = [];

        foreach (explode(',', $value) as $element) {
            $for = '';

            foreach (explode(';', $element) as $pair) {
                [$key, $pairValue] = array_pad(explode('=', $pair, 2), 2, '');

                if (strtolower(trim($key)) === 'for') {
                    $for = $pairValue;
                }
            }

            $values[] = $for;
        }

        return $values;
    }

    /**
     * '*', 'private', 'cloudflare', a comma-separated string or a list of CIDRs and keywords.
     *
     * @return array{0: bool, 1: list<string>}
     */
    private static function trusted(mixed $value): array
    {
        $items = is_array($value) ? $value : explode(',', is_scalar($value) ? (string) $value : '');
        $any = false;
        $ranges = [];

        foreach ($items as $item) {
            if (! is_string($item) || ($item = trim($item)) === '') {
                continue;
            }

            match (strtolower($item)) {
                '*' => $any = true,
                'private' => array_push($ranges, ...self::PRIVATE_RANGES),
                'cloudflare' => array_push($ranges, ...self::CLOUDFLARE_RANGES),
                default => $ranges[] = $item,
            };
        }

        return [$any, array_values(array_unique($ranges))];
    }

    private static function string(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
