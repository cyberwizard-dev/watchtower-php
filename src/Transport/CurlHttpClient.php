<?php

declare(strict_types=1);

namespace WatchTower\Transport;

/**
 * cURL transport (preferred when ext-curl is loaded).
 */
final class CurlHttpClient implements HttpClient
{
    public function post(string $url, array $headers, string $body, float $connectTimeout, float $timeout): int
    {
        try {
            $handle = curl_init($url);

            if ($handle === false) {
                return 0;
            }

            $lines = [];
            foreach ($headers as $name => $value) {
                $lines[] = $name.': '.$value;
            }
            $lines[] = 'Expect:';

            curl_setopt_array($handle, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_HTTPHEADER => $lines,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) round($connectTimeout * 1000)),
                CURLOPT_TIMEOUT_MS => max(1, (int) round($timeout * 1000)),
                CURLOPT_FOLLOWLOCATION => false,
            ]);

            $result = curl_exec($handle);

            // The handle is freed when it goes out of scope (curl_close() is a no-op since PHP 8).
            return $result === false ? 0 : (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        } catch (\Throwable) {
            return 0;
        }
    }
}
