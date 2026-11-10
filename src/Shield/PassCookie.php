<?php

declare(strict_types=1);

namespace WatchTower\Shield;

use WatchTower\Config;

/**
 * wt_shield_pass: "{expires}.{hmac}" issued after a solved challenge. The HMAC covers the expiry
 * and the client IP and is keyed with a hash of the environment token, so the cookie can't be
 * forged, extended or handed to another client address.
 */
final class PassCookie
{
    public const NAME = 'wt_shield_pass';

    public function __construct(private readonly Config $config) {}

    /** The Set-Cookie header value. */
    public function header(string $ip, int $now, bool $secure): string
    {
        $expires = $now + $this->config->shield['pass_minutes'] * 60;

        return self::NAME.'='.$expires.'.'.$this->signature($expires, $ip)
            .'; Expires='.gmdate('D, d M Y H:i:s', $expires).' GMT'
            .'; Max-Age='.($expires - $now)
            .'; Path=/; HttpOnly; SameSite=Lax'.($secure ? '; Secure' : '');
    }

    public function valid(mixed $value, string $ip, int $now): bool
    {
        if (! is_string($value) || preg_match('/^(\d{1,12})\.([0-9a-f]{64})$/', $value, $parts) !== 1) {
            return false;
        }

        $expires = (int) $parts[1];

        return $expires > $now && hash_equals($this->signature($expires, $ip), $parts[2]);
    }

    public function signature(int $expires, string $ip): string
    {
        return hash_hmac('sha256', "wt-shield-pass|{$expires}|{$ip}", hash('sha256', 'wt-shield|'.(string) $this->config->token));
    }
}
