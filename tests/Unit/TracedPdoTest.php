<?php

declare(strict_types=1);

namespace WatchTower\Tests\Unit;

use WatchTower\Client;
use WatchTower\Pdo\TracedPdo;
use WatchTower\Support\Sql;
use WatchTower\Tests\Support\TestCase;
use WatchTower\Tests\Support\WireFixtures;

final class TracedPdoTest extends TestCase
{
    public function test_queries_are_recorded_with_caller_and_stripped_literals(): void
    {
        $client = $this->client();
        $client->startRequest(self::server());
        $pdo = new TracedPdo('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION], null, static fn (): Client => $client);

        $pdo->exec('create table users (id integer primary key, email text)');
        $pdo->exec("insert into users (email) values ('ada@example.com')");
        $statement = $pdo->prepare('select * from users where id = ?');
        $statement->execute([1]);
        $line = __LINE__ - 1;
        $rows = $pdo->query('select count(*) from users where id > 0')->fetchColumn();

        $this->assertSame(1, (int) $rows);
        $this->assertSame([['id' => 1, 'email' => 'ada@example.com']], $statement->fetchAll(\PDO::FETCH_ASSOC));

        $queries = self::ofType($client->buffered(), 'query');
        $this->assertCount(4, $queries);

        foreach ($queries as $query) {
            WireFixtures::assertShape($query);
            $this->assertSame('sqlite', $query['connection']);
            $this->assertSame('tests/Unit/TracedPdoTest.php', $query['file']);
            $this->assertIsInt($query['duration']);
        }

        $this->assertSame("insert into users (email) values ('?')", $queries[1]['sql']);
        $this->assertStringNotContainsString('ada@example.com', json_encode($queries));
        $this->assertSame('select * from users where id = ?', $queries[2]['sql']);
        $this->assertSame($line, $queries[2]['line']);
        $this->assertSame(Sql::group('sqlite', 'select * from users where id = ?'), $queries[2]['_group']);
        $this->assertSame('select count(*) from users where id > ?', $queries[3]['sql']);
    }

    public function test_failed_queries_are_not_recorded_and_errors_propagate(): void
    {
        $client = $this->client();
        $client->startRequest(self::server());
        $pdo = new TracedPdo('sqlite::memory:', null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION], 'reporting', static fn (): Client => $client);

        try {
            $pdo->query('select * from missing');
            $this->fail('expected a PDOException');
        } catch (\PDOException) {
        }

        $this->assertSame([], self::ofType($client->buffered(), 'query'));
        $this->assertSame('reporting', $pdo->connectionName());
    }

    public function test_works_without_an_initialised_sdk(): void
    {
        $pdo = new TracedPdo('sqlite::memory:');
        $this->assertSame(0, $pdo->exec('create table t (id integer)'));
        $this->assertSame([], $pdo->query('select * from t')->fetchAll());
    }
}
