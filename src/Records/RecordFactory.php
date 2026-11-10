<?php

declare(strict_types=1);

namespace WatchTower\Records;

use WatchTower\Config;
use WatchTower\Execution;
use WatchTower\Support\Location;
use WatchTower\Support\Redactor;
use WatchTower\Support\Sql;
use WatchTower\Support\Text;
use WatchTower\WatchTower;

/**
 * Builds records in the Laravel Nightwatch v1 wire format (laravel/nightwatch
 * src/Sensors/*) plus the Watch Tower `wt-*` records this SDK emits.
 *
 * Units: timestamps are float UNIX seconds, durations integer microseconds,
 * memory bytes; headers, context, trace and payload are JSON strings.
 */
final class RecordFactory
{
    /** Stack frames that get source code snippets (ExceptionSensor caps at 10). */
    private const MAX_CODE_FRAMES = 10;

    public function __construct(
        private readonly Config $config,
        private readonly Redactor $redactor,
        private readonly Location $location,
    ) {}

    /**
     * @return array{v: int, t: string, timestamp: float, deploy: string, server: string}
     */
    private function base(string $type, float $timestamp, int $version = 1): array
    {
        return [
            'v' => $version,
            't' => $type,
            'timestamp' => $timestamp,
            'deploy' => $this->config->deploy,
            'server' => $this->config->server,
        ];
    }

    /**
     * RequestSensor. `$http` holds method, url, route_*, ip, status_code, sizes and headers.
     *
     * @param  array<string, mixed>  $http
     * @return array<string, mixed>
     */
    public function request(Execution $execution, array $http, float $endedAt): array
    {
        $routeMethods = array_values(array_map('strval', (array) ($http['route_methods'] ?? [])));
        sort($routeMethods);
        $routeDomain = (string) ($http['route_domain'] ?? '');
        $routePath = (string) ($http['route_path'] ?? '');
        $bootstrapEnd = $execution->bootstrapEndedAt ?? $execution->startedAt;
        $bootstrap = self::micros($bootstrapEnd - $execution->startedAt);
        $action = self::micros($endedAt - $bootstrapEnd);

        return [
            ...$this->base('request', $execution->startedAt),
            '_group' => hash('xxh128', implode('|', $routeMethods).",{$routeDomain},{$routePath}"),
            'trace_id' => $execution->traceId,
            'user' => $execution->userId,
            'method' => Text::tiny(strtoupper((string) ($http['method'] ?? 'GET'))),
            'url' => Text::text($this->redactor->url((string) ($http['url'] ?? ''))),
            'route_name' => Text::tiny((string) ($http['route_name'] ?? '')),
            'route_methods' => $routeMethods,
            'route_domain' => Text::tiny($routeDomain),
            'route_path' => Text::tiny($routePath),
            'route_action' => Text::tiny((string) ($http['route_action'] ?? '')),
            'ip' => Text::tiny((string) ($http['ip'] ?? '')),
            'duration' => $bootstrap + $action,
            'status_code' => (int) ($http['status_code'] ?? 0),
            'request_size' => (int) ($http['request_size'] ?? 0),
            'response_size' => (int) ($http['response_size'] ?? 0),
            'bootstrap' => $bootstrap,
            'before_middleware' => 0,
            'action' => $action,
            'render' => 0,
            'after_middleware' => 0,
            'sending' => 0,
            'terminating' => 0,
            ...$execution->counters,
            'peak_memory_usage' => memory_get_peak_usage(true),
            'exception_preview' => Text::tiny($execution->exceptionPreview),
            'context' => '{}',
            'headers' => Text::text(Text::json($this->redactor->headers((array) ($http['headers'] ?? [])))),
            'payload' => Text::text((string) ($http['payload'] ?? '')),
        ];
    }

    /**
     * CommandSensor.
     *
     * @return array<string, mixed>
     */
    public function command(Execution $execution, float $endedAt): array
    {
        $name = (string) ($execution->meta['name'] ?? 'php');
        $exitCode = $execution->exitCode ?? 0;
        $bootstrapEnd = $execution->bootstrapEndedAt ?? $execution->startedAt;
        $bootstrap = self::micros($bootstrapEnd - $execution->startedAt);
        $action = self::micros($endedAt - $bootstrapEnd);

        return [
            ...$this->base('command', $execution->startedAt),
            '_group' => hash('xxh128', $name),
            'trace_id' => $execution->traceId,
            'class' => (string) ($execution->meta['class'] ?? ''),
            'name' => $name,
            'command' => $this->redactor->text((string) ($execution->meta['command'] ?? $name)),
            'exit_code' => $exitCode < 0 || $exitCode > 255 ? 255 : $exitCode,
            'duration' => $bootstrap + $action,
            'bootstrap' => $bootstrap,
            'action' => $action,
            'terminating' => 0,
            ...$execution->counters,
            'peak_memory_usage' => memory_get_peak_usage(true),
            'exception_preview' => Text::tiny($execution->exceptionPreview),
            'context' => '{}',
        ];
    }

    /**
     * QuerySensor. `$sql` must already have its literals stripped.
     *
     * @return array<string, mixed>
     */
    public function query(Execution $execution, string $sql, float $startedAt, int $duration, string $connection, string $file, int $line): array
    {
        return [
            ...$this->base('query', $startedAt),
            '_group' => Sql::group($connection, $sql),
            ...$execution->header(),
            'sql' => Text::medium($sql),
            'file' => Text::tiny($file),
            'line' => $line,
            'duration' => max(0, $duration),
            'connection' => Text::tiny($connection),
            'connection_type' => '',
        ];
    }

    /**
     * ExceptionSensor (wire version 3, as Nightwatch sends it).
     *
     * @return array<string, mixed>
     */
    public function exception(Execution $execution, \Throwable $e, bool $handled, float $timestamp): array
    {
        [$file, $line] = $this->location->forThrowable($e);
        $code = (string) $e->getCode();

        return [
            ...$this->base('exception', $timestamp, 3),
            '_group' => hash('xxh128', $e::class.','.$code.','.$file.','.$line),
            ...$execution->header(),
            'class' => Text::text($e::class),
            'file' => Text::text($file),
            'line' => $line,
            'message' => Text::text($this->redactor->text($e->getMessage())),
            'code' => Text::text($code),
            'trace' => Text::medium($this->trace($e)),
            'handled' => $handled,
            'php_version' => PHP_VERSION,
            'laravel_version' => '',
        ];
    }

    /**
     * LogSensor.
     *
     * @param  array<array-key, mixed>  $context
     * @param  array<array-key, mixed>  $extra
     * @return array<string, mixed>
     */
    public function log(Execution $execution, string $level, string $message, array $context, array $extra, float $timestamp): array
    {
        return [
            ...$this->base('log', $timestamp),
            ...$execution->header(),
            'level' => Text::tiny($level),
            'message' => Text::text($this->redactor->text($message)),
            'context' => Text::text(Text::json($this->redactor->data((array) Text::normalize($context)))),
            'extra' => Text::text(Text::json($this->redactor->data((array) Text::normalize($extra)))),
        ];
    }

    /**
     * OutgoingRequestSensor.
     *
     * @return array<string, mixed>
     */
    public function outgoingRequest(Execution $execution, string $method, string $url, float $startedAt, int $duration, int $requestSize, int $responseSize, int $statusCode): array
    {
        $url = (string) preg_replace('#^([a-z][a-z0-9+.-]*://)[^/@?\#]*@#i', '$1', $url);
        $host = (string) (parse_url($url, PHP_URL_HOST) ?? '');

        return [
            ...$this->base('outgoing-request', $startedAt),
            '_group' => hash('xxh128', $host),
            ...$execution->header(),
            'host' => Text::tiny($host),
            'method' => Text::tiny(strtoupper($method)),
            'url' => Text::text($this->redactor->url($url)),
            'duration' => max(0, $duration),
            'request_size' => max(0, $requestSize),
            'response_size' => max(0, $responseSize),
            'status_code' => $statusCode,
        ];
    }

    /**
     * UserSensor (no deploy/server header, exactly like Nightwatch).
     *
     * @param  array{id: string, name: string, username: string}  $details
     * @return array<string, mixed>
     */
    public function user(array $details, float $timestamp): array
    {
        return [
            'v' => 1,
            't' => 'user',
            'timestamp' => $timestamp,
            'id' => Text::tiny($details['id']),
            'name' => Text::tiny($details['name']),
            'username' => Text::tiny($details['username']),
        ];
    }

    /**
     * Watch Tower agent header (contract: v, t, timestamp, deploy, server, agent_version).
     *
     * @return array<string, mixed>
     */
    private function agentBase(string $type, float $timestamp): array
    {
        return [...$this->base($type, $timestamp), 'agent_version' => WatchTower::VERSION];
    }

    /**
     * `wt-hello` with the PHP SDK extras (sdk, sdk_version, framework, service, environment).
     *
     * @param  list<string>  $features
     * @return array<string, mixed>
     */
    public function hello(array $features, float $timestamp): array
    {
        return [
            ...$this->agentBase('wt-hello', $timestamp),
            'laravel_version' => null,
            'php_version' => PHP_VERSION,
            'features' => $features,
            'schedule_catchup_version' => null,
            'sdk' => 'php',
            'sdk_version' => WatchTower::VERSION,
            'framework' => $this->config->framework,
            'service' => $this->config->service,
            'environment' => $this->config->environment,
        ];
    }

    /**
     * `wt-trace-link`: this execution was called by a traced caller.
     *
     * @param  array{parent_trace_id: string, parent_span_id: string}  $parent
     * @return array<string, mixed>
     */
    public function traceLink(Execution $execution, array $parent, float $timestamp): array
    {
        return [
            ...$this->base('wt-trace-link', $timestamp),
            'trace_id' => $execution->traceId,
            'agent_version' => WatchTower::VERSION,
            'execution_source' => $execution->source,
            'execution_id' => $execution->id(),
            'execution_stage' => $execution->stage,
            'user' => $execution->userId,
            'parent_trace_id' => $parent['parent_trace_id'],
            'parent_span_id' => $parent['parent_span_id'],
        ];
    }

    /**
     * `wt-shield`: a Shield decision that is not "allow".
     *
     * @param  array{action: string, rule: string, ip: string, method: string, path: string, user_agent: ?string, user_id: ?string, rules_version: string}  $decision
     * @return array<string, mixed>
     */
    public function shield(Execution $execution, array $decision, float $timestamp): array
    {
        return [
            ...$this->base('wt-shield', $timestamp),
            'trace_id' => $execution->traceId,
            'agent_version' => WatchTower::VERSION,
            'execution_source' => $execution->source,
            'execution_id' => $execution->id(),
            'execution_stage' => $execution->stage,
            'user' => $execution->userId,
            'action' => $decision['action'],
            'rule' => Text::tiny($decision['rule']),
            'ip' => Text::tiny($decision['ip']),
            'method' => Text::tiny($decision['method']),
            'path' => Text::text($decision['path']),
            'user_agent' => $decision['user_agent'] === null ? null : Text::restrict($decision['user_agent'], 255),
            'user_id' => $decision['user_id'],
            'rules_version' => Text::tiny($decision['rules_version']),
        ];
    }

    /**
     * `wt-output` of a command (group = command group hash, xxh128 of its name).
     *
     * @return array<string, mixed>
     */
    public function output(Execution $execution, string $output, bool $truncated, float $timestamp): array
    {
        $name = (string) ($execution->meta['name'] ?? 'php');

        return [
            ...$this->agentBase('wt-output', $timestamp),
            'kind' => 'command',
            'group' => hash('xxh128', $name),
            'trace_id' => $execution->traceId,
            'name' => $name,
            'exit_code' => $execution->exitCode ?? 0,
            'output' => $this->redactor->text($output),
            'truncated' => $truncated,
        ];
    }

    /**
     * ExceptionSensor::serializeTrace(): the throw location first, then each
     * frame as {file: "path:line", source: "Class->method(types)", code: {line: text}|null}.
     */
    private function trace(\Throwable $e): string
    {
        $snippets = 0;
        $files = [];
        $trace = [[
            'file' => $this->location->normalize($e->getFile()).':'.$e->getLine(),
            'source' => '',
            'code' => $this->snippet($e->getFile(), $e->getLine(), $snippets, $files),
        ]];

        foreach ($e->getTrace() as $frame) {
            if ($this->isSdkClass($frame['class'] ?? null)) {
                continue;
            }

            $file = isset($frame['file']) && is_string($frame['file']) ? $this->location->normalize($frame['file']) : '[internal function]';

            if (isset($frame['line']) && is_int($frame['line'])) {
                $file .= ':'.$frame['line'];
            }

            $source = ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '').'(';

            if (isset($frame['args']) && is_array($frame['args']) && $frame['args'] !== []) {
                $args = array_map(static fn (mixed $argument): string => match (gettype($argument)) {
                    'NULL' => 'null',
                    'boolean' => 'bool',
                    'integer' => 'int',
                    'double' => 'float',
                    'array' => 'array',
                    'object' => $argument::class,
                    'string' => 'string',
                    default => 'resource',
                }, $frame['args']);

                if (! array_is_list($args)) {
                    $args = array_map(static fn (string $value, int|string $key): string => "{$key}: {$value}", $args, array_keys($args));
                }

                $source .= implode(', ', $args);
            }

            $trace[] = [
                'file' => $file,
                'source' => $source.')',
                'code' => $this->snippet($frame['file'] ?? null, $frame['line'] ?? null, $snippets, $files),
            ];
        }

        $json = json_encode($trace, Text::JSON_FLAGS);

        return is_string($json) ? $json : '[]';
    }

    /**
     * Five lines of application source around a line, keyed by line number.
     *
     * @param  array<string, list<string>|null>  $files
     */
    private function snippet(mixed $file, mixed $line, int &$count, array &$files): ?\stdClass
    {
        if (! $this->config->captures('code_snippets') || $count >= self::MAX_CODE_FRAMES
            || ! is_string($file) || ! is_int($line) || ! $this->location->isApplicationFile($file)) {
            return null;
        }

        if (! array_key_exists($file, $files)) {
            $lines = is_file($file) && is_readable($file) && filesize($file) < 2_000_000 ? @file($file, FILE_IGNORE_NEW_LINES) : false;
            $files[$file] = is_array($lines) ? $lines : null;
        }

        $lines = $files[$file];

        if ($lines === null) {
            return null;
        }

        $code = new \stdClass;

        for ($number = max(1, $line - 5); $number <= min(count($lines), $line + 5); $number++) {
            $code->{(string) $number} = rtrim($lines[$number - 1], "\r\n");
        }

        $count++;

        return $code;
    }

    /** @var array<string, bool> */
    private array $sdkClasses = [];

    /** Frames of SDK classes (error handler, guards) are left out of traces. */
    private function isSdkClass(mixed $class): bool
    {
        if (! is_string($class) || ! str_starts_with($class, 'WatchTower\\')) {
            return false;
        }

        return $this->sdkClasses[$class] ??= (static function (string $class, Location $location): bool {
            try {
                $file = (new \ReflectionClass($class))->getFileName();

                return is_string($file) && $location->isSdkFile($file);
            } catch (\Throwable) {
                return false;
            }
        })($class, $this->location);
    }

    public static function micros(float $seconds): int
    {
        return max(0, (int) round($seconds * 1_000_000));
    }
}
