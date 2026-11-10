<?php

declare(strict_types=1);

namespace WatchTower;

use WatchTower\Records\RecordFactory;
use WatchTower\Shield\Shield;
use WatchTower\Support\Guard;
use WatchTower\Support\Location;
use WatchTower\Support\Redactor;
use WatchTower\Support\RoutePattern;
use WatchTower\Support\Sql;
use WatchTower\Support\Text;
use WatchTower\Support\TraceContext;
use WatchTower\Transport\HttpClient;
use WatchTower\Transport\Ingest;
use WatchTower\Transport\Spool;

/**
 * The SDK core: owns the current execution (request or command), buffers its
 * records and hands them to the transport when the execution ends.
 */
final class Client
{
    /** PSR-3 levels by RFC 5424 severity (0 = most severe). */
    public const LOG_LEVELS = [
        'emergency' => 0, 'alert' => 1, 'critical' => 2, 'error' => 3,
        'warning' => 4, 'notice' => 5, 'info' => 6, 'debug' => 7,
    ];

    private const HELLO_INTERVAL_SECONDS = 86400;

    private static bool $helloSentThisProcess = false;

    public readonly Redactor $redactor;

    public readonly Location $location;

    public readonly RecordFactory $records;

    public readonly Ingest $ingest;

    private ?Execution $execution = null;

    private int $explicitCommands = 0;

    private ?int $responseBytes = null;

    private int $outputLevel = 0;

    private bool $finished = false;

    private ?Shield $shield = null;

    public function __construct(public readonly Config $config, ?HttpClient $http = null)
    {
        $this->redactor = new Redactor($config->redactKeys, $config->redactHeaders);
        $this->location = new Location($config->basePath, $config->locationSkip);
        $this->records = new RecordFactory($config, $this->redactor, $this->location);
        $this->ingest = new Ingest(
            $config,
            $http ?? $config->httpClient ?? Ingest::defaultHttpClient(),
            new Spool($config->spoolDir, $config->spoolMaxBytes),
        );
    }

    /** Shield (bot & spam protection), built on first use. */
    public function shield(): Shield
    {
        return $this->shield ??= new Shield($this->config, $this);
    }

    /** @internal Tests inject a clock or counter. */
    public function useShield(Shield $shield): void
    {
        $this->shield = $shield;
    }

    public function execution(): ?Execution
    {
        return $this->execution;
    }

    public function isActive(): bool
    {
        return $this->config->enabled && $this->execution !== null && ! $this->execution->ignored;
    }

    /**
     * Begin observing an HTTP request described by a `$_SERVER`-like array.
     *
     * @param  array<string, mixed>  $server
     */
    public function startRequest(array $server, ?float $startedAt = null): Execution
    {
        $startedAt ??= is_numeric($server['REQUEST_TIME_FLOAT'] ?? null) ? (float) $server['REQUEST_TIME_FLOAT'] : microtime(true);
        $execution = new Execution('request', $startedAt, $this->roll($this->config->sampleRate));
        $execution->bootstrapEndedAt = max($startedAt, microtime(true));

        $method = strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET'));
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $path = (string) (parse_url($uri, PHP_URL_PATH) ?? '/');
        $path = $path === '' ? '/' : $path;
        $headers = self::headersFrom($server);
        $https = strtolower((string) ($server['HTTPS'] ?? ''));
        $scheme = ($https !== '' && $https !== 'off') || (string) ($server['REQUEST_SCHEME'] ?? '') === 'https' ? 'https' : 'http';
        $host = (string) ($server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? 'localhost');

        $execution->preview = Text::tiny($method.' '.$path);
        $execution->meta = [
            'method' => $method,
            'url' => $scheme.'://'.$host.$uri,
            'path' => $path,
            'route_name' => '',
            'route_methods' => [$method],
            'route_domain' => '',
            'route_path' => RoutePattern::fromPath($path),
            'route_action' => '',
            'ip' => $this->config->clientIp->resolve($server),
            'request_size' => is_numeric($server['CONTENT_LENGTH'] ?? null) ? (int) $server['CONTENT_LENGTH'] : 0,
            'headers' => $this->config->captures('headers') ? $headers : [],
        ];
        $execution->ignored = ! $this->config->captures('requests') || $this->isIgnoredPath($path);

        $this->begin($execution);

        if ($this->config->captures('trace_context') && isset($headers['traceparent'][0])) {
            $parent = TraceContext::parse($headers['traceparent'][0]);

            if ($parent !== null) {
                $this->push($this->records->traceLink($execution, $parent, microtime(true)));
            }
        }

        return $execution;
    }

    /**
     * Begin observing a CLI command or script.
     */
    public function startCommand(string $name, ?string $command = null, string $class = '', ?float $startedAt = null): Execution
    {
        $startedAt ??= microtime(true);
        $execution = new Execution('command', $startedAt, $this->roll($this->config->sampleRate));
        $execution->bootstrapEndedAt = max($startedAt, microtime(true));
        $execution->preview = Text::tiny($name);
        $execution->meta = ['name' => $name, 'command' => $command ?? $name, 'class' => $class];
        $execution->ignored = ! $this->config->captures('commands');

        $this->begin($execution);

        return $execution;
    }

    public function begin(Execution $execution): void
    {
        $this->execution = $execution;
        $this->finished = false;
    }

    /**
     * Name the route of the current request (`/users/{id}`), which also becomes its group.
     *
     * @param  list<string>|null  $methods
     */
    public function route(string $path, ?string $name = null, ?string $action = null, ?array $methods = null, ?string $domain = null): void
    {
        if ($this->execution === null || $this->execution->source !== 'request') {
            return;
        }

        $meta = &$this->execution->meta;
        $meta['route_path'] = $path === '' ? '' : '/'.ltrim($path, '/');
        $meta['route_name'] = $name ?? $meta['route_name'];
        $meta['route_action'] = $action ?? $meta['route_action'];
        $meta['route_methods'] = $methods !== null ? array_values(array_map('strtoupper', $methods)) : $meta['route_methods'];
        $meta['route_domain'] = $domain ?? $meta['route_domain'];
    }

    public function user(string|int|null $id, ?string $name = null, ?string $username = null): void
    {
        if ($this->execution === null) {
            return;
        }

        if ($id === null || (string) $id === '') {
            $this->execution->userId = '';
            $this->execution->userDetails = null;

            return;
        }

        $this->execution->userId = Text::tiny((string) $id);
        $this->execution->userDetails = ['id' => (string) $id, 'name' => (string) $name, 'username' => (string) $username];
    }

    /** Do not report the current execution at all. */
    public function ignore(): void
    {
        if ($this->execution !== null) {
            $this->execution->ignored = true;
        }
    }

    public function setExitCode(int $code): void
    {
        if ($this->execution !== null) {
            $this->execution->exitCode = $code;
        }
    }

    public function captureException(\Throwable $e, bool $handled = true): void
    {
        Guard::safely(function () use ($e, $handled): void {
            if (! $this->isActive() || ! $this->config->captures('exceptions')) {
                return;
            }

            $execution = $this->execution;
            $execution->counters['exceptions']++;

            if (! $handled) {
                $execution->hasUnhandledException = true;

                if ($execution->exceptionPreview === '') {
                    $execution->exceptionPreview = Text::tiny($this->redactor->text($e->getMessage()));
                }

                if ($this->roll($this->config->exceptionSampleRate)) {
                    $execution->forceKeep = true;
                }
            }

            $this->push($this->records->exception($execution, $e, $handled, microtime(true)));
        });
    }

    /**
     * @param  array<array-key, mixed>  $context
     * @param  array<array-key, mixed>  $extra
     */
    public function log(string $level, string|\Stringable $message, array $context = [], array $extra = [], ?float $timestamp = null): void
    {
        Guard::safely(function () use ($level, $message, $context, $extra, $timestamp): void {
            $level = strtolower($level);

            if (! $this->isActive() || ! $this->config->captures('logs') || ! isset(self::LOG_LEVELS[$level])
                || self::LOG_LEVELS[$level] > (self::LOG_LEVELS[$this->config->logLevel] ?? 7)) {
                return;
            }

            $this->execution->counters['logs']++;
            $this->push($this->records->log($this->execution, $level, (string) $message, $context, $extra, $timestamp ?? microtime(true)));
        });
    }

    /**
     * Record a database query. Literals are stripped before anything is stored.
     */
    public function query(string $sql, float $startedAt, int $durationMicros, string $connection = 'default', ?string $file = null, ?int $line = null): void
    {
        Guard::safely(function () use ($sql, $startedAt, $durationMicros, $connection, $file, $line): void {
            if (! $this->isActive() || ! $this->config->captures('queries')) {
                return;
            }

            if ($file === null) {
                [$file, $line] = $this->location->find(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30));
            }

            $this->execution->counters['queries']++;

            if ($this->config->slowQueryMs !== null && $durationMicros >= $this->config->slowQueryMs * 1000) {
                $this->execution->forceKeep = true;
            }

            $this->push($this->records->query(
                $this->execution,
                Sql::stripLiterals($sql, $this->config->stripNumericLiterals),
                $startedAt,
                $durationMicros,
                $connection,
                $file,
                (int) $line,
            ));
        });
    }

    public function outgoingRequest(string $method, string $url, float $startedAt, int $durationMicros, int $requestSize, int $responseSize, int $statusCode): void
    {
        Guard::safely(function () use ($method, $url, $startedAt, $durationMicros, $requestSize, $responseSize, $statusCode): void {
            if (! $this->isActive() || ! $this->config->captures('outgoing_requests')) {
                return;
            }

            $this->execution->counters['outgoing_requests']++;
            $this->push($this->records->outgoingRequest($this->execution, $method, $url, $startedAt, $durationMicros, $requestSize, $responseSize, $statusCode));
        });
    }

    /** A `traceparent` value for an outgoing call made by the current execution. */
    public function traceparent(): ?string
    {
        if ($this->execution === null || ! $this->config->captures('trace_context')) {
            return null;
        }

        return TraceContext::outgoing($this->execution->traceId);
    }

    /**
     * Run a CLI task as its own command execution and report it (with its
     * output when enabled). The callback's return value is passed through: an
     * int is the exit code, `false` means 1, anything else 0.
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function runCommand(string $name, callable $callback, ?bool $captureOutput = null): mixed
    {
        $outer = $this->execution;
        $outerFinished = $this->finished;
        $this->explicitCommands++;
        $execution = $this->startCommand($name, $name, '');
        $execution->ignored = false;
        $capture = ($captureOutput ?? $this->config->captures('command_output')) && $this->config->enabled;
        $level = null;

        if ($capture) {
            $execution->meta['output'] = '';
            $execution->meta['output_truncated'] = false;
            $max = $this->config->outputMaxBytes;

            $started = ob_start(static function (string $buffer) use ($execution, $max): string {
                $output = $execution->meta['output'].$buffer;

                if (strlen($output) > $max * 2) {
                    $output = Text::tail($output, $max);
                    $execution->meta['output_truncated'] = true;
                }

                $execution->meta['output'] = $output;

                return $buffer;
            }, 1);
            $level = $started ? ob_get_level() : null;
        }

        try {
            $result = $callback();
            $execution->exitCode = is_int($result) ? $result : ($result === false ? 1 : 0);

            return $result;
        } catch (\Throwable $e) {
            $this->captureException($e, false);
            $execution->exitCode = 1;

            throw $e;
        } finally {
            if ($level !== null && ob_get_level() >= $level) {
                while (ob_get_level() > $level) {
                    @ob_end_flush();
                }
                @ob_end_flush();
            }

            $this->finish();
            $this->execution = $outer;
            $this->finished = $outerFinished;
        }
    }

    /**
     * Send what is buffered so far (the execution keeps running).
     */
    public function flush(): void
    {
        Guard::safely(function (): void {
            if ($this->execution === null || $this->execution->ignored) {
                return;
            }

            if ($this->shouldKeep($this->execution, microtime(true))) {
                $records = $this->execution->records;
                $this->execution->records = [];
                $this->deliver($records);
            }
        });
    }

    /**
     * End the current execution: add its request/command (and user, output)
     * records, apply sampling and deliver.
     *
     * @param  array<string, mixed>  $response  status_code / response_size overrides.
     */
    public function finish(array $response = []): void
    {
        Guard::safely(function () use ($response): void {
            $execution = $this->execution;

            if ($execution === null || $this->finished) {
                return;
            }

            $this->finished = true;
            $endedAt = microtime(true);
            $execution->stage = 'terminating';

            if ($execution->ignored || ! $this->config->enabled) {
                $this->deliver([]);

                return;
            }

            $records = $execution->records;

            if ($execution->dropped > 0) {
                $records[] = $this->records->log($execution, 'warning', sprintf(
                    'Watch Tower PHP SDK dropped %d records of this %s (limit %d per execution).',
                    $execution->dropped,
                    $execution->source,
                    $this->config->maxRecords,
                ), ['dropped' => $execution->droppedTypes], [], $endedAt);
                $execution->counters['logs']++;
            }

            if ($execution->source === 'request') {
                $status = (int) ($response['status_code'] ?? 0);
                if ($execution->hasUnhandledException && ($status === 0 || $status < 500)) {
                    $status = 500;
                }

                $records[] = $this->records->request($execution, [
                    ...$execution->meta,
                    'status_code' => $status,
                    'response_size' => (int) ($response['response_size'] ?? 0),
                    'payload' => $this->payload($execution, $status),
                ], $endedAt);
            } elseif (! ($this->explicitCommands > 0 && ($execution->meta['auto'] ?? false) && $records === [])) {
                if ($execution->hasUnhandledException && ($execution->exitCode ?? 0) === 0) {
                    $execution->exitCode = ($execution->meta['auto'] ?? false) ? 255 : 1;
                }

                $records[] = $this->records->command($execution, $endedAt);

                if (isset($execution->meta['output']) && is_string($execution->meta['output'])) {
                    $output = $execution->meta['output'];
                    $truncated = (bool) ($execution->meta['output_truncated'] ?? false) || strlen($output) > $this->config->outputMaxBytes;
                    $records[] = $this->records->output($execution, Text::tail($output, $this->config->outputMaxBytes), $truncated, $endedAt);
                }
            }

            if ($execution->userDetails !== null) {
                $records[] = $this->records->user($execution->userDetails, $endedAt);
            }

            $execution->records = [];
            $this->deliver($this->shouldKeep($execution, $endedAt) ? $records : []);
        });
    }

    /**
     * Registered (as the last shutdown function) by WatchTower::init().
     */
    public function shutdown(?ErrorHandler $errors = null): void
    {
        Guard::safely(function () use ($errors): void {
            $errors?->handleShutdown();

            $response = [];

            if ($this->execution?->source === 'request' && PHP_SAPI !== 'cli') {
                $finish = $this->config->finishRequest && (function_exists('fastcgi_finish_request') || function_exists('litespeed_finish_request'));

                if ($finish && $this->outputLevel > 0) {
                    // Flush buffers opened above the size counter so it sees the whole body.
                    while (ob_get_level() > $this->outputLevel && @ob_end_flush()) {
                    }
                }

                $code = http_response_code();
                $response = ['status_code' => is_int($code) ? $code : 200, 'response_size' => $this->responseSize()];

                if ($finish) {
                    function_exists('fastcgi_finish_request') ? @fastcgi_finish_request() : @litespeed_finish_request();
                }
            }

            $this->finish($response);

            // After the response and the ingest POST: refresh stale Shield rules.
            if ($this->execution?->source === 'request') {
                $this->shield?->afterResponse();
            }
        });
    }

    /**
     * Count response bytes with a pass-through output buffer (chunk size 1, so
     * output is never held back).
     */
    public function countResponseBytes(): void
    {
        Guard::safely(function (): void {
            $this->responseBytes = 0;

            if (ob_start(function (string $buffer): string {
                $this->responseBytes += strlen($buffer);

                return $buffer;
            }, 1)) {
                $this->outputLevel = ob_get_level();
            }
        });
    }

    /**
     * Records waiting in the current execution's buffer (for tests and debugging).
     *
     * @return list<array<string, mixed>>
     */
    public function buffered(): array
    {
        return $this->execution->records ?? [];
    }

    /**
     * @param  array<string, mixed>  $record
     */
    public function push(array $record): void
    {
        $execution = $this->execution;

        if ($execution === null || $execution->ignored) {
            return;
        }

        if (count($execution->records) >= $this->config->maxRecords) {
            $execution->dropped++;
            $type = (string) ($record['t'] ?? 'unknown');
            $execution->droppedTypes[$type] = ($execution->droppedTypes[$type] ?? 0) + 1;

            return;
        }

        $execution->records[] = $record;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     */
    private function deliver(array $records): void
    {
        if (! $this->config->enabled) {
            return;
        }

        if ($this->helloDue()) {
            array_unshift($records, $this->records->hello($this->features(), microtime(true)));
            $this->markHelloSent();
        }

        $this->ingest->deliver($records);
    }

    private function shouldKeep(Execution $execution, float $now): bool
    {
        if ($execution->sampled || $execution->forceKeep) {
            return true;
        }

        return $this->config->slowRequestMs !== null
            && ($now - $execution->startedAt) * 1000 >= $this->config->slowRequestMs;
    }

    private function roll(float $rate): bool
    {
        return match (true) {
            $rate >= 1.0 => true,
            $rate <= 0.0 => false,
            default => mt_rand() / mt_getrandmax() < $rate,
        };
    }

    /**
     * @return list<string>
     */
    private function features(): array
    {
        return array_values(array_filter([
            $this->config->captures('trace_context') ? 'trace_context' : null,
            $this->config->captures('command_output') ? 'output' : null,
            $this->config->shield['enabled'] ? 'shield' : null,
        ]));
    }

    private function helloDue(): bool
    {
        if (self::$helloSentThisProcess || ! $this->config->captures('hello') || ! $this->config->canSend()) {
            return false;
        }

        $marker = $this->helloMarker();
        $modified = is_file($marker) ? @filemtime($marker) : false;

        return $modified === false || $modified < time() - self::HELLO_INTERVAL_SECONDS;
    }

    private function markHelloSent(): void
    {
        self::$helloSentThisProcess = true;

        if ($this->ingest->spool()->ensureDirectory()) {
            @touch($this->helloMarker());
        }
    }

    private function helloMarker(): string
    {
        return $this->config->spoolDir.DIRECTORY_SEPARATOR.'hello-'.$this->config->tokenId();
    }

    /** @internal Tests reset the per-process hello flag. */
    public static function resetProcessState(): void
    {
        self::$helloSentThisProcess = false;
    }

    private function responseSize(): int
    {
        foreach (headers_list() as $header) {
            if (stripos($header, 'content-length:') === 0 && is_numeric($length = trim(substr($header, 15)))) {
                return (int) $length;
            }
        }

        return $this->responseBytes ?? 0;
    }

    /**
     * Like Nightwatch: the request payload is only kept for 500 responses, and
     * only when explicitly enabled.
     */
    private function payload(Execution $execution, int $status): string
    {
        if ($status !== 500 || in_array($execution->meta['method'] ?? 'GET', ['GET', 'HEAD', 'OPTIONS', 'TRACE'], true)) {
            return '';
        }

        if (! $this->config->captures('payload')) {
            return '{"_nightwatch_error":"NOT_ENABLED"}';
        }

        return Text::text(Text::json($this->redactor->data($_POST)));
    }

    private function isIgnoredPath(string $path): bool
    {
        foreach ($this->config->ignorePaths as $pattern) {
            if ($pattern === $path || (function_exists('fnmatch') && fnmatch($pattern, $path))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Request headers in Symfony HeaderBag::all() shape: lower-case name => list of values.
     *
     * @param  array<string, mixed>  $server
     * @return array<string, list<string>>
     */
    public static function headersFrom(array $server): array
    {
        $headers = [];

        foreach ($server as $key => $value) {
            if (! is_string($value) && ! is_numeric($value)) {
                continue;
            }

            $name = match (true) {
                str_starts_with((string) $key, 'HTTP_') => substr((string) $key, 5),
                in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH', 'CONTENT_MD5'], true) => (string) $key,
                default => null,
            };

            if ($name !== null) {
                $headers[strtolower(str_replace('_', '-', $name))] = [(string) $value];
            }
        }

        return $headers;
    }
}
