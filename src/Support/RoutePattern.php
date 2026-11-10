<?php

declare(strict_types=1);

namespace WatchTower\Support;

/**
 * Turns a concrete URL path into a route-like pattern so requests group
 * sensibly when the application does not name its routes:
 * `/users/42/posts/9f1c...` becomes `/users/{id}/posts/{uuid}`.
 */
final class RoutePattern
{
    public static function fromPath(string $path): string
    {
        $path = '/'.ltrim($path, '/');

        if ($path === '/') {
            return '/';
        }

        $segments = array_map(static function (string $segment): string {
            return match (true) {
                $segment === '' => '',
                preg_match('/^\d+$/', $segment) === 1 => '{id}',
                preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $segment) === 1 => '{uuid}',
                preg_match('/^[0-9a-f]{16,}$/i', $segment) === 1 => '{hash}',
                preg_match('/^[0-9A-HJKMNP-TV-Z]{26}$/', $segment) === 1 => '{ulid}',
                default => $segment,
            };
        }, explode('/', substr($path, 1)));

        return '/'.implode('/', $segments);
    }

    /**
     * Turn a WordPress REST route regex (`/wp/v2/posts/(?P<id>[\d]+)`) into `/wp/v2/posts/{id}`.
     */
    public static function fromRegex(string $route): string
    {
        $pattern = (string) preg_replace('/\(\?P<([A-Za-z0-9_]+)>(?:[^()]|\([^()]*\))*\)/', '{$1}', $route);

        return '/'.ltrim(str_replace(['^', '$'], '', $pattern), '/');
    }
}
