<?php

require __DIR__.'/bootstrap.php';

WatchTower::command('backup:database', static function (): int {
    echo "dumped 3 tables\n";

    return 0;
}, true);

WatchTower::command('backup:files', static function (): int {
    echo "copied 10 files\n";

    return 2;
}, true);
