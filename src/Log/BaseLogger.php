<?php

declare(strict_types=1);

namespace WatchTower\Log;

use WatchTower\Client;

/**
 * PSR-3 compatible logger writing Nightwatch `log` records into the current
 * execution. Signatures are deliberately loose so the subclass can implement
 * every psr/log major version (1, 2 and 3).
 */
abstract class BaseLogger
{
    /**
     * @param  \Closure(): ?Client  $client
     */
    public function __construct(private readonly \Closure $client) {}

    public function emergency($message, array $context = []): void
    {
        $this->log('emergency', $message, $context);
    }

    public function alert($message, array $context = []): void
    {
        $this->log('alert', $message, $context);
    }

    public function critical($message, array $context = []): void
    {
        $this->log('critical', $message, $context);
    }

    public function error($message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    public function warning($message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    public function notice($message, array $context = []): void
    {
        $this->log('notice', $message, $context);
    }

    public function info($message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    public function debug($message, array $context = []): void
    {
        $this->log('debug', $message, $context);
    }

    /**
     * @param  mixed  $level  A PSR-3 level name.
     * @param  string|\Stringable  $message
     * @param  array<array-key, mixed>  $context
     */
    public function log($level, $message, array $context = []): void
    {
        try {
            if (! is_string($level) || (! is_string($message) && ! $message instanceof \Stringable)) {
                return;
            }

            ($this->client)()?->log($level, $message, $context);
        } catch (\Throwable) {
            // Logging must never break the application.
        }
    }
}
