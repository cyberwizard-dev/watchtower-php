<?php

declare(strict_types=1);

namespace WatchTower\Tests\Unit;

use WatchTower\Tests\Support\TestCase;
use WatchTower\Tests\Support\WireFixtures;

final class RecordShapeTest extends TestCase
{
    private const BUILDERS = '/../../../Laravel/watch-tower/tests/Support/NightwatchRecords.php';

    public function test_fixtures_match_the_watch_tower_builders(): void
    {
        $builders = getenv('WATCHTOWER_BUILDERS') ?: __DIR__.self::BUILDERS;

        if (! is_file($builders)) {
            $this->markTestSkipped('watch-tower/tests/Support/NightwatchRecords.php is not available (set WATCHTOWER_BUILDERS).');
        }

        $result = self::runPhp(dirname(__DIR__).'/Fixtures/dump-builders.php', [], [$builders]);
        $this->assertSame(0, $result['exit'], $result['stderr']);
        $actual = json_decode($result['stdout'], true, 512, JSON_THROW_ON_ERROR);

        foreach (WireFixtures::builders() as $type => $fields) {
            $this->assertSame(array_keys($actual[$type]), array_keys($fields), "{$type}: fixture fields differ from the builder");

            foreach ($fields as $field => $fieldType) {
                $builderType = $actual[$type][$field];
                $this->assertTrue(
                    ltrim($fieldType, '?') === $builderType || (str_starts_with($fieldType, '?') && $builderType === 'null'),
                    "{$type}.{$field}: fixture {$fieldType}, builder {$builderType}",
                );
            }
        }
    }

    public function test_request_record_shape(): void
    {
        $client = $this->client();
        $client->startRequest(self::server());
        $client->finish(['status_code' => 200, 'response_size' => 10]);

        $request = self::one($this->http->records(), 'request');
        WireFixtures::assertShape($request);

        $this->assertSame(1, $request['v']);
        $this->assertSame('GET', $request['method']);
        $this->assertSame('/users/{id}', $request['route_path']);
        $this->assertSame(['GET'], $request['route_methods']);
        $this->assertSame(hash('xxh128', 'GET,,/users/{id}'), $request['_group']);
        $this->assertSame(200, $request['status_code']);
        $this->assertSame(10, $request['response_size']);
        $this->assertSame('203.0.113.4', $request['ip']);
        $this->assertSame('v1.0.0', $request['deploy']);
        $this->assertSame('web-1', $request['server']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $request['trace_id']);
        $this->assertGreaterThanOrEqual(40_000, $request['duration'], 'duration is in microseconds');
        $this->assertSame($request['duration'], $request['bootstrap'] + $request['action']);
        $this->assertGreaterThan(1_000_000, $request['peak_memory_usage']);
        $this->assertIsArray(json_decode($request['headers'], true));
    }

    public function test_command_record_shape(): void
    {
        $client = $this->client();
        $client->runCommand('backup.php', static fn (): int => 3);

        $command = self::one($this->http->records(), 'command');
        WireFixtures::assertShape($command);

        $this->assertSame('backup.php', $command['name']);
        $this->assertSame(hash('xxh128', 'backup.php'), $command['_group']);
        $this->assertSame(3, $command['exit_code']);
    }

    public function test_execution_records_share_the_execution_header(): void
    {
        $client = $this->client();
        $execution = $client->startRequest(self::server());
        $client->user(42, 'Ada Lovelace', 'ada');
        $client->query('select * from users where id = ?', microtime(true), 1500, 'mysql');
        $client->log('error', 'Payment failed', ['order' => 1234]);
        $client->captureException(new \RuntimeException('Something broke'), true);
        $client->outgoingRequest('POST', 'https://api.stripe.com/v1/charges?api_key=sk', microtime(true), 300000, 100, 400, 200);
        $client->finish(['status_code' => 200]);

        $records = $this->http->records();

        foreach (['query', 'log', 'exception', 'outgoing-request', 'user', 'request'] as $type) {
            WireFixtures::assertShape(self::one($records, $type));
        }

        foreach (['query', 'log', 'exception', 'outgoing-request'] as $type) {
            $record = self::one($records, $type);
            $this->assertSame($execution->traceId, $record['trace_id']);
            $this->assertSame('request', $record['execution_source']);
            $this->assertSame($execution->traceId, $record['execution_id']);
            $this->assertSame('GET /users/42', $record['execution_preview']);
            $this->assertSame('42', $record['user']);
        }

        $request = self::one($records, 'request');
        $this->assertSame(1, $request['queries']);
        $this->assertSame(1, $request['logs']);
        $this->assertSame(1, $request['exceptions']);
        $this->assertSame(1, $request['outgoing_requests']);
        $this->assertSame('42', $request['user']);

        $user = self::one($records, 'user');
        $this->assertSame(['42', 'Ada Lovelace', 'ada'], [$user['id'], $user['name'], $user['username']]);

        $outgoing = self::one($records, 'outgoing-request');
        $this->assertSame(hash('xxh128', 'api.stripe.com'), $outgoing['_group']);
        $this->assertSame('api.stripe.com', $outgoing['host']);
    }

    public function test_exception_record_follows_the_nightwatch_trace_format(): void
    {
        $client = $this->client();
        $client->startRequest(self::server());
        $exception = new \RuntimeException('Something broke', 7);
        $line = __LINE__ - 1;
        $client->captureException($exception, false);
        $client->finish(['status_code' => 200]);

        $records = $this->http->records();
        $record = self::one($records, 'exception');
        WireFixtures::assertShape($record);

        $file = 'tests/Unit/RecordShapeTest.php';
        $this->assertSame(3, $record['v'], 'Nightwatch sends exceptions as wire version 3');
        $this->assertSame('RuntimeException', $record['class']);
        $this->assertSame($file, $record['file']);
        $this->assertSame($line, $record['line']);
        $this->assertSame('7', $record['code']);
        $this->assertFalse($record['handled']);
        $this->assertSame(hash('xxh128', "RuntimeException,7,{$file},{$line}"), $record['_group']);

        $trace = json_decode($record['trace'], true);
        $this->assertSame("{$file}:{$line}", $trace[0]['file']);
        $this->assertSame('', $trace[0]['source']);
        $this->assertArrayHasKey((string) $line, $trace[0]['code']);
        $this->assertStringContainsString('Something broke', $trace[0]['code'][(string) $line]);
        $this->assertStringContainsString('->test_exception_record_follows_the_nightwatch_trace_format()', $trace[1]['source']);

        $request = self::one($records, 'request');
        $this->assertSame(500, $request['status_code'], 'unhandled exceptions mark the request as failed');
        $this->assertSame('Something broke', $request['exception_preview']);
    }

    public function test_query_group_matches_nightwatch(): void
    {
        $client = $this->client();
        $client->startRequest(self::server());
        $client->query('select * from "users" where "id" in (?, ?, ?)', microtime(true), 10, 'mysql');
        $client->query('insert into logs (a, b) values (?, ?), (?, ?)', microtime(true), 10, 'mysql');
        $client->finish();

        [$select, $insert] = self::ofType($this->http->records(), 'query');
        $this->assertSame(hash('xxh128', 'mysql,select * from "users" where "id" in (...?)'), $select['_group']);
        $this->assertSame(hash('xxh128', 'mysql,insert into logs (a, b) values ...'), $insert['_group']);
    }
}
