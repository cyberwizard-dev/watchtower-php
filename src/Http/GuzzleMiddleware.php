<?php

declare(strict_types=1);

namespace WatchTower\Http;

use GuzzleHttp\Promise\Create;
use WatchTower\Client;

/**
 * Guzzle middleware recording `outgoing-request` records and adding a W3C
 * `traceparent` header:
 *
 *     $stack = \GuzzleHttp\HandlerStack::create();
 *     $stack->push(WatchTower::guzzleMiddleware(), 'watchtower');
 *     $http = new \GuzzleHttp\Client(['handler' => $stack]);
 */
final class GuzzleMiddleware
{
    /**
     * @param  \Closure(): ?Client  $client
     */
    public static function create(\Closure $client): callable
    {
        return static function (callable $handler) use ($client): callable {
            return static function ($request, array $options) use ($handler, $client) {
                $sdk = null;

                try {
                    $sdk = $client();
                    $traceparent = $sdk?->traceparent();

                    if ($traceparent !== null && ! $request->hasHeader('traceparent')) {
                        $request = $request->withHeader('traceparent', $traceparent);
                    }
                } catch (\Throwable) {
                    // Send the request untouched.
                }

                $startedAt = microtime(true);
                $record = static function ($response) use ($sdk, $request, $startedAt): void {
                    if ($sdk === null) {
                        return;
                    }

                    try {
                        $sdk->outgoingRequest(
                            $request->getMethod(),
                            (string) $request->getUri(),
                            $startedAt,
                            (int) round((microtime(true) - $startedAt) * 1_000_000),
                            self::size($request),
                            $response !== null ? self::size($response) : 0,
                            $response !== null ? (int) $response->getStatusCode() : 0,
                        );
                    } catch (\Throwable) {
                        // Never affect the request.
                    }
                };

                return $handler($request, $options)->then(
                    static function ($response) use ($record) {
                        $record($response);

                        return $response;
                    },
                    static function ($reason) use ($record) {
                        $response = is_object($reason) && method_exists($reason, 'getResponse') ? $reason->getResponse() : null;
                        $record($response);

                        return Create::rejectionFor($reason);
                    },
                );
            };
        };
    }

    /**
     * Nightwatch OutgoingRequestSensor::resolveMessageSize(): body size, else Content-Length, else 0.
     */
    private static function size(object $message): int
    {
        $size = $message->getBody()->getSize();

        if ($size !== null) {
            return (int) $size;
        }

        $length = $message->getHeader('content-length')[0] ?? null;

        return is_numeric($length) ? (int) $length : 0;
    }
}
