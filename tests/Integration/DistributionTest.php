<?php

declare(strict_types=1);

namespace WatchTower\Tests\Integration;

use WatchTower\Tests\Support\BuiltInServer;
use WatchTower\Tests\Support\FakeHttpClient;
use WatchTower\Tests\Support\TestCase;
use WatchTower\Tests\Support\WireFixtures;

/**
 * The single-file build and the spool flush CLI.
 */
final class DistributionTest extends TestCase
{
    public function test_single_file_build_works_without_composer(): void
    {
        $file = $this->spoolDir.'-dist/watchtower.php';
        $build = self::runPhp(dirname(__DIR__, 2).'/build/build.php', [], [$file]);
        $this->assertSame(0, $build['exit'], $build['stderr']);

        $lint = self::runPhp('-l', [], [$file]);
        $this->assertSame(0, $lint['exit'], $lint['stdout'].$lint['stderr']);

        $result = self::runPhp(dirname(__DIR__).'/Fixtures/scripts/exit-code.php', ['WT_SPOOL_DIR' => $this->spoolDir, 'WT_SDK_FILE' => $file]);
        self::removeDirectory(dirname($file));

        $this->assertSame(3, $result['exit'], $result['stderr']);
        $command = self::one(self::spooled($this->spoolDir), 'command');
        WireFixtures::assertShape($command);
        $this->assertSame(1, $command['queries']);
    }

    public function test_committed_dist_file_is_up_to_date(): void
    {
        $file = $this->spoolDir.'-fresh.php';
        self::runPhp(dirname(__DIR__, 2).'/build/build.php', [], [$file]);
        $fresh = (string) file_get_contents($file);
        @unlink($file);

        $this->assertSame($fresh, file_get_contents(dirname(__DIR__, 2).'/dist/watchtower.php'), 'Run `composer build` to refresh dist/watchtower.php');
    }

    public function test_flush_cli_drains_the_spool(): void
    {
        $spooler = self::runPhp(dirname(__DIR__).'/Fixtures/scripts/exit-code.php', ['WT_SPOOL_DIR' => $this->spoolDir]);
        $this->assertSame(3, $spooler['exit'], $spooler['stderr']);
        $this->assertNotEmpty(self::spooled($this->spoolDir));

        $bin = dirname(__DIR__, 2).'/bin/watchtower-flush';
        $status = self::runPhp($bin, [], ['--status', '--spool-dir='.$this->spoolDir]);
        $this->assertStringContainsString('1 batches queued', $status['stdout']);

        $server = new BuiltInServer;

        try {
            $server->respondWith(500);
            $failed = self::runPhp($bin, [], ['--token=cli-token', '--url='.$server->url, '--spool-dir='.$this->spoolDir]);
            $this->assertSame(1, $failed['exit']);
            $this->assertNotEmpty(self::spooled($this->spoolDir), 'failed batches stay queued');

            $server->respondWith(202);
            $flushed = self::runPhp($bin, [], ['--token=cli-token', '--url='.$server->url, '--spool-dir='.$this->spoolDir]);
            $this->assertSame(0, $flushed['exit'], $flushed['stdout'].$flushed['stderr']);
            $this->assertStringContainsString('Sent 1 batches, 0 left', $flushed['stdout']);
            $this->assertSame([], self::spooled($this->spoolDir));

            $requests = $server->requests();
            $last = end($requests);
            $this->assertSame('Bearer cli-token', $last['headers']['authorization']);
            $this->assertSame('exit-code.php', self::one(FakeHttpClient::decode($last['body']), 'command')['name']);
        } finally {
            $server->stop();
        }
    }

    public function test_flush_cli_respects_the_auth_back_off(): void
    {
        $server = new BuiltInServer;

        try {
            self::runPhp(dirname(__DIR__).'/Fixtures/scripts/exit-code.php', ['WT_SPOOL_DIR' => $this->spoolDir]);
            $server->respondWith(401);
            $bin = dirname(__DIR__, 2).'/bin/watchtower-flush';
            self::runPhp($bin, [], ['--token=bad', '--url='.$server->url, '--spool-dir='.$this->spoolDir]);
            $count = count($server->requests());

            $again = self::runPhp($bin, [], ['--token=bad', '--url='.$server->url, '--spool-dir='.$this->spoolDir]);
            $this->assertStringContainsString('rejected the token', $again['stdout']);
            $this->assertCount($count, $server->requests(), 'no request during the back-off');
        } finally {
            $server->stop();
        }
    }
}
