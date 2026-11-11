<?php

declare(strict_types=1);

namespace WatchTower\WordPress;

use WatchTower\Client;
use WatchTower\Support\RoutePattern;

/**
 * WordPress hooks used by the `wordpress/watchtower.php` mu-plugin: request
 * routes from the main query / REST route / admin screen, the current user,
 * and `$wpdb` queries.
 *
 * Query timing: with SAVEQUERIES enabled the exact durations WordPress logs
 * are used (the caller is then the function list WordPress records). Without
 * it, a query starts at the `query` filter and ends at the next WordPress
 * hook that fires afterwards, which is an upper bound (it includes any PHP work
 * between the query and that hook), but file/line point at the real caller.
 */
final class Integration
{
    private static ?Client $client = null;

    /** @var array{sql: string, start: float, file: string, line: int}|null */
    private static ?array $pending = null;

    private static ?\Closure $closer = null;

    private static string $template = '';

    /** Form actions that print inside the form, where the Shield honeypot goes. */
    public const HONEYPOT_FORMS = ['comment_form', 'login_form', 'register_form', 'lostpassword_form'];

    /**
     * The `client_ip` option for the mu-plugin: WATCHTOWER_OPTIONS['client_ip'] wins, then the
     * WATCHTOWER_CLIENT_IP_PRESET, WATCHTOWER_CLIENT_IP_HEADER and WATCHTOWER_TRUSTED_PROXIES
     * constants (a list or a comma-separated string).
     *
     * @param  array<string, mixed>  $options
     * @param  (callable(string): mixed)|null  $constant  reads a constant, null when undefined
     * @return array<string, mixed>
     */
    public static function clientIpOptions(array $options, ?callable $constant = null): array
    {
        $constant ??= static fn (string $name): mixed => defined($name) ? constant($name) : null;
        $clientIp = is_array($options['client_ip'] ?? null) ? $options['client_ip'] : [];

        foreach (['preset' => 'WATCHTOWER_CLIENT_IP_PRESET', 'header' => 'WATCHTOWER_CLIENT_IP_HEADER', 'trusted_proxies' => 'WATCHTOWER_TRUSTED_PROXIES'] as $key => $name) {
            $value = $constant($name);

            if (! isset($clientIp[$key]) && (is_string($value) || is_array($value)) && $value !== '' && $value !== []) {
                $clientIp[$key] = $value;
            }
        }

        return $clientIp;
    }

    public static function register(Client $client): void
    {
        self::$client = $client;
        self::$pending = null;
        self::$template = '';

        if (function_exists('wp_doing_ajax') && wp_doing_ajax()) {
            $action = isset($_REQUEST['action']) && is_string($_REQUEST['action']) ? preg_replace('/[^a-z0-9_\-]/', '', strtolower($_REQUEST['action'])) : '';
            $client->route('/wp-admin/admin-ajax.php', 'ajax'.($action !== '' ? '.'.$action : ''));
        } elseif (function_exists('wp_doing_cron') && wp_doing_cron()) {
            $client->route('/wp-cron.php', 'cron');
        } elseif (($GLOBALS['pagenow'] ?? '') === 'wp-login.php') {
            $client->route('/wp-login.php', 'login');
        }

        add_filter('template_include', [self::class, 'template'], PHP_INT_MAX);
        add_action('wp', [self::class, 'routeFromQuery'], PHP_INT_MAX);
        add_action('current_screen', [self::class, 'routeFromScreen'], PHP_INT_MAX);
        add_filter('rest_dispatch_request', [self::class, 'routeFromRest'], 10, 4);
        add_action('init', [self::class, 'user'], PHP_INT_MAX);
        add_action('shutdown', [self::class, 'shutdown'], PHP_INT_MAX);

        // Shield: the honeypot field in the comment, login, registration and lost password forms.
        if ($client->config->shield['enabled']) {
            foreach (self::HONEYPOT_FORMS as $hook) {
                add_action($hook, [self::class, 'honeypot']);
            }
        }

        // Browser monitoring: the script tag early in <head> (the proxy itself is served by the
        // mu-plugin, before WordPress loads its plugins).
        if ($client->browser()->enabled()) {
            add_action('wp_head', [self::class, 'browserTag'], 1);
        }

        if ($client->config->captures('queries') && ! (defined('SAVEQUERIES') && SAVEQUERIES)) {
            self::$closer = static function (): void {
                self::closePendingQuery();
            };
            add_filter('query', [self::class, 'queryStarted'], PHP_INT_MAX);
        }
    }

    /** Form actions: print the hidden Shield honeypot input. */
    public static function honeypot(): void
    {
        try {
            if (self::$client !== null) {
                echo self::$client->shield()->honeypotField();
            }
        } catch (\Throwable) {
            // Never break the form.
        }
    }

    /** `wp_head` action: print the browser monitoring script tag. */
    public static function browserTag(): void
    {
        try {
            if (self::$client !== null) {
                echo self::$client->browser()->tag();
            }
        } catch (\Throwable) {
            // Never break the page.
        }
    }

    /** `template_include` filter: remember the theme template that renders the page. */
    public static function template(mixed $template): mixed
    {
        if (is_string($template) && $template !== '') {
            self::$template = basename($template);
            self::routeFromQuery();
        }

        return $template;
    }

    /** `wp` action: name the request after the template type of the main query. */
    public static function routeFromQuery(): void
    {
        try {
            if (self::$client === null) {
                return;
            }

            [$path, $name] = self::templateRoute();
            self::$client->route($path, $name, self::$template !== '' ? 'theme/'.self::$template : null);
        } catch (\Throwable) {
            // Never break the page.
        }
    }

    /** `current_screen` action (wp-admin). */
    public static function routeFromScreen(mixed $screen = null): void
    {
        try {
            $page = (string) ($GLOBALS['pagenow'] ?? 'index.php');
            $id = is_object($screen) && isset($screen->id) ? (string) $screen->id : basename($page, '.php');
            self::$client?->route('/wp-admin/'.$page, 'admin.'.$id);
        } catch (\Throwable) {
        }
    }

    /** `rest_dispatch_request` filter: `/wp/v2/posts/(?P<id>[\d]+)` becomes `/wp-json/wp/v2/posts/{id}`. */
    public static function routeFromRest(mixed $result, mixed $request = null, mixed $route = null, mixed $handler = null): mixed
    {
        try {
            if (is_string($route) && self::$client !== null) {
                $method = is_object($request) && method_exists($request, 'get_method') ? (string) $request->get_method() : null;
                self::$client->route('/wp-json'.RoutePattern::fromRegex($route), 'rest', null, $method !== null ? [$method] : null);
            }
        } catch (\Throwable) {
        }

        return $result;
    }

    /** `init` / `shutdown`: attribute the request to the logged-in user (login name, never the email). */
    public static function user(): void
    {
        try {
            if (self::$client === null || ! function_exists('wp_get_current_user')) {
                return;
            }

            $user = wp_get_current_user();

            if (is_object($user) && isset($user->ID) && (int) $user->ID > 0) {
                self::$client->user((int) $user->ID, (string) ($user->display_name ?? ''), (string) ($user->user_login ?? ''));
            }
        } catch (\Throwable) {
        }
    }

    public static function shutdown(): void
    {
        self::closePendingQuery();
        self::user();
        self::recordSavedQueries();
    }

    /** `query` filter: a `$wpdb` query is about to run. */
    public static function queryStarted(mixed $sql): mixed
    {
        try {
            self::closePendingQuery();

            if (! is_string($sql) || self::$client === null || self::$closer === null) {
                return $sql;
            }

            [$file, $line] = self::$client->location->find(debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 30));
            self::$pending = ['sql' => $sql, 'start' => microtime(true), 'file' => $file, 'line' => $line];
            add_filter('all', self::$closer);
        } catch (\Throwable) {
        }

        return $sql;
    }

    /** The next hook after a query (or shutdown) ends it. */
    public static function closePendingQuery(): void
    {
        $pending = self::$pending;

        if ($pending === null) {
            return;
        }

        self::$pending = null;

        try {
            if (self::$closer !== null) {
                remove_filter('all', self::$closer);
            }

            self::$client?->query($pending['sql'], $pending['start'], (int) round((microtime(true) - $pending['start']) * 1_000_000), 'wpdb', $pending['file'], $pending['line']);
        } catch (\Throwable) {
        }
    }

    /** SAVEQUERIES: exact durations from `$wpdb->queries` ([sql, seconds, callers, start, data]). */
    private static function recordSavedQueries(): void
    {
        try {
            global $wpdb;

            if (! (defined('SAVEQUERIES') && SAVEQUERIES) || self::$client === null || ! is_object($wpdb) || ! is_array($wpdb->queries ?? null)
                || ! self::$client->config->captures('queries')) {
                return;
            }

            $now = microtime(true);

            foreach ($wpdb->queries as $query) {
                if (! is_array($query) || ! is_string($query[0] ?? null)) {
                    continue;
                }

                $seconds = is_numeric($query[1] ?? null) ? (float) $query[1] : 0.0;
                $start = is_numeric($query[3] ?? null) ? (float) $query[3] : $now - $seconds;
                $callers = array_map('trim', explode(',', (string) ($query[2] ?? '')));

                self::$client->query($query[0], $start, (int) round($seconds * 1_000_000), 'wpdb', (string) end($callers), 0);
            }

            $wpdb->queries = [];
        } catch (\Throwable) {
        }
    }

    /**
     * @return array{0: string, 1: string}
     */
    private static function templateRoute(): array
    {
        $is = static fn (string $tag): bool => function_exists($tag) && (bool) $tag();

        return match (true) {
            $is('is_404') => ['', 'wp.404'],
            $is('is_feed') => ['/feed', 'wp.feed'],
            $is('is_search') => ['/search', 'wp.search'],
            $is('is_front_page') => ['/', 'wp.front_page'],
            $is('is_home') => ['/{posts_page}', 'wp.home'],
            $is('is_attachment') => ['/attachment/{slug}', 'wp.attachment'],
            $is('is_page') => ['/{page}', 'wp.page'],
            $is('is_singular') => ['/'.self::postType().'/{slug}', 'wp.single.'.self::postType()],
            $is('is_category') => ['/category/{slug}', 'wp.category'],
            $is('is_tag') => ['/tag/{slug}', 'wp.tag'],
            $is('is_tax') => ['/{taxonomy}/{term}', 'wp.taxonomy'],
            $is('is_author') => ['/author/{author}', 'wp.author'],
            $is('is_date') => ['/{date}', 'wp.date'],
            $is('is_post_type_archive') => ['/'.self::postType(), 'wp.archive.'.self::postType()],
            $is('is_archive') => ['/{archive}', 'wp.archive'],
            default => ['/', 'wp'],
        };
    }

    private static function postType(): string
    {
        $type = function_exists('get_post_type') ? get_post_type() : null;

        if (! is_string($type) && function_exists('get_query_var')) {
            $type = get_query_var('post_type');
            $type = is_array($type) ? reset($type) : $type;
        }

        return is_string($type) && $type !== '' ? $type : 'post';
    }

    /** @internal */
    public static function reset(): void
    {
        self::$client = null;
        self::$pending = null;
        self::$closer = null;
        self::$template = '';
    }
}
