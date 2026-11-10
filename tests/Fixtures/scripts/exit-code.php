<?php

use WatchTower\Pdo\TracedPdo;

require __DIR__.'/bootstrap.php';

WatchTower::logger()->info('Nightly cleanup started', ['dry_run' => false]);
$pdo = new TracedPdo('sqlite::memory:');
$pdo->exec('create table jobs (id integer)');

WatchTower::exitCode(3);
exit(3);
