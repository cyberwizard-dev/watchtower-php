<?php

declare(strict_types=1);

namespace WatchTower\Pdo;

use WatchTower\Client;
use WatchTower\WatchTower;

/**
 * Drop-in PDO that records every successful query (prepare/execute, query,
 * exec) as a Nightwatch `query` record: literals stripped, duration in
 * microseconds, connection name and the application file/line that ran it.
 *
 *     $pdo = new TracedPdo('mysql:host=db;dbname=app', 'user', 'secret', [], 'mysql');
 */
class TracedPdo extends \PDO
{
    private string $connectionName;

    /** @var \Closure(): ?Client */
    private \Closure $client;

    /**
     * @param  array<int, mixed>|null  $options
     * @param  string|null  $connectionName  Shown as the query's connection (default: the driver name).
     * @param  (\Closure(): ?Client)|null  $client
     */
    public function __construct(string $dsn, ?string $username = null, ?string $password = null, ?array $options = null, ?string $connectionName = null, ?\Closure $client = null)
    {
        parent::__construct($dsn, $username, $password, $options);

        $this->client = $client ?? static fn (): ?Client => WatchTower::client();
        $this->connectionName = $connectionName ?? (string) $this->getAttribute(\PDO::ATTR_DRIVER_NAME);

        // Custom statement classes are not allowed on persistent connections.
        if (! ($options[\PDO::ATTR_PERSISTENT] ?? false)) {
            $this->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [TracedStatement::class, [$this]]);
        }
    }

    public function connectionName(): string
    {
        return $this->connectionName;
    }

    public function exec(string $statement): int|false
    {
        $startedAt = microtime(true);
        $result = parent::exec($statement);

        if ($result !== false) {
            $this->recordQuery($statement, $startedAt, microtime(true));
        }

        return $result;
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): \PDOStatement|false
    {
        $startedAt = microtime(true);
        $result = $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);

        if ($result !== false) {
            $this->recordQuery($query, $startedAt, microtime(true));
        }

        return $result;
    }

    /** @internal Called by TracedPdo and TracedStatement. */
    public function recordQuery(string $sql, float $startedAt, float $endedAt): void
    {
        try {
            ($this->client)()?->query($sql, $startedAt, (int) round(($endedAt - $startedAt) * 1_000_000), $this->connectionName);
        } catch (\Throwable) {
            // Never interfere with the query itself.
        }
    }
}
