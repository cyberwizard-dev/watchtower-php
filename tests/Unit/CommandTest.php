<?php

declare(strict_types=1);

namespace WatchTower\Tests\Unit;

use WatchTower\Tests\Support\TestCase;
use WatchTower\Tests\Support\WireFixtures;

final class CommandTest extends TestCase
{
    public function test_wrapped_command_with_output_capture(): void
    {
        $client = $this->client();

        ob_start();
        $result = $client->runCommand('backup.php', static function (): int {
            echo "Backed up 12 tables\n";
            echo "token=abc\n";

            return 0;
        }, true);
        $printed = ob_get_clean();

        $this->assertSame(0, $result);
        $this->assertSame("Backed up 12 tables\ntoken=abc\n", $printed, 'output still reaches the terminal');

        $records = $this->http->records();
        $command = self::one($records, 'command');
        $output = self::one($records, 'wt-output');
        WireFixtures::assertShape($command);
        WireFixtures::assertShape($output);

        $this->assertSame('command', $output['kind']);
        $this->assertSame($command['_group'], $output['group']);
        $this->assertSame($command['trace_id'], $output['trace_id']);
        $this->assertSame("Backed up 12 tables\ntoken=[redacted]\n", $output['output']);
        $this->assertFalse($output['truncated']);
        $this->assertSame(0, $output['exit_code']);
    }

    public function test_long_output_keeps_the_tail(): void
    {
        $client = $this->client(['output_max_bytes' => 1024]);

        ob_start();
        $client->runCommand('noisy.php', static function (): void {
            for ($i = 0; $i < 500; $i++) {
                echo "line {$i}\n";
            }
        }, true);
        ob_end_clean();

        $output = self::one($this->http->records(), 'wt-output');
        $this->assertTrue($output['truncated']);
        $this->assertLessThanOrEqual(1024, strlen($output['output']));
        $this->assertStringEndsWith("line 499\n", $output['output']);
    }

    public function test_failing_command_reports_exception_and_exit_code(): void
    {
        $client = $this->client();

        try {
            $client->runCommand('import.php', static function (): never {
                throw new \RuntimeException('CSV missing');
            });
            $this->fail('the exception is re-thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('CSV missing', $e->getMessage());
        }

        $records = $this->http->records();
        $command = self::one($records, 'command');
        $exception = self::one($records, 'exception');

        $this->assertSame(1, $command['exit_code']);
        $this->assertSame(1, $command['exceptions']);
        $this->assertSame('CSV missing', $command['exception_preview']);
        $this->assertSame('command', $exception['execution_source']);
        $this->assertSame($command['trace_id'], $exception['trace_id']);
        $this->assertSame('import.php', $exception['execution_preview']);
        $this->assertFalse($exception['handled']);
    }

    public function test_return_values_map_to_exit_codes(): void
    {
        $client = $this->client();
        $client->runCommand('a', static fn (): bool => false);
        $client->runCommand('b', static fn (): string => 'done');

        $this->assertSame([1, 0], array_column(self::ofType($this->http->records(), 'command'), 'exit_code'));
    }

    public function test_the_outer_execution_is_restored(): void
    {
        $client = $this->client();
        $outer = $client->startRequest(self::server());
        $client->runCommand('inner', static fn (): int => 0);

        $this->assertSame($outer, $client->execution());
    }
}
