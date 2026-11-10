<?php

require __DIR__.'/bootstrap.php';

ini_set('memory_limit', '16M');
$chunks = [];

while (true) {
    $chunks[] = str_repeat('x', 1024 * 1024);
}
