<?php

declare(strict_types=1);

namespace WatchTower\Shield;

/**
 * The response Shield (or the browser proxy) sends instead of the application's.
 */
final class ShieldResponse
{
    /**
     * @param  list<string>  $headers  full header lines
     */
    public function __construct(
        public readonly int $status,
        public readonly array $headers,
        public readonly string $body,
    ) {}

    public function send(): void
    {
        if (! headers_sent()) {
            http_response_code($this->status);

            foreach ($this->headers as $header) {
                header($header, stripos($header, 'set-cookie:') !== 0);
            }
        }

        echo $this->body;
    }

    public function header(string $name): ?string
    {
        foreach ($this->headers as $header) {
            if (stripos($header, $name.':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }

        return null;
    }
}
