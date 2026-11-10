<?php

declare(strict_types=1);

namespace WatchTower\Support;

/**
 * Finds the application frame responsible for a query, call or error and
 * turns absolute paths into paths relative to the project root.
 */
final class Location
{
    /** @var list<string> */
    private array $sdkPaths;

    /**
     * @param  list<string>  $skip  Extra path fragments to treat as library code.
     */
    public function __construct(
        private readonly string $basePath,
        private readonly array $skip = [],
    ) {
        $this->sdkPaths = basename(__DIR__) === 'Support'
            ? [dirname(__DIR__).DIRECTORY_SEPARATOR, dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'wordpress'.DIRECTORY_SEPARATOR]
            : [__FILE__];
    }

    public function isSdkFile(string $file): bool
    {
        foreach ($this->sdkPaths as $path) {
            if ($file === $path || str_starts_with($file, $path)) {
                return true;
            }
        }

        foreach ($this->skip as $fragment) {
            if ($fragment !== '' && str_contains($file, $fragment)) {
                return true;
            }
        }

        return false;
    }

    public function isVendorFile(string $file): bool
    {
        return str_contains($file, DIRECTORY_SEPARATOR.'vendor'.DIRECTORY_SEPARATOR);
    }

    /** Application code (not SDK, not vendor), used to decide where code snippets are read. */
    public function isApplicationFile(string $file): bool
    {
        return ! $this->isSdkFile($file) && ! $this->isVendorFile($file);
    }

    /**
     * First application frame of a backtrace, else the first non-SDK frame.
     *
     * @param  list<array<string, mixed>>  $trace
     * @return array{0: string, 1: int}
     */
    public function find(array $trace): array
    {
        $fallback = null;

        foreach ($trace as $frame) {
            $file = $frame['file'] ?? null;

            if (! is_string($file) || $this->isSdkFile($file)) {
                continue;
            }

            $line = (int) ($frame['line'] ?? 0);

            if (! $this->isVendorFile($file)) {
                return [$this->normalize($file), $line];
            }

            $fallback ??= [$this->normalize($file), $line];
        }

        return $fallback ?? ['', 0];
    }

    /**
     * Location of a throwable: its own file unless that is library code.
     *
     * @return array{0: string, 1: int}
     */
    public function forThrowable(\Throwable $e): array
    {
        if ($this->isApplicationFile($e->getFile())) {
            return [$this->normalize($e->getFile()), $e->getLine()];
        }

        foreach ($e->getTrace() as $frame) {
            if (isset($frame['file']) && is_string($frame['file']) && $this->isApplicationFile($frame['file'])) {
                return [$this->normalize($frame['file']), (int) ($frame['line'] ?? 0)];
            }
        }

        return [$this->normalize($e->getFile()), $e->getLine()];
    }

    public function normalize(string $file): string
    {
        if ($this->basePath !== '' && str_starts_with($file, $this->basePath.DIRECTORY_SEPARATOR)) {
            return substr($file, strlen($this->basePath) + 1);
        }

        return $file;
    }
}
