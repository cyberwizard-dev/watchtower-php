<?php

declare(strict_types=1);

namespace WatchTower\Tests\Support;

/**
 * A `php -S` process running tests/Fixtures/ingest-server.php.
 */
final class BuiltInServer
{
    /** @var resource */
    private $process;

    public readonly string $url;

    public readonly string $captureDir;

    /**
     * @param  array<string, string>  $env
     */
    public function __construct(?string $router = null, array $env = [])
    {
        $this->captureDir = sys_get_temp_dir().'/watchtower-capture-'.bin2hex(random_bytes(6));
        mkdir($this->captureDir);

        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = (int) substr((string) stream_socket_get_name($socket, false), strrpos((string) stream_socket_get_name($socket, false), ':') + 1);
        fclose($socket);

        $this->url = 'http://127.0.0.1:'.$port;
        $this->process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=0', '-d', 'log_errors=0', '-S', '127.0.0.1:'.$port, $router ?? dirname(__DIR__).'/Fixtures/ingest-server.php'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            [...getenv(), 'WT_CAPTURE_DIR' => $this->captureDir, ...$env],
        );

        $deadline = microtime(true) + 5;

        while (microtime(true) < $deadline) {
            $connection = @fsockopen('127.0.0.1', $port, $errno, $error, 0.1);

            if ($connection !== false) {
                fclose($connection);

                return;
            }

            usleep(20_000);
        }

        throw new \RuntimeException('The PHP built-in server did not start.');
    }

    public function respondWith(int $status): void
    {
        file_put_contents($this->captureDir.'/status', (string) $status);
    }

    /**
     * Captured ingest requests, oldest first.
     *
     * @return list<array{method: string, headers: array<string, string>, body: string, status: int}>
     */
    public function requests(): array
    {
        $files = glob($this->captureDir.'/request-*.json') ?: [];
        sort($files);

        return array_map(static function (string $file): array {
            $request = json_decode((string) file_get_contents($file), true);
            $request['body'] = base64_decode($request['body']);

            return $request;
        }, $files);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function records(): array
    {
        $records = [];

        foreach ($this->requests() as $request) {
            array_push($records, ...FakeHttpClient::decode($request['body'], ['Content-Encoding' => $request['headers']['content-encoding'] ?? '']));
        }

        return $records;
    }

    public function stop(): void
    {
        proc_terminate($this->process);
        proc_close($this->process);
        TestCase::removeDirectory($this->captureDir);
    }
}
