<?php

declare(strict_types=1);

namespace WatchTower\Tests\Integration;

use WatchTower\Tests\Support\TestCase;
use WatchTower\Tests\Support\WireFixtures;

/**
 * Behaviour that needs a real PHP process: shutdown handling, fatal errors,
 * uncaught exceptions and automatic CLI command records.
 */
final class ProcessTest extends TestCase
{
    private const SCRIPTS = __DIR__.'/../Fixtures/scripts/';

    /**
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function script(string $name, array $env = []): array
    {
        return self::runPhp(self::SCRIPTS.$name, ['WT_SPOOL_DIR' => $this->spoolDir, ...$env]);
    }

    public function test_fatal_errors_are_reported_at_shutdown(): void
    {
        $result = $this->script('fatal.php');

        $this->assertSame(255, $result['exit']);
        $this->assertStringContainsString('Allowed memory size', $result['stderr']);

        $records = self::spooled($this->spoolDir);
        $exception = self::one($records, 'exception');
        WireFixtures::assertShape($exception);
        $this->assertSame('WatchTower\\FatalError', $exception['class']);
        $this->assertStringContainsString('Allowed memory size', $exception['message']);
        $this->assertFalse($exception['handled']);
        $this->assertStringEndsWith('fatal.php', $exception['file']);

        $command = self::one($records, 'command');
        $this->assertSame(255, $command['exit_code']);
        $this->assertSame('fatal.php', $command['name']);
        $this->assertSame($command['trace_id'], $exception['trace_id']);
    }

    public function test_uncaught_exceptions_keep_php_behaviour(): void
    {
        $result = $this->script('uncaught.php');

        $this->assertSame(255, $result['exit']);
        $this->assertStringContainsString('Uncaught RuntimeException: Import failed', $result['stderr']);

        $records = self::spooled($this->spoolDir);
        $exception = self::one($records, 'exception');
        $this->assertSame('RuntimeException', $exception['class']);
        $this->assertFalse($exception['handled']);
        $this->assertStringContainsString('import_rows()', json_decode($exception['trace'], true)[1]['source']);

        $command = self::one($records, 'command');
        WireFixtures::assertShape($command);
        $this->assertSame(255, $command['exit_code']);
        $this->assertSame(1, $command['exceptions']);
        $this->assertSame('Import failed', $command['exception_preview']);
    }

    public function test_cli_scripts_are_recorded_as_commands(): void
    {
        $result = $this->script('exit-code.php');
        $this->assertSame(3, $result['exit']);

        $records = self::spooled($this->spoolDir);
        $command = self::one($records, 'command');
        WireFixtures::assertShape($command);
        $this->assertSame('exit-code.php', $command['name']);
        $this->assertSame(hash('xxh128', 'exit-code.php'), $command['_group']);
        $this->assertSame(3, $command['exit_code']);
        $this->assertSame('v2.0.0', $command['deploy']);
        $this->assertSame('script-host', $command['server']);
        $this->assertSame(1, $command['logs']);
        $this->assertSame(1, $command['queries']);

        $log = self::one($records, 'log');
        $this->assertSame('command', $log['execution_source']);
        $this->assertSame($command['trace_id'], $log['trace_id']);
        $this->assertSame('exit-code.php', $log['execution_preview']);
    }

    public function test_wrapped_commands_replace_the_script_record(): void
    {
        $result = $this->script('wrapped.php');

        $this->assertSame(0, $result['exit']);
        $this->assertSame("dumped 3 tables\ncopied 10 files\n", $result['stdout']);

        $records = self::spooled($this->spoolDir);
        $commands = self::ofType($records, 'command');
        $this->assertSame(['backup:database', 'backup:files'], array_column($commands, 'name'));
        $this->assertSame([0, 2], array_column($commands, 'exit_code'));

        $outputs = self::ofType($records, 'wt-output');
        $this->assertSame(["dumped 3 tables\n", "copied 10 files\n"], array_column($outputs, 'output'));
        $this->assertSame([hash('xxh128', 'backup:database'), hash('xxh128', 'backup:files')], array_column($outputs, 'group'));
    }
}
