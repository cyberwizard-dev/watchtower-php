<?php

declare(strict_types=1);

namespace WatchTower\Tests\Unit;

use WatchTower\Client;
use WatchTower\ErrorHandler;
use WatchTower\Tests\Support\TestCase;

final class ErrorHandlerTest extends TestCase
{
    /** @var list<array{int, string}> */
    private array $previousCalls = [];

    private int $reporting = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousCalls = [];
    }

    private function install(array $options = []): Client
    {
        set_error_handler(function (int $level, string $message): bool {
            $this->previousCalls[] = [$level, $message];

            return true;
        });

        $client = $this->client($options);
        $client->startRequest(self::server());
        (new ErrorHandler($client))->install();

        // PHPUnit narrows error_reporting() while a test runs; report everything here.
        $this->reporting = error_reporting(E_ALL);

        return $client;
    }

    private function uninstall(): void
    {
        error_reporting($this->reporting);
        restore_error_handler();
        restore_exception_handler();
        restore_error_handler();
    }

    public function test_warnings_become_handled_exceptions_and_the_previous_handler_still_runs(): void
    {
        $client = $this->install();

        trigger_error('Custom warning', E_USER_WARNING);
        $line = __LINE__ - 1;
        $this->uninstall();

        $exceptions = self::ofType($client->buffered(), 'exception');
        $this->assertCount(1, $exceptions);
        $this->assertSame('ErrorException', $exceptions[0]['class']);
        $this->assertSame('Custom warning', $exceptions[0]['message']);
        $this->assertTrue($exceptions[0]['handled']);
        $this->assertSame($line, $exceptions[0]['line']);
        $this->assertSame('tests/Unit/ErrorHandlerTest.php', $exceptions[0]['file']);
        $this->assertSame([[E_USER_WARNING, 'Custom warning']], $this->previousCalls, 'previous handler is chained');

        $trace = json_decode($exceptions[0]['trace'], true);
        foreach ($trace as $frame) {
            $this->assertStringNotContainsString('WatchTower\\ErrorHandler', $frame['source'], 'SDK frames are left out');
        }
    }

    public function test_silenced_errors_are_not_reported_but_still_chained(): void
    {
        $client = $this->install();

        @trigger_error('Silenced', E_USER_NOTICE);
        $this->uninstall();

        $this->assertSame([], self::ofType($client->buffered(), 'exception'));
        $this->assertSame([[E_USER_NOTICE, 'Silenced']], $this->previousCalls);
    }

    public function test_error_reporting_is_respected(): void
    {
        $client = $this->install();
        error_reporting(E_ALL & ~E_USER_NOTICE);

        trigger_error('Not reported', E_USER_NOTICE);
        trigger_error('Reported', E_USER_WARNING);
        $this->uninstall();

        $exceptions = self::ofType($client->buffered(), 'exception');
        $this->assertCount(1, $exceptions);
        $this->assertSame('Reported', $exceptions[0]['message']);
    }

    public function test_configured_error_mask_filters_further(): void
    {
        $client = $this->install(['error_reporting' => E_ALL & ~E_USER_WARNING]);

        trigger_error('Masked', E_USER_WARNING);
        $this->uninstall();

        $this->assertSame([], self::ofType($client->buffered(), 'exception'));
    }

    public function test_deprecations_are_opt_in(): void
    {
        $client = $this->install();
        trigger_error('Old API', E_USER_DEPRECATED);
        $this->uninstall();
        $this->assertSame([], self::ofType($client->buffered(), 'exception'));

        $client = $this->install(['capture' => ['deprecations' => true, 'hello' => false]]);
        trigger_error('Old API', E_USER_DEPRECATED);
        $this->uninstall();
        $this->assertCount(1, self::ofType($client->buffered(), 'exception'));
    }

    public function test_repeated_warnings_are_capped(): void
    {
        $client = $this->install();

        for ($i = 0; $i < 20; $i++) {
            trigger_error('Loop warning', E_USER_WARNING);
        }

        $this->uninstall();

        $this->assertCount(5, self::ofType($client->buffered(), 'exception'));
        $this->assertCount(20, $this->previousCalls);
    }

    public function test_uncaught_exceptions_are_reported_and_passed_to_the_previous_handler(): void
    {
        $received = null;
        set_exception_handler(static function (\Throwable $e) use (&$received): void {
            $received = $e;
        });

        $client = $this->client();
        $client->startRequest(self::server());
        $handler = new ErrorHandler($client);
        $handler->install();

        $exception = new \LogicException('Unhandled');
        $handler->handleException($exception);

        restore_error_handler();
        restore_exception_handler();
        restore_exception_handler();

        $this->assertSame($exception, $received);
        $record = self::one($client->buffered(), 'exception');
        $this->assertFalse($record['handled']);
        $this->assertSame('LogicException', $record['class']);
    }
}
