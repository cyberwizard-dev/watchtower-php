<?php

use WatchTower\Shield\FileCounter;

/*
 * Concurrency fixture: count $argv[2] hits on one FileCounter key in $argv[1].
 */

require dirname(__DIR__, 3).'/vendor/autoload.php';

$counter = new FileCounter($argv[1]);

for ($i = 0; $i < (int) $argv[2]; $i++) {
    $counter->attempt('concurrency', PHP_INT_MAX, 86400, 0, time());
}
