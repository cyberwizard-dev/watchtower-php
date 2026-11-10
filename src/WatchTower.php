<?php

declare(strict_types=1);

namespace WatchTower;

use WatchTower\Http\CurlTracer;
use WatchTower\Http\GuzzleMiddleware;
use WatchTower\Log\Logger;
use WatchTower\Shield\ShieldResponse;
use WatchTower\Support\Guard;

/**
 * Static entry point of the SDK.
 *
 *     WatchTower::init(['token' => '...', 'base_url' => 'https://watchtower.example.com']);
 *
 * Every method is safe to call before init() (it does nothing) and never throws.
 */
final class WatchTower
{
    public const VERSION = '0.2.0';

    private static ?Client $client = null;

    private static ?ErrorHandler $errors = null;

    private static ?Logger $logger = null;

    /**
     * Configure the SDK and start observing the current request or CLI script.
     *
     * @param  array<string, mixed>  $options  See README for every option.
     */
    public static function init(array $options = []): ?Client
    {
        if (self::$client !== null) {
            return self::$client;
        }

        $blocked = null;

        $client = Guard::safely(static function () use ($options, &$blocked): Client {
            $client = new Client(new Config($options));
            self::$client = $client;

            if (! $client->config->enabled) {
                return $client;
            }

            if ($options['install_handlers'] ?? true) {
                self::$errors = new ErrorHandler($client);
                self::$errors->install();
            }

            if (PHP_SAPI === 'cli' || PHP_SAPI === 'phpdbg') {
                $argv = is_array($_SERVER['argv'] ?? null) ? array_map('strval', $_SERVER['argv']) : [];
                $name = (string) ($options['command_name'] ?? ($argv !== [] ? basename($argv[0]) : 'php'));
                $execution = $client->startCommand($name, $argv !== [] ? implode(' ', [$name, ...array_slice($argv, 1)]) : $name, '', is_numeric($_SERVER['REQUEST_TIME_FLOAT'] ?? null) ? (float) $_SERVER['REQUEST_TIME_FLOAT'] : null);
                $execution->meta['auto'] = true;
            } else {
                $client->startRequest($_SERVER);

                if ($client->config->captures('response_size')) {
                    $client->countResponseBytes();
                }

                // Shield decides before any application code runs.
                if ($client->config->shield['enabled']) {
                    $blocked = $client->shield()->handle($_SERVER, $_POST, $_COOKIE);
                }
            }

            // Registering from inside a shutdown function puts ours last, after the application's own.
            register_shutdown_function(static function () use ($client): void {
                register_shutdown_function(static fn () => $client->shutdown(self::$errors));
            });

            return $client;
        });

        if ($blocked instanceof ShieldResponse) {
            $blocked->send();

            // The shutdown handler still reports the request and its wt-shield record.
            exit;
        }

        return $client;
    }

    /**
     * The hidden Shield honeypot input to put inside forms; empty when Shield is off. A POST that
     * fills it is blocked (or flagged, per the rules).
     */
    public static function honeypotField(): string
    {
        return (string) Guard::safely(static fn (): string => self::$client?->shield()->honeypotField() ?? '', '');
    }

    public static function client(): ?Client
    {
        return self::$client;
    }

    /**
     * Name the route of this request; it becomes the request's group in Watch Tower.
     *
     * @param  list<string>|null  $methods
     */
    public static function route(string $path, ?string $name = null, ?string $action = null, ?array $methods = null): void
    {
        Guard::safely(static fn () => self::$client?->route($path, $name, $action, $methods));
    }

    /**
     * Attribute this execution to a user (also sends a `user` record).
     */
    public static function user(string|int|null $id, ?string $name = null, ?string $email = null): void
    {
        Guard::safely(static fn () => self::$client?->user($id, $name, $email));
    }

    public static function captureException(\Throwable $e, bool $handled = true): void
    {
        self::$client?->captureException($e, $handled);
    }

    /**
     * A PSR-3 logger (implements Psr\Log\LoggerInterface when psr/log is installed).
     */
    public static function logger(): Logger
    {
        return self::$logger ??= new Logger(static fn (): ?Client => self::$client);
    }

    /**
     * Run a CLI task as its own reported command.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public static function command(string $name, callable $callback, ?bool $captureOutput = null): mixed
    {
        if (self::$client === null) {
            return $callback();
        }

        return self::$client->runCommand($name, $callback, $captureOutput);
    }

    /** Exit code reported for the automatically observed CLI script. */
    public static function exitCode(int $code): void
    {
        Guard::safely(static fn () => self::$client?->setExitCode($code));
    }

    /**
     * curl_exec() that records an outgoing-request. Pass the request headers to
     * have a W3C `traceparent` added to them.
     *
     * @param  list<string>|null  $headers
     */
    public static function curlExec(\CurlHandle $handle, ?array $headers = null): string|bool
    {
        return CurlTracer::exec(self::$client, $handle, $headers);
    }

    /**
     * Guzzle middleware: `$stack->push(WatchTower::guzzleMiddleware());`
     */
    public static function guzzleMiddleware(): callable
    {
        return GuzzleMiddleware::create(static fn (): ?Client => self::$client);
    }

    /** The `traceparent` header value to send with an outgoing call, if tracing is on. */
    public static function traceparent(): ?string
    {
        return Guard::safely(static fn () => self::$client?->traceparent());
    }

    /** Do not report the current request or script. */
    public static function ignore(): void
    {
        Guard::safely(static fn () => self::$client?->ignore());
    }

    /** Send everything buffered so far now (useful in long-running scripts). */
    public static function flush(): void
    {
        self::$client?->flush();
    }

    /**
     * Forget the SDK state and restore handlers (tests, long-running workers).
     */
    public static function reset(): void
    {
        if (self::$errors !== null) {
            @restore_error_handler();
            @restore_exception_handler();
        }

        self::$client = null;
        self::$errors = null;
        self::$logger = null;
        Client::resetProcessState();
    }
}
