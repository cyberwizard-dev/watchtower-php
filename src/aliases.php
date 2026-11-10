<?php

use WatchTower\WatchTower;

/*
 * Lets plain PHP code call `WatchTower::init([...])` without a `use` statement.
 */
if (! class_exists('WatchTower', false)) {
    class_alias(WatchTower::class, 'WatchTower');
}
