<?php

declare(strict_types=1);

namespace WatchTower;

use WatchTower\Support\Uuid;

/**
 * State of the request or command currently being observed: its trace id,
 * the counters Nightwatch reports, and the records buffered for it.
 */
final class Execution
{
    public string $traceId;

    public string $preview = '';

    public string $stage = 'action';

    public string $userId = '';

    /** @var array{id: string, name: string, username: string}|null */
    public ?array $userDetails = null;

    /** @var array<string, int> */
    public array $counters = [
        'exceptions' => 0,
        'logs' => 0,
        'queries' => 0,
        'lazy_loads' => 0,
        'jobs_queued' => 0,
        'mail' => 0,
        'notifications' => 0,
        'outgoing_requests' => 0,
        'files_read' => 0,
        'files_written' => 0,
        'cache_events' => 0,
        'hydrated_models' => 0,
    ];

    public string $exceptionPreview = '';

    public bool $hasUnhandledException = false;

    /** Set when something (an exception, a slow query, a Shield decision) means the execution is kept even if not sampled. */
    public bool $forceKeep = false;

    public bool $ignored = false;

    /** @var list<array<string, mixed>> */
    public array $records = [];

    public int $dropped = 0;

    /** @var array<string, int> */
    public array $droppedTypes = [];

    /**
     * Request: route and HTTP details; command: name, command line and class.
     *
     * @var array<string, mixed>
     */
    public array $meta = [];

    public ?int $exitCode = null;

    public ?float $bootstrapEndedAt = null;

    /**
     * @param  'request'|'command'  $source
     */
    public function __construct(
        public readonly string $source,
        public readonly float $startedAt,
        public readonly bool $sampled = true,
        ?string $traceId = null,
    ) {
        $this->traceId = $traceId ?? Uuid::v4();
    }

    /** Nightwatch uses the trace id as the execution id of requests and commands. */
    public function id(): string
    {
        return $this->traceId;
    }

    /**
     * The execution header copied into every in-execution record (QuerySensor.php:60-72).
     *
     * @return array<string, string>
     */
    public function header(): array
    {
        return [
            'trace_id' => $this->traceId,
            'execution_source' => $this->source,
            'execution_id' => $this->id(),
            'execution_preview' => $this->preview,
            'execution_stage' => $this->stage,
            'user' => $this->userId,
        ];
    }
}
