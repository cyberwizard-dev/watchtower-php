<?php

declare(strict_types=1);

namespace WatchTower\Shield;

/**
 * The rules served by Watch Tower's Shield API, validated. Malformed entries are dropped one by
 * one, so a single bad rule never disables the rest.
 */
final class Rules
{
    /**
     * @param  list<string>  $allowIps
     * @param  list<array{cidr: string, reason: ?string, expires_at: ?int}>  $blockIps
     * @param  list<string>  $userAgents
     * @param  list<string>  $paths
     * @param  list<array{name: string, methods: list<string>, paths: list<string>, limit: int, window_seconds: int, by: string, action: string, block_seconds: int}>  $rateLimits
     * @param  array{field: string, action: string}|null  $honeypot
     * @param  array{provider: string, site_key: string}|null  $challenge
     */
    public function __construct(
        public readonly string $version,
        public readonly int $ttlSeconds,
        public readonly string $mode,
        public readonly array $allowIps = [],
        public readonly array $blockIps = [],
        public readonly array $userAgents = [],
        public readonly array $paths = [],
        public readonly array $rateLimits = [],
        public readonly ?array $honeypot = null,
        public readonly ?array $challenge = null,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data  the decoded 200 response
     */
    public static function fromArray(array $data): self
    {
        $block = is_array($data['block'] ?? null) ? $data['block'] : [];
        $allow = is_array($data['allow'] ?? null) ? $data['allow'] : [];

        return new self(
            version: is_scalar($data['version'] ?? null) ? (string) $data['version'] : '',
            ttlSeconds: max(5, is_numeric($data['ttl_seconds'] ?? null) ? (int) $data['ttl_seconds'] : 60),
            mode: ($data['mode'] ?? null) === 'enforce' ? 'enforce' : 'monitor',
            allowIps: self::strings($allow['ips'] ?? []),
            blockIps: self::blockIps($block['ips'] ?? []),
            userAgents: self::strings($block['user_agents'] ?? []),
            paths: self::strings($block['paths'] ?? []),
            rateLimits: self::rateLimits($data['rate_limits'] ?? []),
            honeypot: self::honeypot($data['honeypot'] ?? null),
            challenge: self::challenge($data['challenge'] ?? null),
        );
    }

    /**
     * The shape stored in the cache (and accepted by fromArray()).
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'version' => $this->version,
            'ttl_seconds' => $this->ttlSeconds,
            'mode' => $this->mode,
            'allow' => ['ips' => $this->allowIps],
            'block' => [
                'ips' => $this->blockIps,
                'user_agents' => $this->userAgents,
                'paths' => $this->paths,
                'countries' => [],
            ],
            'rate_limits' => $this->rateLimits,
            'honeypot' => $this->honeypot,
            'challenge' => $this->challenge,
        ];
    }

    /**
     * @return list<string>
     */
    private static function strings(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        return array_values(array_filter(
            $values,
            static fn (mixed $value): bool => is_string($value) && trim($value) !== '',
        ));
    }

    /**
     * @return list<array{cidr: string, reason: ?string, expires_at: ?int}>
     */
    private static function blockIps(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $ips = [];

        foreach ($values as $value) {
            // A bare string is accepted as a CIDR without reason or expiry.
            if (is_string($value)) {
                $value = ['cidr' => $value];
            }

            if (! is_array($value) || ! is_string($value['cidr'] ?? null) || trim($value['cidr']) === '') {
                continue;
            }

            $ips[] = [
                'cidr' => trim($value['cidr']),
                'reason' => is_string($value['reason'] ?? null) ? $value['reason'] : null,
                'expires_at' => is_numeric($value['expires_at'] ?? null) ? (int) $value['expires_at'] : null,
            ];
        }

        return $ips;
    }

    /**
     * @return list<array{name: string, methods: list<string>, paths: list<string>, limit: int, window_seconds: int, by: string, action: string, block_seconds: int}>
     */
    private static function rateLimits(mixed $values): array
    {
        if (! is_array($values)) {
            return [];
        }

        $limits = [];

        foreach ($values as $value) {
            if (! is_array($value) || ! is_string($value['name'] ?? null) || $value['name'] === ''
                || ! is_numeric($value['limit'] ?? null) || (int) $value['limit'] < 1
                || ! is_numeric($value['window_seconds'] ?? null) || (int) $value['window_seconds'] < 1) {
                continue;
            }

            $limits[] = [
                'name' => $value['name'],
                'methods' => array_map('strtoupper', self::strings($value['methods'] ?? [])),
                'paths' => self::strings($value['paths'] ?? []),
                'limit' => (int) $value['limit'],
                'window_seconds' => (int) $value['window_seconds'],
                'by' => in_array($value['by'] ?? null, ['ip', 'user', 'ip_path'], true) ? $value['by'] : 'ip',
                'action' => ($value['action'] ?? null) === 'challenge' ? 'challenge' : 'block',
                'block_seconds' => is_numeric($value['block_seconds'] ?? null) ? max(0, (int) $value['block_seconds']) : 0,
            ];
        }

        return $limits;
    }

    /**
     * @return array{field: string, action: string}|null
     */
    private static function honeypot(mixed $value): ?array
    {
        if (! is_array($value) || ! is_string($value['field'] ?? null) || trim($value['field']) === '') {
            return null;
        }

        return [
            'field' => trim($value['field']),
            'action' => ($value['action'] ?? null) === 'flag' ? 'flag' : 'block',
        ];
    }

    /**
     * @return array{provider: string, site_key: string}|null
     */
    private static function challenge(mixed $value): ?array
    {
        if (! is_array($value) || ($value['provider'] ?? null) !== 'turnstile'
            || ! is_string($value['site_key'] ?? null) || $value['site_key'] === '') {
            return null;
        }

        return ['provider' => 'turnstile', 'site_key' => $value['site_key']];
    }
}
