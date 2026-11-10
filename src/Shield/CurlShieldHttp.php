<?php

declare(strict_types=1);

namespace WatchTower\Shield;

final class CurlShieldHttp implements ShieldHttp
{
    public function request(string $method, string $url, array $headers, ?string $body, float $connectTimeout, float $timeout): array
    {
        try {
            $handle = curl_init($url);

            if ($handle === false) {
                return ['status' => 0, 'body' => ''];
            }

            $lines = ['Expect:'];
            foreach ($headers as $name => $value) {
                $lines[] = $name.': '.$value;
            }

            $options = [
                CURLOPT_CUSTOMREQUEST => $method,
                CURLOPT_HTTPHEADER => $lines,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOSIGNAL => true,
                CURLOPT_CONNECTTIMEOUT_MS => max(1, (int) round($connectTimeout * 1000)),
                CURLOPT_TIMEOUT_MS => max(1, (int) round($timeout * 1000)),
                CURLOPT_FOLLOWLOCATION => false,
            ];

            if ($body !== null) {
                $options[CURLOPT_POSTFIELDS] = $body;
            }

            curl_setopt_array($handle, $options);
            $result = curl_exec($handle);

            if (! is_string($result)) {
                return ['status' => 0, 'body' => ''];
            }

            return ['status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE), 'body' => $result];
        } catch (\Throwable) {
            return ['status' => 0, 'body' => ''];
        }
    }
}
