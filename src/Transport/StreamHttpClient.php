<?php

declare(strict_types=1);

namespace WatchTower\Transport;

/**
 * Fallback transport using PHP streams (no extensions needed). Streams have no
 * separate connect timeout, so the total timeout applies to both phases.
 */
final class StreamHttpClient implements HttpClient
{
    public function post(string $url, array $headers, string $body, float $connectTimeout, float $timeout): int
    {
        try {
            $lines = ['Connection: close', 'Content-Length: '.strlen($body)];
            foreach ($headers as $name => $value) {
                $lines[] = $name.': '.$value;
            }

            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => implode("\r\n", $lines),
                    'content' => $body,
                    'timeout' => $timeout,
                    'ignore_errors' => true,
                    'follow_location' => 0,
                    'protocol_version' => 1.1,
                ],
            ]);

            $stream = @fopen($url, 'rb', false, $context);

            if ($stream === false) {
                return 0;
            }

            $meta = stream_get_meta_data($stream);
            fclose($stream);

            return self::status(is_array($meta['wrapper_data'] ?? null) ? $meta['wrapper_data'] : []);
        } catch (\Throwable) {
            return 0;
        }
    }

    /**
     * @param  array<int, mixed>  $headers
     */
    private static function status(array $headers): int
    {
        $status = 0;

        foreach ($headers as $line) {
            if (is_string($line) && preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match) === 1) {
                $status = (int) $match[1];
            }
        }

        return $status;
    }
}
