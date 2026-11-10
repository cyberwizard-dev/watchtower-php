<?php

declare(strict_types=1);

namespace WatchTower\Log;

use Monolog\Handler\AbstractProcessingHandler;
use Monolog\LogRecord;
use WatchTower\Client;
use WatchTower\WatchTower;

if (class_exists(AbstractProcessingHandler::class)) {
    /**
     * Monolog (2.x and 3.x) handler that forwards records to Watch Tower:
     *
     *     $logger->pushHandler(new \WatchTower\Log\MonologHandler());
     */
    class MonologHandler extends AbstractProcessingHandler
    {
        /** @var \Closure(): ?Client */
        private \Closure $client;

        /**
         * @param  int|string|object  $level  Minimum Monolog level.
         * @param  (\Closure(): ?Client)|null  $client
         */
        public function __construct($level = 'debug', bool $bubble = true, ?\Closure $client = null)
        {
            parent::__construct($level, $bubble);
            $this->client = $client ?? static fn (): ?Client => WatchTower::client();
        }

        /**
         * @param  LogRecord|array<string, mixed>  $record
         */
        protected function write($record): void
        {
            try {
                if ($record instanceof LogRecord) {
                    [$level, $message, $context, $extra, $time] = [$record->level->toPsrLogLevel(), $record->message, $record->context, $record->extra, $record->datetime];
                } else {
                    [$level, $message, $context, $extra, $time] = [strtolower((string) $record['level_name']), (string) $record['message'], (array) ($record['context'] ?? []), (array) ($record['extra'] ?? []), $record['datetime'] ?? null];
                }

                $timestamp = $time instanceof \DateTimeInterface ? (float) $time->format('U.u') : microtime(true);

                ($this->client)()?->log($level, $message, $context, $extra, $timestamp);
            } catch (\Throwable) {
                // Never break the application's logging.
            }
        }
    }
}
