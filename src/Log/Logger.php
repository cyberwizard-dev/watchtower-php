<?php

declare(strict_types=1);

namespace WatchTower\Log;

use Psr\Log\LoggerInterface;

if (interface_exists(LoggerInterface::class)) {
    /**
     * PSR-3 logger for Watch Tower (psr/log is installed).
     */
    final class Logger extends BaseLogger implements LoggerInterface {}
} else {
    /**
     * PSR-3 compatible logger for Watch Tower (psr/log is not installed).
     */
    final class Logger extends BaseLogger {}
}
