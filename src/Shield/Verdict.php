<?php

declare(strict_types=1);

namespace WatchTower\Shield;

/**
 * What Shield would do with a request in enforce mode.
 */
final class Verdict
{
    public const ALLOW = 'allow';

    public const BLOCK = 'block';

    public const RATE_LIMIT = 'rate_limit';

    public const CHALLENGE = 'challenge';

    /**
     * Honeypot hit with action "flag": reported, never blocked.
     */
    public const FLAG = 'flag';

    private function __construct(
        public readonly string $kind,
        public readonly ?string $rule = null,
        public readonly string $recordAction = '',
        public readonly int $retryAfter = 0,
    ) {}

    public static function allow(): self
    {
        return new self(self::ALLOW);
    }

    public static function block(string $rule, string $recordAction = 'blocked'): self
    {
        return new self(self::BLOCK, $rule, $recordAction);
    }

    public static function rateLimit(string $rule, int $retryAfter): self
    {
        return new self(self::RATE_LIMIT, $rule, 'rate_limited', max(1, $retryAfter));
    }

    public static function challenge(string $rule, int $retryAfter): self
    {
        return new self(self::CHALLENGE, $rule, 'challenged', max(1, $retryAfter));
    }

    public static function flag(string $rule): self
    {
        return new self(self::FLAG, $rule, 'honeypot');
    }

    public function allowed(): bool
    {
        return $this->kind === self::ALLOW;
    }
}
