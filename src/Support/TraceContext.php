<?php

declare(strict_types=1);

namespace WatchTower\Support;

/**
 * W3C Trace Context (`traceparent`) built from and read into trace ids,
 * identical to the Laravel Watch Tower agent.
 */
final class TraceContext
{
    private const PATTERN = '/^([0-9a-f]{2})-([0-9a-f]{32})-([0-9a-f]{16})-([0-9a-f]{2})$/';

    /**
     * "00-{trace id without dashes}-{random 16 hex}-01", or null when the trace id is not a
     * 32-hex-digit id (with or without dashes).
     */
    public static function outgoing(?string $traceId): ?string
    {
        $hex = strtolower(str_replace('-', '', (string) $traceId));

        if (preg_match('/^[0-9a-f]{32}$/', $hex) !== 1 || $hex === str_repeat('0', 32)) {
            return null;
        }

        return '00-'.$hex.'-'.bin2hex(random_bytes(8)).'-01';
    }

    /**
     * @return array{parent_trace_id: string, parent_span_id: string}|null
     */
    public static function parse(?string $header): ?array
    {
        $header = strtolower(trim((string) $header));

        if (preg_match(self::PATTERN, $header, $matches) !== 1
            || $matches[1] === 'ff'
            || $matches[2] === str_repeat('0', 32)
            || $matches[3] === str_repeat('0', 16)) {
            return null;
        }

        return ['parent_trace_id' => $matches[2], 'parent_span_id' => $matches[3]];
    }
}
