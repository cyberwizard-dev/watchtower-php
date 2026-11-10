<?php

declare(strict_types=1);

namespace WatchTower\Support;

/**
 * Client-side scrubbing of secrets, mirroring Watch Tower's server-side Redactor
 * so nothing sensitive leaves the host in the first place.
 */
final class Redactor
{
    public const MASK = '[redacted]';

    /**
     * @param  list<string>  $headers  Lower-case header names that are always masked.
     */
    public function __construct(
        private readonly string $keyPattern,
        private readonly array $headers = [],
    ) {}

    public function isSensitiveKey(string $key): bool
    {
        return preg_match($this->keyPattern, $key) === 1;
    }

    /**
     * Mask header values by name (exact list or key pattern).
     *
     * @param  array<string, list<string>>  $headers
     * @return array<string, list<string>>
     */
    public function headers(array $headers): array
    {
        $clean = [];

        foreach ($headers as $name => $values) {
            $name = strtolower((string) $name);

            if (in_array($name, ['php-auth-user', 'php-auth-pw', 'php-auth-digest'], true)) {
                continue;
            }

            $clean[$name] = in_array($name, $this->headers, true) || $this->isSensitiveKey($name)
                ? array_map(static fn (): string => self::MASK, $values === [] ? [''] : $values)
                : array_map('strval', $values);
        }

        return $clean;
    }

    /**
     * Recursively mask values whose keys look like credentials.
     *
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    public function data(array $data, int $depth = 0): array
    {
        if ($depth > 12) {
            return [self::MASK];
        }

        $clean = [];

        foreach ($data as $key => $value) {
            if (is_string($key) && $this->isSensitiveKey($key) && ! is_array($value)) {
                $clean[$key] = self::MASK;
            } elseif (is_array($value)) {
                $clean[$key] = $this->data($value, $depth + 1);
            } elseif (is_string($value)) {
                $clean[$key] = $this->text($value);
            } else {
                $clean[$key] = $value;
            }
        }

        return $clean;
    }

    /**
     * Mask sensitive query-string parameters and drop user info from a URL.
     */
    public function url(string $url): string
    {
        $url = (string) preg_replace('#^([a-z][a-z0-9+.-]*://)[^/@?\#]*@#i', '$1', $url);
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query) || $query === '') {
            return $url;
        }

        $parts = array_map(function (string $pair): string {
            [$key] = explode('=', $pair, 2);

            return $this->isSensitiveKey(urldecode($key)) ? $key.'='.self::MASK : $pair;
        }, explode('&', $query));

        return str_replace('?'.$query, '?'.implode('&', $parts), $url);
    }

    /**
     * Mask bearer tokens, JWTs and key=value secrets that end up in free text.
     */
    public function text(string $text): string
    {
        $text = (string) preg_replace('/\b(Bearer|Basic)\s+[A-Za-z0-9\-._~+\/]+=*/i', '$1 '.self::MASK, $text);
        $text = (string) preg_replace('/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\b/', self::MASK, $text);

        return (string) preg_replace_callback(
            '/\b([A-Za-z_][A-Za-z0-9_.-]*)=([^\s&"\']+)/',
            fn (array $m): string => $this->isSensitiveKey($m[1]) ? $m[1].'='.self::MASK : $m[0],
            $text,
        );
    }
}
