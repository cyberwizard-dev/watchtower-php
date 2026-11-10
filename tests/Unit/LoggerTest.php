<?php

declare(strict_types=1);

namespace WatchTower\Tests\Unit;

use Monolog\Logger as Monolog;
use Psr\Log\LoggerInterface;
use WatchTower\Client;
use WatchTower\Log\Logger;
use WatchTower\Log\MonologHandler;
use WatchTower\Tests\Support\TestCase;
use WatchTower\Tests\Support\WireFixtures;

final class LoggerTest extends TestCase
{
    public function test_logger_implements_psr3_and_writes_log_records(): void
    {
        $client = $this->client();
        $client->startRequest(self::server());
        $logger = new Logger(static fn (): Client => $client);

        $this->assertInstanceOf(LoggerInterface::class, $logger);

        $logger->error('Payment {id} failed', ['id' => 1234, 'password' => 'hunter2']);
        $logger->warning(new class implements \Stringable
        {
            public function __toString(): string
            {
                return 'stringable message';
            }
        });
        $logger->log('not-a-level', 'ignored');

        $logs = self::ofType($client->buffered(), 'log');
        $this->assertCount(2, $logs);
        WireFixtures::assertShape($logs[0]);
        $this->assertSame('error', $logs[0]['level']);
        $this->assertSame('Payment {id} failed', $logs[0]['message']);
        $this->assertSame(['id' => 1234, 'password' => '[redacted]'], json_decode($logs[0]['context'], true));
        $this->assertSame('stringable message', $logs[1]['message']);
    }

    public function test_minimum_level(): void
    {
        $client = $this->client(['log_level' => 'warning']);
        $client->startRequest(self::server());
        $logger = new Logger(static fn (): Client => $client);

        $logger->info('too chatty');
        $logger->critical('kept');

        $this->assertSame(['kept'], array_column(self::ofType($client->buffered(), 'log'), 'message'));
    }

    public function test_logger_is_a_no_op_before_init(): void
    {
        $logger = new Logger(static fn (): ?Client => null);
        $logger->error('nothing happens');
        $this->addToAssertionCount(1);
    }

    public function test_monolog_handler_forwards_records(): void
    {
        $client = $this->client();
        $client->startRequest(self::server());

        $monolog = new Monolog('app');
        $monolog->pushHandler(new MonologHandler('info', true, static fn (): Client => $client));
        $monolog->pushProcessor(static function ($record) {
            $record->extra['request_id'] = 'abc';

            return $record;
        });

        $monolog->debug('below the handler level');
        $monolog->notice('Cache warmed', ['keys' => 3, 'api_key' => 'k']);

        $log = self::one($client->buffered(), 'log');
        WireFixtures::assertShape($log);
        $this->assertSame('notice', $log['level']);
        $this->assertSame('Cache warmed', $log['message']);
        $this->assertSame(['keys' => 3, 'api_key' => '[redacted]'], json_decode($log['context'], true));
        $this->assertSame(['request_id' => 'abc'], json_decode($log['extra'], true));
    }
}
