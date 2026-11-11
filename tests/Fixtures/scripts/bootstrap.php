<?php

/*
 * Shared setup of the subprocess fixtures: load the SDK (Composer or the
 * single-file build in WT_SDK_FILE) and init it from WT_* environment variables.
 */

$sdk = getenv('WT_SDK_FILE');
require is_string($sdk) && $sdk !== '' ? $sdk : dirname(__DIR__, 3).'/vendor/autoload.php';

WatchTower::init([
    'token' => 'script-token',
    'base_url' => getenv('WT_URL') ?: 'http://127.0.0.1:9',
    'mode' => getenv('WT_MODE') ?: 'spool',
    'spool_dir' => getenv('WT_SPOOL_DIR'),
    'deploy' => 'v2.0.0',
    'server' => 'script-host',
    'capture' => ['hello' => false],
    'browser_key' => getenv('WT_BROWSER_KEY') ?: null,
    'shield' => [
        'enabled' => getenv('WT_SHIELD_DIR') !== false && getenv('WT_SHIELD_DIR') !== '',
        'cache_dir' => getenv('WT_SHIELD_DIR') ?: null,
        'turnstile_secret' => 'fixture-secret',
    ],
]);
