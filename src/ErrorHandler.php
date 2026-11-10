<?php

declare(strict_types=1);

namespace WatchTower;

use WatchTower\Support\Guard;

/**
 * Global exception, error and fatal-error capture. Previous handlers are
 * chained, `error_reporting()` and the `@` operator are respected, and PHP's
 * own behaviour (logging, display, exit code 255) is preserved.
 */
final class ErrorHandler
{
    private const FATAL = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

    /** Identical warnings reported per execution before further repeats are ignored. */
    private const MAX_REPEATS = 5;

    /** @var callable|null */
    private $previousExceptionHandler = null;

    /** @var callable|null */
    private $previousErrorHandler = null;

    /** @var array<string, int> */
    private array $seen = [];

    private ?string $seenFor = null;

    private bool $installed = false;

    public function __construct(private readonly Client $client) {}

    public function install(): void
    {
        if ($this->installed) {
            return;
        }

        $this->installed = true;
        $config = $this->client->config;

        if ($config->captures('exceptions')) {
            $this->previousExceptionHandler = set_exception_handler([$this, 'handleException']);
        }

        if ($config->captures('errors')) {
            $this->previousErrorHandler = set_error_handler([$this, 'handleError']);
        }
    }

    public function handleException(\Throwable $e): void
    {
        Guard::safely(function () use ($e): void {
            $this->client->captureException($e, false);

            if ($this->client->execution()?->source === 'command') {
                $this->client->setExitCode(255);
            }
        });

        if ($this->previousExceptionHandler !== null) {
            ($this->previousExceptionHandler)($e);

            return;
        }

        // No previous handler: let PHP report it exactly as it would have
        // ("PHP Fatal error: Uncaught ...", HTTP 500, exit code 255).
        set_exception_handler(null);

        throw $e;
    }

    public function handleError(int $level, string $message, string $file = '', int $line = 0): bool
    {
        $reported = (error_reporting() & $level) !== 0
            && (($this->client->config->errorReporting ?? E_ALL) & $level) !== 0;

        if ($reported) {
            Guard::safely(fn () => $this->report($level, $message, $file, $line));
        }

        if ($this->previousErrorHandler !== null) {
            return (bool) ($this->previousErrorHandler)($level, $message, $file, $line);
        }

        // Fall through to PHP's standard handler (log / display as configured).
        return false;
    }

    public function handleShutdown(): void
    {
        Guard::safely(function (): void {
            $error = error_get_last();

            if ($error === null || ($error['type'] & self::FATAL) === 0 || ! $this->client->config->captures('fatal_errors')) {
                return;
            }

            $execution = $this->client->execution();

            // An uncaught exception we already reported ends as "Uncaught ..." fatal error.
            if ($execution?->hasUnhandledException && str_starts_with($error['message'], 'Uncaught ')) {
                return;
            }

            $message = (string) preg_replace('/\s+Stack trace:.*$/s', '', $error['message']);
            $this->client->captureException(new FatalError($message, 0, $error['type'], $error['file'], $error['line']), false);

            if ($execution?->source === 'command') {
                $this->client->setExitCode(255);
            }
        });
    }

    private function report(int $level, string $message, string $file, int $line): void
    {
        $deprecation = ($level & (E_DEPRECATED | E_USER_DEPRECATED)) !== 0;

        if ($deprecation && ! $this->client->config->captures('deprecations')) {
            return;
        }

        $execution = $this->client->execution();
        $trace = $execution?->traceId;

        if ($trace !== $this->seenFor) {
            $this->seen = [];
            $this->seenFor = $trace;
        }

        $key = $level.'|'.$file.'|'.$line;
        $this->seen[$key] = ($this->seen[$key] ?? 0) + 1;

        if ($this->seen[$key] > self::MAX_REPEATS) {
            return;
        }

        // Warnings and notices are reported as handled; E_USER_ERROR stops the script.
        $this->client->captureException(new \ErrorException($message, 0, $level, $file, $line), $level !== E_USER_ERROR);
    }
}
