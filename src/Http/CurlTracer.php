<?php

declare(strict_types=1);

namespace WatchTower\Http;

use WatchTower\Client;

/**
 * Times a curl_exec() call and records it as an `outgoing-request`.
 */
final class CurlTracer
{
    /**
     * @param  list<string>|null  $headers  The request headers; when given they are set on the
     *                                      handle together with a W3C `traceparent` header.
     */
    public static function exec(?Client $client, \CurlHandle $handle, ?array $headers = null): string|bool
    {
        if ($client !== null && $headers !== null) {
            try {
                $traceparent = $client->traceparent();

                if ($traceparent !== null && ! self::hasHeader($headers, 'traceparent')) {
                    $headers[] = 'traceparent: '.$traceparent;
                }

                curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
            } catch (\Throwable) {
                // Fall through to the plain call.
            }
        }

        $startedAt = microtime(true);
        $result = curl_exec($handle);
        $endedAt = microtime(true);

        if ($client !== null) {
            try {
                $info = curl_getinfo($handle);
                $method = defined('CURLINFO_EFFECTIVE_METHOD') ? (string) curl_getinfo($handle, CURLINFO_EFFECTIVE_METHOD) : '';

                $client->outgoingRequest(
                    $method !== '' ? $method : 'GET',
                    (string) ($info['url'] ?? ''),
                    $startedAt,
                    (int) round(($endedAt - $startedAt) * 1_000_000),
                    (int) max(0, $info['size_upload'] ?? 0),
                    (int) max(0, $info['size_download'] ?? 0),
                    $result === false ? 0 : (int) ($info['http_code'] ?? 0),
                );
            } catch (\Throwable) {
                // Recording must never affect the call.
            }
        }

        return $result;
    }

    /**
     * Add a `traceparent` to a list of "Name: value" header lines (for code
     * that sets CURLOPT_HTTPHEADER itself).
     *
     * @param  list<string>  $headers
     * @return list<string>
     */
    public static function withTraceparent(?Client $client, array $headers): array
    {
        $traceparent = $client?->traceparent();

        if ($traceparent !== null && ! self::hasHeader($headers, 'traceparent')) {
            $headers[] = 'traceparent: '.$traceparent;
        }

        return $headers;
    }

    /**
     * @param  list<string>  $headers
     */
    private static function hasHeader(array $headers, string $name): bool
    {
        foreach ($headers as $header) {
            if (is_string($header) && stripos(ltrim($header), $name.':') === 0) {
                return true;
            }
        }

        return false;
    }
}
