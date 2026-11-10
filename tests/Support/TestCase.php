<?php

declare(strict_types=1);

namespace WatchTower\Tests\Support;

use PHPUnit\Framework\Assert;
use WatchTower\Client;
use WatchTower\Config;
use WatchTower\WatchTower;

abstract class TestCase extends \PHPUnit\Framework\TestCase
{
    protected string $spoolDir;

    protected FakeHttpClient $http;

    protected function setUp(): void
    {
        parent::setUp();

        $this->spoolDir = sys_get_temp_dir().'/watchtower-test-'.bin2hex(random_bytes(6));
        $this->http = new FakeHttpClient;
        Client::resetProcessState();
    }

    protected function tearDown(): void
    {
        WatchTower::reset();
        self::removeDirectory($this->spoolDir);

        parent::tearDown();
    }

    /**
     * @param  array<string, mixed>  $options
     */
    protected function client(array $options = []): Client
    {
        return new Client(new Config([
            'token' => 'test-token',
            'base_url' => 'https://watchtower.test',
            'deploy' => 'v1.0.0',
            'server' => 'web-1',
            'spool_dir' => $this->spoolDir,
            'base_path' => dirname(__DIR__, 2),
            'capture' => ['hello' => false],
            ...$options,
        ]), $this->http);
    }

    /**
     * @param  array<string, mixed>  $server
     * @return array<string, mixed>
     */
    protected static function server(array $server = []): array
    {
        return [
            'REQUEST_METHOD' => 'GET',
            'REQUEST_URI' => '/users/42?page=2&token=secret-value',
            'HTTP_HOST' => 'app.test',
            'HTTPS' => 'on',
            'REMOTE_ADDR' => '203.0.113.4',
            'HTTP_ACCEPT' => 'text/html',
            'HTTP_AUTHORIZATION' => 'Bearer abc',
            'HTTP_X_API_KEY' => 'k',
            'HTTP_USER_AGENT' => 'PHPUnit',
            'REQUEST_TIME_FLOAT' => microtime(true) - 0.05,
            ...$server,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return list<array<string, mixed>>
     */
    protected static function ofType(array $records, string $type): array
    {
        return array_values(array_filter($records, static fn (array $record): bool => $record['t'] === $type));
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    protected static function one(array $records, string $type): array
    {
        $matches = self::ofType($records, $type);
        Assert::assertCount(1, $matches, "Expected exactly one {$type} record");

        return $matches[0];
    }

    public static function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }

        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $path = $directory.'/'.$entry;
            is_dir($path) ? self::removeDirectory($path) : @unlink($path);
        }

        @rmdir($directory);
    }

    /**
     * Run a PHP script in a fresh process.
     *
     * @param  array<string, string>  $env
     * @return array{exit: int, stdout: string, stderr: string}
     */
    protected static function runPhp(string $script, array $env = [], array $args = []): array
    {
        $command = [PHP_BINARY, '-d', 'display_errors=stderr', '-d', 'log_errors=0', $script, ...$args];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, [...getenv(), ...$env]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return ['exit' => proc_close($process), 'stdout' => (string) $stdout, 'stderr' => (string) $stderr];
    }

    /**
     * Every record spooled in a directory.
     *
     * @return list<array<string, mixed>>
     */
    protected static function spooled(string $directory): array
    {
        $records = [];

        foreach (glob($directory.'/batch-*.jsonl') ?: [] as $file) {
            foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                array_push($records, ...json_decode($line, true, 512, JSON_THROW_ON_ERROR)['records']);
            }
        }

        return $records;
    }
}
