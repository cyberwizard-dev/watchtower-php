<?php

declare(strict_types=1);

namespace WatchTower\Support;

/**
 * Size limits and JSON encoding used by Nightwatch's record serializers.
 */
final class Text
{
    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    public static function tiny(string $value): string
    {
        return self::restrict($value, 255);
    }

    public static function text(string $value): string
    {
        return self::restrict($value, 65_535);
    }

    public static function medium(string $value): string
    {
        return self::restrict($value, 16_777_215);
    }

    /** Cut to at most $bytes bytes without splitting a UTF-8 character. */
    public static function restrict(string $value, int $bytes): string
    {
        if (strlen($value) <= $bytes) {
            return $value;
        }

        return function_exists('mb_strcut') ? mb_strcut($value, 0, $bytes, 'UTF-8') : substr($value, 0, $bytes);
    }

    /** Keep the last $bytes bytes (command output), on a character boundary. */
    public static function tail(string $value, int $bytes): string
    {
        if (strlen($value) <= $bytes) {
            return $value;
        }

        return function_exists('mb_strcut')
            ? mb_strcut($value, strlen($value) - $bytes, $bytes, 'UTF-8')
            : substr($value, -$bytes);
    }

    /** JSON for nested wire fields; arrays with string keys (or empty ones) become objects. */
    public static function json(mixed $value, bool $object = true): string
    {
        if ($object && is_array($value)) {
            $value = (object) $value;
        }

        $json = json_encode($value, self::JSON_FLAGS);

        return is_string($json) ? $json : ($object ? '{}' : 'null');
    }

    /**
     * Make arbitrary log context JSON-safe (throwables, objects, resources).
     */
    public static function normalize(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 8) {
            return '[depth limit]';
        }

        return match (true) {
            $value === null, is_scalar($value) => $value,
            is_array($value) => array_map(static fn (mixed $item): mixed => self::normalize($item, $depth + 1), $value),
            $value instanceof \Throwable => [
                'class' => $value::class,
                'message' => $value->getMessage(),
                'code' => $value->getCode(),
                'file' => $value->getFile().':'.$value->getLine(),
            ],
            $value instanceof \JsonSerializable => self::normalize(Guard::safely(static fn (): mixed => $value->jsonSerialize(), $value::class), $depth + 1),
            $value instanceof \DateTimeInterface => $value->format(\DateTimeInterface::RFC3339_EXTENDED),
            $value instanceof \Stringable => (string) $value,
            is_object($value) => '['.$value::class.']',
            default => '[resource]',
        };
    }
}
