<?php

declare(strict_types=1);

namespace WatchTower\Browser;

use WatchTower\Config;
use WatchTower\Shield\CurlShieldHttp;
use WatchTower\Shield\ShieldHttp;
use WatchTower\Shield\ShieldResponse;
use WatchTower\Shield\StreamShieldHttp;
use WatchTower\Support\Guard;

/**
 * Browser monitoring: the script tag and the first-party proxy behind it.
 *
 * With the proxy on (the default) the script and its beacons stay on the app's own domain, so
 * content blockers, which drop requests to third-party monitoring hosts, let them through.
 * GET {browser_path}/s.js serves Watch Tower's script (cached in a file for an hour);
 * POST {browser_path}/e forwards a beacon to Watch Tower with the environment token and the
 * visitor's origin, user agent and client IP, which Watch Tower only trusts together with
 * that token. The token never reaches the page.
 *
 * Visitors never get an error from either path: when Watch Tower is unreachable the script is
 * an empty 200 and a beacon a 204. The SDK's calls go through its own small HTTP client, never
 * through the traced curl or Guzzle wrappers, so they are not recorded as outgoing requests.
 */
final class Browser
{
    /** Largest beacon body forwarded; bigger ones are dropped (Watch Tower refuses them too). */
    public const MAX_BEACON_BYTES = 65536;

    /** Seconds a fetched script is served from the cache file. */
    public const SCRIPT_TTL_SECONDS = 3600;

    /** Seconds before the script is fetched again after Watch Tower failed to serve it. */
    public const SCRIPT_RETRY_SECONDS = 60;

    /** Connect and total timeout of the proxy's calls to Watch Tower. */
    public const TIMEOUT_SECONDS = 3.0;

    private ShieldHttp $http;

    /** @var \Closure(): int */
    private \Closure $clock;

    /**
     * @param  (\Closure(): int)|null  $clock  current UNIX time (tests travel in time with it)
     */
    public function __construct(
        private readonly Config $config,
        ?ShieldHttp $http = null,
        ?\Closure $clock = null,
    ) {
        $this->http = $http ?? (function_exists('curl_init') ? new CurlShieldHttp : new StreamShieldHttp);
        $this->clock = $clock ?? static fn (): int => time();
    }

    /** A browser key and a Watch Tower URL are configured and the SDK is enabled. */
    public function enabled(): bool
    {
        return $this->config->enabled && $this->config->browserKey !== null && $this->config->baseUrl !== '';
    }

    /** The tag points at the first-party proxy (on by default; it needs the environment token). */
    public function proxied(): bool
    {
        return $this->enabled() && $this->config->browserProxy && $this->config->token !== null;
    }

    /**
     * The `<script>` tag for the page's `<head>`, or an empty string when browser monitoring is
     * not configured. It never contains the environment token.
     */
    public function tag(): string
    {
        if (! $this->enabled()) {
            return '';
        }

        $attributes = ['data-key' => (string) $this->config->browserKey];

        if ($this->proxied()) {
            $src = $this->config->browserPath.'/s.js';
            $attributes['data-endpoint'] = $this->config->browserPath.'/e';
        } else {
            $src = $this->config->baseUrl.'/watchtower-browser.js';
        }

        if ($this->config->browserRelease !== '') {
            $attributes['data-release'] = $this->config->browserRelease;
        }

        if ($this->config->browserSample !== null) {
            $attributes['data-sample'] = (string) $this->config->browserSample;
        }

        $html = '<script src="'.self::escape($src).'"';

        foreach ($attributes as $name => $value) {
            $html .= ' '.$name.'="'.self::escape($value).'"';
        }

        return $html.' defer></script>';
    }

    /**
     * Answer the request when it is one of the proxy's: GET (or HEAD) {path}/s.js or
     * POST {path}/e. Null for every other request, and whenever the proxy is not configured.
     *
     * @param  array<string, mixed>  $server  $_SERVER
     * @param  (\Closure(): string)|null  $body  reads the request body (default: php://input)
     */
    public function handle(array $server, ?\Closure $body = null): ?ShieldResponse
    {
        if (! $this->proxied()) {
            return null;
        }

        $method = strtoupper(is_string($server['REQUEST_METHOD'] ?? null) ? $server['REQUEST_METHOD'] : 'GET');
        $path = is_string($server['REQUEST_URI'] ?? null) ? (string) parse_url($server['REQUEST_URI'], PHP_URL_PATH) : '';

        if ($path === $this->config->browserPath.'/s.js' && ($method === 'GET' || $method === 'HEAD')) {
            return Guard::safely(fn (): ShieldResponse => $this->script(), self::emptyScript());
        }

        if ($path === $this->config->browserPath.'/e' && $method === 'POST') {
            Guard::safely(fn () => $this->forward($server, $body ?? self::input(...)));

            return new ShieldResponse(204, ['Cache-Control: no-store'], '');
        }

        return null;
    }

    /** The cache file of Watch Tower's script. */
    public function scriptPath(): string
    {
        return $this->config->spoolDir.DIRECTORY_SEPARATOR.'browser-script-'.substr(hash('xxh128', $this->config->baseUrl), 0, 12).'.js';
    }

    private function script(): ShieldResponse
    {
        $path = $this->scriptPath();
        $now = ($this->clock)();
        clearstatcache(true, $path);
        $modified = is_file($path) ? @filemtime($path) : false;
        $cached = $modified !== false ? @file_get_contents($path) : false;

        if (is_string($cached) && $modified + self::SCRIPT_TTL_SECONDS > $now) {
            return $cached === '' ? self::emptyScript() : self::scriptResponse($cached);
        }

        $response = $this->http->request('GET', $this->config->baseUrl.'/watchtower-browser.js', [
            'Accept' => 'application/javascript',
            'User-Agent' => 'watchtower-php',
        ], null, self::TIMEOUT_SECONDS, self::TIMEOUT_SECONDS);

        if ($response['status'] >= 200 && $response['status'] < 300 && $response['body'] !== '') {
            $this->writeScript($response['body'], $now);

            return self::scriptResponse($response['body']);
        }

        // Watch Tower failed: keep serving the last good copy (or nothing) and try again in a minute.
        $this->writeScript(is_string($cached) ? $cached : '', $now - self::SCRIPT_TTL_SECONDS + self::SCRIPT_RETRY_SECONDS);

        return is_string($cached) && $cached !== '' ? self::scriptResponse($cached) : self::emptyScript();
    }

    /**
     * @param  array<string, mixed>  $server
     * @param  \Closure(): string  $body
     */
    private function forward(array $server, \Closure $body): void
    {
        $length = $server['CONTENT_LENGTH'] ?? null;

        if (is_numeric($length) && (int) $length > self::MAX_BEACON_BYTES) {
            return;
        }

        $payload = $body();

        if ($payload === '' || strlen($payload) > self::MAX_BEACON_BYTES) {
            return;
        }

        $this->http->request('POST', $this->config->browserBeaconUrl(), [
            'Authorization' => 'Bearer '.$this->config->token,
            'Content-Type' => 'text/plain',
            'User-Agent' => 'watchtower-php',
            'X-Watchtower-Origin' => self::header(self::origin($server)),
            'X-Watchtower-User-Agent' => self::header(is_string($server['HTTP_USER_AGENT'] ?? null) ? $server['HTTP_USER_AGENT'] : ''),
            'X-Watchtower-Client-Ip' => self::header($this->config->clientIp->resolve($server)),
        ], $payload, self::TIMEOUT_SECONDS, self::TIMEOUT_SECONDS);
    }

    /** Atomic write (temp file + rename), with the mtime that dates the copy. */
    private function writeScript(string $script, int $fetchedAt): void
    {
        $directory = $this->config->spoolDir;

        if (! is_dir($directory) && ! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
            return;
        }

        $path = $this->scriptPath();
        $temp = $path.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (@file_put_contents($temp, $script) === false) {
            return;
        }

        @touch($temp, $fetchedAt);

        if (! @rename($temp, $path)) {
            @unlink($temp);
        }
    }

    /**
     * The site's scheme://host[:port], as the visitor's browser sees it.
     *
     * @param  array<string, mixed>  $server
     */
    private static function origin(array $server): string
    {
        $https = strtolower(is_string($server['HTTPS'] ?? null) ? $server['HTTPS'] : '');
        $scheme = ($https !== '' && $https !== 'off') || ($server['REQUEST_SCHEME'] ?? '') === 'https' ? 'https' : 'http';
        $host = $server['HTTP_HOST'] ?? $server['SERVER_NAME'] ?? 'localhost';

        return $scheme.'://'.(is_string($host) ? $host : 'localhost');
    }

    private static function scriptResponse(string $script): ShieldResponse
    {
        return new ShieldResponse(200, [
            'Content-Type: application/javascript; charset=utf-8',
            'Cache-Control: public, max-age='.self::SCRIPT_TTL_SECONDS,
        ], $script);
    }

    private static function emptyScript(): ShieldResponse
    {
        return new ShieldResponse(200, ['Content-Type: application/javascript', 'Cache-Control: no-store'], '');
    }

    private static function input(): string
    {
        return (string) @file_get_contents('php://input', false, null, 0, self::MAX_BEACON_BYTES + 1);
    }

    /** A header value without line breaks or other control characters. */
    private static function header(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x1F\x7F]+/', ' ', $value));
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
