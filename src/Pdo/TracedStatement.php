<?php

declare(strict_types=1);

namespace WatchTower\Pdo;

/**
 * Statement class installed by TracedPdo: times every execute().
 */
class TracedStatement extends \PDOStatement
{
    protected function __construct(private readonly TracedPdo $connection) {}

    public function execute(?array $params = null): bool
    {
        $startedAt = microtime(true);
        $result = parent::execute($params);

        if ($result) {
            $this->connection->recordQuery($this->queryString, $startedAt, microtime(true));
        }

        return $result;
    }
}
