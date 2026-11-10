<?php

declare(strict_types=1);

namespace WatchTower\Shield;

use WatchTower\Client;
use WatchTower\Config;
use WatchTower\Support\Guard;

/**
 * Shield (bot & spam protection) for plain PHP. WatchTower::init() calls handle() before the
 * application runs; a non-null response is sent and the script exits. Rules are evaluated in
 * the contract's order: allow list (wins over everything), blocked IPs (v4/v6 CIDR), user agent
 * expressions, path globs, rate limits, honeypot, then a pending challenge.
 *
 * Fails open: without cached rules, or on any error inside Shield, the request goes through.
 */
final class Shield
{
    /** Reserved POST field: the interstitial posts the Turnstile token back to the same URL. */
    public const VERIFY_PARAM = '_wt_shield_verify';

    public const TOKEN_PARAM = 'cf-turnstile-response';

    public const SITEVERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';

    /** Verification attempts per IP per minute (each one is a call to Cloudflare). */
    public const VERIFY_ATTEMPTS_PER_MINUTE = 20;

    public readonly RulesStore $rules;

    public readonly PassCookie $pass;

    private Counter $counter;

    private ShieldHttp $http;

    /** @var \Closure(): int */
    private \Closure $clock;

    /**
     * @param  (\Closure(): int)|null  $clock  current UNIX time (tests travel in time with it)
     */
    public function __construct(
        private readonly Config $config,
        private readonly Client $client,
        ?\Closure $clock = null,
        ?Counter $counter = null,
    ) {
        $this->clock = $clock ?? static fn (): int => time();
        $this->http = $config->shield['http_client'] ?? (function_exists('curl_init') ? new CurlShieldHttp : new StreamShieldHttp);
        $this->rules = new RulesStore($config, $this->http, fn (): int => ($this->clock)());
        $this->pass = new PassCookie($config);
        $this->counter = $counter ?? (
            $config->shield['counters'] !== 'file' && ApcuCounter::available()
                ? new ApcuCounter
                : new FileCounter($config->shield['cache_dir'].DIRECTORY_SEPARATOR.'shield-counters')
        );
    }

    public function enabled(): bool
    {
        return $this->config->enabled && $this->config->shield['enabled'];
    }

    /**
     * Decide on the current request. Null lets it through.
     *
     * @param  array<string, mixed>  $server  $_SERVER
     * @param  array<array-key, mixed>  $post  $_POST
     * @param  array<array-key, mixed>  $cookies  $_COOKIE
     */
    public function handle(array $server, array $post = [], array $cookies = []): ?ShieldResponse
    {
        if (! $this->enabled()) {
            return null;
        }

        return Guard::safely(function () use ($server, $post, $cookies): ?ShieldResponse {
            $rules = $this->rules->current();

            if ($rules === null) {
                return null;
            }

            $request = $this->facts($server, $post, $cookies);

            if ($request['method'] === 'POST' && isset($post[self::VERIFY_PARAM])) {
                return $this->verify($request, $rules, $post);
            }

            return $this->apply($request, $rules, $this->evaluate($request, $rules));
        });
    }

    /**
     * Called from the shutdown handler, after the response was sent: refresh stale rules.
     */
    public function afterResponse(): void
    {
        if ($this->enabled()) {
            Guard::safely(fn () => $this->rules->refreshIfStale());
        }
    }

    /**
     * "monitor" or "enforce": the configured override, else what Watch Tower sent.
     */
    public function mode(Rules $rules): string
    {
        return $this->config->shield['mode_override'] ?? $rules->mode;
    }

    /** The honeypot field name: the rules' when Watch Tower sent one, else the configured one. */
    public function honeypotName(): string
    {
        $fromRules = Guard::safely(fn (): ?string => $this->rules->current()?->honeypot['field'] ?? null);

        return is_string($fromRules) && $fromRules !== '' ? $fromRules : $this->config->shield['honeypot_field'];
    }

    /**
     * The hidden honeypot input, or an empty string when Shield is off.
     */
    public function honeypotField(): string
    {
        if (! $this->enabled()) {
            return '';
        }

        $name = htmlspecialchars($this->honeypotName(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return '<div aria-hidden="true" style="position:absolute!important;left:-10000px!important;top:auto!important;width:1px!important;height:1px!important;overflow:hidden!important;">'
            .'<label for="'.$name.'">Leave this field empty</label>'
            .'<input type="text" id="'.$name.'" name="'.$name.'" value="" tabindex="-1" autocomplete="off">'
            .'</div>';
    }

    /**
     * @param  array{method: string, path: string, ip: string, user_agent: string, accept: string, ajax: bool, https: bool, uri: string, post: array<array-key, mixed>, cookies: array<array-key, mixed>}  $request
     */
    public function evaluate(array $request, Rules $rules): Verdict
    {
        $ip = $request['ip'];

        foreach ($rules->allowIps as $cidr) {
            if (Patterns::ipInRange($ip, $cidr)) {
                return Verdict::allow();
            }
        }

        $now = ($this->clock)();

        foreach ($rules->blockIps as $block) {
            if (($block['expires_at'] === null || $block['expires_at'] > $now) && Patterns::ipInRange($ip, $block['cidr'])) {
                return Verdict::block('ip:'.$block['cidr']);
            }
        }

        if ($request['user_agent'] !== '') {
            foreach ($rules->userAgents as $pattern) {
                if (Patterns::regexMatches($pattern, $request['user_agent'])) {
                    return Verdict::block('ua');
                }
            }
        }

        foreach ($rules->paths as $glob) {
            if (Patterns::globMatches($glob, $request['path'])) {
                return Verdict::block('path:'.$glob);
            }
        }

        $challenge = null;

        foreach ($rules->rateLimits as $limit) {
            if (! $this->appliesTo($limit, $request['method'], $request['path'])) {
                continue;
            }

            $user = $this->client->execution()?->userId ?? '';
            $subject = match ($limit['by']) {
                // The SDK runs before the application knows its user: counted by IP until then.
                'user' => $user !== '' ? 'user:'.$user : 'ip:'.$ip,
                'ip_path' => 'ip:'.$ip.'|'.strtolower($request['path']),
                default => 'ip:'.$ip,
            };

            $retryAfter = $this->counter->attempt('rate:'.$limit['name'].':'.$subject, $limit['limit'], $limit['window_seconds'], $limit['block_seconds'], $now);

            if ($retryAfter === null) {
                continue;
            }

            if ($limit['action'] === 'block') {
                return Verdict::rateLimit('rate:'.$limit['name'], $retryAfter);
            }

            // A solved challenge lets the client through challenge limits for its lifetime.
            if ($challenge === null && ! $this->pass->valid($request['cookies'][PassCookie::NAME] ?? null, $ip, $now)) {
                $challenge = Verdict::challenge('rate:'.$limit['name'], $retryAfter);
            }
        }

        if ($rules->honeypot !== null && $request['method'] === 'POST' && self::filled($request['post'][$rules->honeypot['field']] ?? null)) {
            return $rules->honeypot['action'] === 'flag' ? Verdict::flag('honeypot') : Verdict::block('honeypot', 'honeypot');
        }

        return $challenge ?? Verdict::allow();
    }

    /**
     * The request as Shield sees it. The IP is the one the request record uses (Support\ClientIp:
     * REMOTE_ADDR, or the client_ip header when the peer is a trusted proxy).
     *
     * @param  array<string, mixed>  $server
     * @param  array<array-key, mixed>  $post
     * @param  array<array-key, mixed>  $cookies
     * @return array{method: string, path: string, ip: string, user_agent: string, accept: string, ajax: bool, https: bool, uri: string, post: array<array-key, mixed>, cookies: array<array-key, mixed>}
     */
    public function facts(array $server, array $post = [], array $cookies = []): array
    {
        $uri = (string) ($server['REQUEST_URI'] ?? '/');
        $https = strtolower((string) ($server['HTTPS'] ?? ''));
        $ip = $this->client->execution()?->meta['ip'] ?? null;

        return [
            'method' => strtoupper((string) ($server['REQUEST_METHOD'] ?? 'GET')),
            'path' => Patterns::normalizePath((string) (parse_url('http://x'.(str_starts_with($uri, '/') ? $uri : '/'.$uri), PHP_URL_PATH) ?? '/')),
            'ip' => is_string($ip) && $ip !== '' ? $ip : $this->config->clientIp->resolve($server),
            'user_agent' => is_string($server['HTTP_USER_AGENT'] ?? null) ? $server['HTTP_USER_AGENT'] : '',
            'accept' => strtolower(is_string($server['HTTP_ACCEPT'] ?? null) ? $server['HTTP_ACCEPT'] : ''),
            'ajax' => strtolower((string) ($server['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest',
            'https' => ($https !== '' && $https !== 'off') || (string) ($server['REQUEST_SCHEME'] ?? '') === 'https',
            'uri' => self::safeReturn($uri),
            'post' => $post,
            'cookies' => $cookies,
        ];
    }

    /**
     * @param  array{method: string, path: string, ip: string, user_agent: string, accept: string, ajax: bool, https: bool, uri: string, post: array<array-key, mixed>, cookies: array<array-key, mixed>}  $request
     */
    private function apply(array $request, Rules $rules, Verdict $verdict): ?ShieldResponse
    {
        if ($verdict->allowed()) {
            return null;
        }

        $rule = (string) $verdict->rule;

        if ($verdict->kind === Verdict::FLAG) {
            $this->record($request, 'honeypot', $rule, $rules);

            return null;
        }

        if ($this->mode($rules) === 'monitor') {
            $this->record($request, 'would_block', $rule, $rules);

            return null;
        }

        if ($verdict->kind === Verdict::CHALLENGE && $this->canChallenge($request, $rules)) {
            $this->record($request, 'challenged', $rule, $rules);

            return $this->challengePage($request, $rules, 429, false);
        }

        if ($verdict->kind === Verdict::CHALLENGE || $verdict->kind === Verdict::RATE_LIMIT) {
            $this->record($request, 'rate_limited', $rule, $rules);

            return $this->plain($request, 429, 'Too Many Requests.', ['Retry-After: '.max(1, $verdict->retryAfter)]);
        }

        $this->record($request, $verdict->recordAction, $rule, $rules);

        return $this->plain($request, 403, 'Forbidden.');
    }

    /**
     * The interstitial posted the Turnstile token back to this URL.
     *
     * @param  array{method: string, path: string, ip: string, user_agent: string, accept: string, ajax: bool, https: bool, uri: string, post: array<array-key, mixed>, cookies: array<array-key, mixed>}  $request
     * @param  array<array-key, mixed>  $post
     */
    private function verify(array $request, Rules $rules, array $post): ShieldResponse
    {
        $secret = (string) $this->config->shield['turnstile_secret'];
        $redirect = ['Location: '.$request['uri'], 'Cache-Control: no-store, private'];

        // Nothing to verify against: fail open.
        if ($rules->challenge === null || $secret === '') {
            return new ShieldResponse(303, $redirect, '');
        }

        $now = ($this->clock)();

        if ($this->counter->attempt('verify:'.$request['ip'], self::VERIFY_ATTEMPTS_PER_MINUTE, 60, 0, $now) !== null) {
            return $this->plain($request, 429, 'Too Many Requests.', ['Retry-After: 60']);
        }

        $token = $post[self::TOKEN_PARAM] ?? null;
        $passed = false;

        if (is_string($token) && $token !== '' && strlen($token) <= 2048) {
            $response = $this->http->request('POST', self::SITEVERIFY_URL, [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept' => 'application/json',
            ], http_build_query(['secret' => $secret, 'response' => $token, 'remoteip' => $request['ip']]), 2.0, 5.0);

            $data = $response['status'] >= 200 && $response['status'] < 300 ? json_decode($response['body'], true) : null;
            $passed = is_array($data) && ($data['success'] ?? null) === true;
        }

        if ($passed) {
            $this->record($request, 'challenge_passed', 'challenge', $rules);

            return new ShieldResponse(303, [...$redirect, 'Set-Cookie: '.$this->pass->header($request['ip'], $now, $request['https'])], '');
        }

        $this->record($request, 'challenge_failed', 'challenge', $rules);

        return $this->challengePage($request, $rules, 403, true);
    }

    /**
     * @param  array{method: string, accept: string, ajax: bool}  $request
     */
    private function canChallenge(array $request, Rules $rules): bool
    {
        return $rules->challenge !== null
            && $this->config->shield['turnstile_secret'] !== null
            && in_array($request['method'], ['GET', 'HEAD'], true)
            && str_contains($request['accept'], 'text/html')
            && ! $request['ajax'];
    }

    /**
     * @param  array{uri: string}  $request
     */
    private function challengePage(array $request, Rules $rules, int $status, bool $failed): ShieldResponse
    {
        $e = static fn (string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $message = $failed ? '<p class="error">The check didn\'t succeed. Please try again.</p>' : '<p>Please confirm you\'re human to continue.</p>';

        $html = '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<meta name="robots" content="noindex, nofollow"><title>Checking your browser</title>'
            .'<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>'
            .'<style>body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f6f7f9;color:#1f2933}'
            .'main{max-width:420px;padding:32px;text-align:center}h1{font-size:1.25rem;margin:0 0 8px}p{margin:0 0 20px;color:#52606d;line-height:1.5}.error{color:#b42318}'
            .'button{margin-top:16px;padding:8px 16px;border:0;border-radius:6px;background:#1f2933;color:#fff;font:inherit;cursor:pointer}'
            .'@media (prefers-color-scheme:dark){body{background:#111418;color:#e4e7eb}p{color:#9aa5b1}button{background:#e4e7eb;color:#111418}}</style></head>'
            .'<body><main><h1>One moment</h1>'.$message
            .'<form id="wt-shield-form" method="POST" action="'.$e($request['uri']).'">'
            .'<input type="hidden" name="'.self::VERIFY_PARAM.'" value="1">'
            .'<div class="cf-turnstile" data-sitekey="'.$e((string) ($rules->challenge['site_key'] ?? '')).'" data-callback="wtShieldSolved"></div>'
            .'<noscript><p>JavaScript is required to continue.</p></noscript><button type="submit">Continue</button></form></main>'
            .'<script>function wtShieldSolved(){document.getElementById("wt-shield-form").submit();}</script></body></html>';

        return new ShieldResponse($status, [
            'Content-Type: text/html; charset=UTF-8',
            'Cache-Control: no-store, private',
            'X-Robots-Tag: noindex',
        ], $html);
    }

    /**
     * Generic bodies: never the rule that matched.
     *
     * @param  array{accept: string}  $request
     * @param  list<string>  $headers
     */
    private function plain(array $request, int $status, string $message, array $headers = []): ShieldResponse
    {
        if (preg_match('~[/+]json~', $request['accept']) === 1) {
            return new ShieldResponse($status, ['Content-Type: application/json', 'Cache-Control: no-store, private', ...$headers], (string) json_encode(['message' => $message]));
        }

        return new ShieldResponse($status, ['Content-Type: text/plain; charset=UTF-8', 'Cache-Control: no-store, private', ...$headers], $message);
    }

    /**
     * @param  array{method: string, path: string, ip: string, user_agent: string}  $request
     */
    private function record(array $request, string $action, string $rule, Rules $rules): void
    {
        $execution = $this->client->execution();

        if ($execution === null) {
            return;
        }

        $this->client->push($this->client->records->shield($execution, [
            'action' => $action,
            'rule' => $rule,
            'ip' => $request['ip'],
            'method' => $request['method'],
            'path' => $request['path'],
            'user_agent' => $request['user_agent'] !== '' ? $request['user_agent'] : null,
            'user_id' => $execution->userId !== '' ? $execution->userId : null,
            'rules_version' => $rules->version,
        ], microtime(true)));
    }

    /**
     * @param  array{methods: list<string>, paths: list<string>}  $limit
     */
    private function appliesTo(array $limit, string $method, string $path): bool
    {
        if ($limit['methods'] !== [] && ! in_array($method, $limit['methods'], true)) {
            return false;
        }

        if ($limit['paths'] === []) {
            return true;
        }

        foreach ($limit['paths'] as $glob) {
            if (Patterns::globMatches($glob, $path)) {
                return true;
            }
        }

        return false;
    }

    /** A same-site path: anything absolute or protocol-relative becomes "/". */
    public static function safeReturn(string $uri): string
    {
        if (! str_starts_with($uri, '/') || str_starts_with($uri, '//') || str_contains($uri, '\\') || preg_match('/[\x00-\x1F\x7F]/', $uri) === 1) {
            return '/';
        }

        return $uri;
    }

    private static function filled(mixed $value): bool
    {
        if (is_array($value)) {
            return $value !== [];
        }

        return is_scalar($value) && trim((string) $value) !== '';
    }
}
