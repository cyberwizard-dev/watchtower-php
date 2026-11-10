<?php

/*
 * The handful of WordPress functions the integration uses, with a tiny hook
 * system that (like WordPress) runs `all` callbacks before every hook.
 */

$GLOBALS['wt_hooks'] = [];
$GLOBALS['wt_conditionals'] = [];
$GLOBALS['wt_current_user'] = (object) ['ID' => 0];

function add_filter(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    $GLOBALS['wt_hooks'][$hook][spl_object_id(Closure::fromCallable($callback)).':'.(is_array($callback) ? implode('::', $callback) : spl_object_id((object) $callback))] = [$callback, $args, $priority];

    return true;
}

function add_action(string $hook, callable $callback, int $priority = 10, int $args = 1): bool
{
    return add_filter($hook, $callback, $priority, $args);
}

function remove_filter(string $hook, callable $callback): bool
{
    foreach ($GLOBALS['wt_hooks'][$hook] ?? [] as $key => [$registered]) {
        if ($registered === $callback) {
            unset($GLOBALS['wt_hooks'][$hook][$key]);

            return true;
        }
    }

    return false;
}

function apply_filters(string $hook, mixed $value, mixed ...$args): mixed
{
    foreach ($GLOBALS['wt_hooks']['all'] ?? [] as [$callback]) {
        $callback($hook);
    }

    foreach ($GLOBALS['wt_hooks'][$hook] ?? [] as [$callback, $count]) {
        $value = $callback(...array_slice([$value, ...$args], 0, $count));
    }

    return $value;
}

function do_action(string $hook, mixed ...$args): void
{
    apply_filters($hook, $args[0] ?? null, ...array_slice($args, 1));
}

function wt_set_conditionals(array $tags): void
{
    $GLOBALS['wt_conditionals'] = $tags;
}

foreach (['is_404', 'is_feed', 'is_search', 'is_front_page', 'is_home', 'is_attachment', 'is_page', 'is_singular', 'is_category', 'is_tag', 'is_tax', 'is_author', 'is_date', 'is_post_type_archive', 'is_archive'] as $tag) {
    eval("function {$tag}(): bool { return in_array('{$tag}', \$GLOBALS['wt_conditionals'], true); }");
}

function get_post_type(): string|false
{
    return $GLOBALS['wt_post_type'] ?? false;
}

function wp_get_current_user(): object
{
    return $GLOBALS['wt_current_user'];
}

function wp_doing_ajax(): bool
{
    return $GLOBALS['wt_doing_ajax'] ?? false;
}

function wp_doing_cron(): bool
{
    return false;
}

/** Simulates $wpdb->query(): the `query` filter, then the database call. */
function wt_wpdb_query(string $sql, int $micros): void
{
    apply_filters('query', $sql);
    usleep($micros);
}
