<?php

/*
 * A plain PHP "application" served by `php -S`, reporting to WT_URL.
 */

require __DIR__.'/../scripts/bootstrap.php';

WatchTower::handleBrowserProxy();

$path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

if ($path === '/health') {
    echo 'ok';

    return true;
}

if ($path === '/page') {
    echo '<head>'.WatchTower::browserTag().'</head>';

    return true;
}

if ($path === '/form') {
    echo '<form method="post">'.WatchTower::honeypotField().'</form>';

    return true;
}

if (preg_match('#^/orders/(\d+)$#', $path, $match) === 1) {
    WatchTower::route('/orders/{id}', 'orders.show', 'orders.php');
    WatchTower::user(9, 'Grace Hopper', 'grace');
    WatchTower::logger()->warning('Order {id} is late', ['id' => (int) $match[1]]);
    http_response_code(201);
    header('Content-Type: text/plain');
    echo str_repeat('o', 1000);

    return true;
}

if ($path === '/boom') {
    trigger_error('Undefined thing', E_USER_WARNING);
    throw new LogicException('Checkout exploded');
}

http_response_code(404);
echo 'missing';

return true;
