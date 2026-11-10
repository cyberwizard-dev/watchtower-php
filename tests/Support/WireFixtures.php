<?php

declare(strict_types=1);

namespace WatchTower\Tests\Support;

use PHPUnit\Framework\Assert;

/**
 * Field => type maps of the Watch Tower test builders
 * (watch-tower/tests/Support/NightwatchRecords.php, the defaults of each
 * builder), which mirror laravel/nightwatch v1 sensors. `?` marks fields the
 * builders fill with a value but the contract allows to be null.
 *
 * EXTRA lists fields the real Nightwatch sensors (or the SDK contract) send on
 * top of the builder defaults; nothing else may appear.
 */
final class WireFixtures
{
    private const BASE = ['v' => 'int', 't' => 'string', 'timestamp' => 'float', 'deploy' => 'string', 'server' => 'string', 'trace_id' => 'string'];

    private const AGENT_BASE = ['v' => 'int', 't' => 'string', 'timestamp' => 'float', 'deploy' => 'string', 'server' => 'string', 'agent_version' => 'string'];

    private const COUNTERS = [
        'exceptions' => 'int', 'logs' => 'int', 'queries' => 'int', 'lazy_loads' => 'int', 'jobs_queued' => 'int',
        'mail' => 'int', 'notifications' => 'int', 'outgoing_requests' => 'int', 'files_read' => 'int',
        'files_written' => 'int', 'cache_events' => 'int', 'hydrated_models' => 'int',
    ];

    /**
     * @return array<string, array<string, string>>
     */
    public static function builders(): array
    {
        return [
            'request' => [
                ...self::BASE,
                '_group' => 'string', 'user' => 'string', 'method' => 'string', 'url' => 'string', 'route_name' => 'string',
                'route_methods' => 'array', 'route_domain' => 'string', 'route_path' => 'string', 'route_action' => 'string',
                'ip' => 'string', 'duration' => 'int', 'status_code' => 'int', 'request_size' => 'int', 'response_size' => 'int',
                'bootstrap' => 'int', 'before_middleware' => 'int', 'action' => 'int', 'render' => 'int',
                'after_middleware' => 'int', 'sending' => 'int', 'terminating' => 'int',
                'exceptions' => 'int', 'logs' => 'int', 'queries' => 'int', 'lazy_loads' => 'int', 'jobs_queued' => 'int',
                'mail' => 'int', 'notifications' => 'int', 'outgoing_requests' => 'int', 'files_read' => 'int',
                'files_written' => 'int', 'cache_events' => 'int', 'hydrated_models' => 'int',
                'peak_memory_usage' => 'int', 'exception_preview' => 'string', 'context' => 'string', 'headers' => 'string', 'payload' => 'string',
            ],
            'query' => [
                ...self::BASE,
                '_group' => 'string', 'execution_source' => 'string', 'execution_id' => 'string', 'execution_preview' => 'string',
                'execution_stage' => 'string', 'user' => 'string', 'sql' => 'string', 'file' => 'string', 'line' => 'int',
                'duration' => 'int', 'connection' => 'string', 'connection_type' => 'string',
            ],
            'exception' => [
                ...self::BASE,
                '_group' => 'string', 'execution_source' => 'string', 'execution_id' => 'string', 'execution_preview' => 'string',
                'execution_stage' => 'string', 'user' => 'string', 'class' => 'string', 'file' => 'string', 'line' => 'int',
                'message' => 'string', 'code' => 'string', 'trace' => 'string', 'handled' => 'bool',
                'php_version' => 'string', 'laravel_version' => 'string',
            ],
            'log' => [
                ...self::BASE,
                'execution_source' => 'string', 'execution_id' => 'string', 'level' => 'string', 'message' => 'string',
                'context' => 'string', 'extra' => 'string',
            ],
            'command' => [
                ...self::BASE,
                '_group' => 'string', 'class' => 'string', 'name' => 'string', 'command' => 'string', 'exit_code' => 'int', 'duration' => 'int',
            ],
            'outgoing-request' => [
                ...self::BASE,
                '_group' => 'string', 'execution_source' => 'string', 'execution_id' => 'string', 'host' => 'string',
                'method' => 'string', 'url' => 'string', 'duration' => 'int', 'request_size' => 'int', 'response_size' => 'int',
                'status_code' => 'int',
            ],
            'user' => ['v' => 'int', 't' => 'string', 'timestamp' => 'float', 'id' => 'string', 'name' => 'string', 'username' => 'string'],
            'wt-hello' => [
                ...self::AGENT_BASE,
                'laravel_version' => '?string', 'php_version' => 'string', 'features' => 'array', 'schedule_catchup_version' => '?string',
            ],
            'wt-trace-link' => [
                ...self::BASE,
                'agent_version' => 'string', 'execution_source' => 'string', 'execution_id' => 'string', 'execution_stage' => 'string',
                'user' => 'string', 'parent_trace_id' => 'string', 'parent_span_id' => 'string',
            ],
            'wt-output' => [
                ...self::AGENT_BASE,
                'kind' => 'string', 'group' => 'string', 'trace_id' => '?string', 'name' => 'string', 'exit_code' => '?int',
                'output' => 'string', 'truncated' => 'bool',
            ],
        ];
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function extras(): array
    {
        return [
            'request' => [],
            'query' => [],
            'exception' => [],
            // LogSensor sends the full execution header.
            'log' => ['execution_preview' => 'string', 'execution_stage' => 'string', 'user' => 'string'],
            // CommandSensor sends stage durations and counters.
            'command' => [
                'bootstrap' => 'int', 'action' => 'int', 'terminating' => 'int', ...self::COUNTERS,
                'peak_memory_usage' => 'int', 'exception_preview' => 'string', 'context' => 'string',
            ],
            'outgoing-request' => ['execution_preview' => 'string', 'execution_stage' => 'string', 'user' => 'string'],
            'user' => [],
            // SDK additions to wt-hello.
            'wt-hello' => ['sdk' => 'string', 'sdk_version' => 'string', 'framework' => '?string', 'service' => '?string', 'environment' => '?string'],
            'wt-trace-link' => [],
            'wt-output' => [],
        ];
    }

    /**
     * Types from the record contract (wt-record-contract.md) that the Watch Tower builders don't
     * cover yet: exact field => type maps, checked by assertShape() but not against the builders.
     *
     * @return array<string, array<string, string>>
     */
    public static function contractOnly(): array
    {
        return [
            'wt-shield' => [
                ...self::BASE,
                'agent_version' => 'string', 'execution_source' => 'string', 'execution_id' => 'string', 'execution_stage' => 'string',
                'user' => 'string', 'action' => 'string', 'rule' => 'string', 'ip' => 'string', 'method' => 'string', 'path' => 'string',
                'user_agent' => '?string', 'user_id' => '?string', 'rules_version' => 'string',
            ],
        ];
    }

    /**
     * Assert a record has exactly the builder's fields (plus the allowed extras) with matching types.
     *
     * @param  array<string, mixed>  $record
     */
    public static function assertShape(array $record): void
    {
        $type = $record['t'] ?? null;
        Assert::assertIsString($type);
        $expected = self::contractOnly()[$type] ?? null;

        if ($expected === null) {
            Assert::assertArrayHasKey($type, self::builders(), "No fixture for record type {$type}");
            $expected = [...self::builders()[$type], ...self::extras()[$type]];
        }

        Assert::assertSame([], array_values(array_diff(array_keys($expected), array_keys($record))), "{$type}: missing fields");
        Assert::assertSame([], array_values(array_diff(array_keys($record), array_keys($expected))), "{$type}: unexpected fields");

        foreach ($expected as $field => $fieldType) {
            $nullable = str_starts_with($fieldType, '?');
            $fieldType = ltrim($fieldType, '?');
            $actual = get_debug_type($record[$field]);

            if ($nullable && $record[$field] === null) {
                continue;
            }

            Assert::assertSame($fieldType, $actual, "{$type}.{$field} should be {$fieldType}");
        }
    }
}
