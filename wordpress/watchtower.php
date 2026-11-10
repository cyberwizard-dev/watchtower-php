<?php

use WatchTower\WatchTower;
use WatchTower\WordPress\Integration;

/**
 * Plugin Name: Watch Tower
 * Description: Reports requests, errors, database queries and logs to a self-hosted Watch Tower server.
 * Version: 0.1.0
 * Author: Cyberwizard
 * License: MIT
 *
 * Install as a must-use plugin:
 *   wp-content/mu-plugins/watchtower.php              (this file)
 *   wp-content/mu-plugins/watchtower-sdk/watchtower.php (dist/watchtower.php of the SDK)
 * or install the SDK with Composer (cyberwizard/watchtower-php) and load its autoloader first.
 *
 * Configure in wp-config.php:
 *   define('WATCHTOWER_TOKEN', 'your-environment-token');
 *   define('WATCHTOWER_URL', 'https://watchtower.example.com');
 *   // optional:
 *   define('WATCHTOWER_DEPLOY', 'v1.4.2');
 *   define('WATCHTOWER_OPTIONS', ['sample_rate' => 0.5, 'ignore_paths' => ['/health', '/wp-cron.php']]);
 *   define('WATCHTOWER_SDK_PATH', '/path/to/watchtower.php');
 */
if (! defined('ABSPATH') || ! defined('WATCHTOWER_TOKEN') || ! defined('WATCHTOWER_URL')) {
    return;
}

if (! class_exists('WatchTower\WatchTower')) {
    $watchtower_candidates = [
        defined('WATCHTOWER_SDK_PATH') ? (string) WATCHTOWER_SDK_PATH : '',
        __DIR__.'/watchtower-sdk/watchtower.php',
        dirname(__DIR__).'/dist/watchtower.php',
        dirname(__DIR__).'/vendor/autoload.php',
    ];

    foreach ($watchtower_candidates as $watchtower_candidate) {
        if ($watchtower_candidate !== '' && is_file($watchtower_candidate)) {
            require_once $watchtower_candidate;
            break;
        }
    }

    unset($watchtower_candidates, $watchtower_candidate);

    if (! class_exists('WatchTower\WatchTower')) {
        return;
    }
}

(static function (): void {
    global $wp_version;

    $options = defined('WATCHTOWER_OPTIONS') && is_array(WATCHTOWER_OPTIONS) ? WATCHTOWER_OPTIONS : [];

    $client = WatchTower::init([
        'service' => function_exists('get_bloginfo') ? (string) get_bloginfo('name') : null,
        'deploy' => defined('WATCHTOWER_DEPLOY') ? (string) WATCHTOWER_DEPLOY : 'wordpress-'.(string) $wp_version,
        'base_path' => rtrim(ABSPATH, '/\\'),
        ...$options,
        'token' => (string) WATCHTOWER_TOKEN,
        'base_url' => (string) WATCHTOWER_URL,
        'framework' => 'wordpress',
        'location_skip' => [
            DIRECTORY_SEPARATOR.'wp-includes'.DIRECTORY_SEPARATOR.'class-wpdb.php',
            DIRECTORY_SEPARATOR.'wp-includes'.DIRECTORY_SEPARATOR.'wp-db.php',
            DIRECTORY_SEPARATOR.'wp-includes'.DIRECTORY_SEPARATOR.'class-wp-hook.php',
            DIRECTORY_SEPARATOR.'wp-includes'.DIRECTORY_SEPARATOR.'plugin.php',
            ...(array) ($options['location_skip'] ?? []),
        ],
    ]);

    if ($client === null || ! $client->config->enabled) {
        return;
    }

    Integration::register($client);
})();
