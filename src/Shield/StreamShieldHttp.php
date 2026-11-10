<?php

declare(strict_types=1);

namespace WatchTower\Shield;

/**
 * Fallback without ext-curl. Streams have no separate connect timeout.
 */
final class StreamShieldHttp implements ShieldHttp
{
    public function request(string $method, string $url, array $headers, ?string $body, float $connectTimeout, float $timeout): array
    {
        try {
            $lines = ['Connection: close'];
            foreach ($headers as $name => $value) {
                $lines[] = $name.': '.$value;
            }

            if ($body !== null) {
                $lines[] = 'Content-Length: '.strlen($body);
            }

            $context = stream_context_create([
                'http' => [
                    'method' => $method,
                    'header' => implode("\r\n", $lines),
                    'content' => $body ?? '',
                    'timeout' => $timeout,
                    'ignore_errors' => true,
                    'follow_location' => 0,
                    'protocol_version' => 1.1,
                ],
            ]);

            $stream = @fopen($url, 'rb', false, $context);

            if ($stream === false) {
                return ['status' => 0, 'body' => ''];
            }

            $content = (string) @stream_get_contents($stream);
            $meta = stream_get_meta_data($stream);
            fclose($stream);

            $status = 0;
            foreach (is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : [] as $line) {
                if (is_string($line) && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                    $status = (int) $match[1];
                }
            }

            return ['status' => $status, 'body' => $content];
        } catch (\Throwable) {
            return ['status' => 0, 'body' => ''];
        }
    }
}
