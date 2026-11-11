# Watch Tower PHP SDK

`cyberwizard/watchtower-php` lets any PHP application report to a self-hosted
[Watch Tower](https://github.com/cyberwizard-dev/watch-tower) server, with no
framework required. It covers plain PHP, WordPress, CodeIgniter, Slim, legacy apps and
cron scripts.

It sends records in the **Laravel Nightwatch v1 wire format** that Watch Tower already
ingests, so requests, exceptions, logs, queries, outgoing HTTP calls, commands and users
show up on the same pages as your Laravel apps.

- Zero required dependencies, PHP 8.1+
- Sends **after** the response (`fastcgi_finish_request()`), with a 1 s connect and 2 s total timeout
- Falls back to an on-disk spool that a cron job drains
- Redacts secrets before anything leaves the server
- Never throws into your application

## Contents

- [Install](#install)
- [Quick start](#quick-start): plain PHP, CodeIgniter-style front controllers, CLI scripts, WordPress
- [Shield (bot and spam protection)](#shield-bot-and-spam-protection)
- [Browser monitoring](#browser-monitoring)
- [Client IP behind proxies](#client-ip-behind-proxies)
- [What is captured](#what-is-captured)
- [Options](#options)
- [API](#api)
- [Privacy and redaction](#privacy-and-redaction)
- [Performance](#performance)
- [Transport contract](#transport-contract)
- [Development](#development)

## Install

### With Composer

```bash
composer require cyberwizard/watchtower-php
```

### Single file (shared hosting, no Composer)

Download `dist/watchtower.php` from a release (or build it with `composer build`) and
upload it next to your code:

```php
require __DIR__.'/watchtower.php';

WatchTower::init(['token' => 'your-environment-token', 'base_url' => 'https://watchtower.example.com']);
```

If the app also has a Composer autoloader, require it **before** `watchtower.php` so the
PSR-3 logger and the Monolog handler can see `psr/log` and Monolog. Do not load both the
Composer package and the single file in one app.

## Quick start

Create an environment in Watch Tower and copy its token. Then call `init()` as early as
possible, ideally on the first line of your front controller:

### Plain PHP

```php
<?php // public/index.php

require __DIR__.'/../vendor/autoload.php';

WatchTower::init([
    'token' => getenv('WATCHTOWER_TOKEN'),
    'base_url' => 'https://watchtower.example.com',
    'service' => 'shop',
    'deploy' => trim((string) @file_get_contents(__DIR__.'/../REVISION')), // version or git sha
]);

// Optional: give the request a route pattern so similar URLs group together.
WatchTower::route('/products/{id}', 'products.show');

// Optional: attribute the request to a user.
WatchTower::user($user->id, $user->name, $user->email);

// ... your application ...
```

`token` and `base_url` can be left out when the `WATCHTOWER_TOKEN` and `WATCHTOWER_URL`
environment variables are set.

Without `WatchTower::route()`, the route is derived from the path with ids replaced:
`/users/42/orders/9b1d...` becomes `/users/{id}/orders/{uuid}`.

### CodeIgniter and other front-controller frameworks

Put `init()` in `index.php` (or a `pre_system` hook) and set the route where the router
resolves it, for example in a base controller:

```php
// CodeIgniter 4: app/Controllers/BaseController.php
public function initController(...$args)
{
    parent::initController(...$args);

    $router = service('router');
    WatchTower::route('/'.$router->getMatchedRoute()[0], $router->getMatchedRouteOptions()['as'] ?? null, $router->controllerName().'::'.$router->methodName());
}
```

Send framework logs to Watch Tower with the PSR-3 logger (`WatchTower::logger()`) or,
if the framework uses Monolog, with `new \WatchTower\Log\MonologHandler()`.

### Database queries (PDO)

Swap `PDO` for `TracedPdo`. It is a subclass, so type hints keep working:

```php
use WatchTower\Pdo\TracedPdo;

$pdo = new TracedPdo('mysql:host=db;dbname=shop', 'shop', $password, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION], 'mysql');
```

Every successful `prepare()/execute()`, `query()` and `exec()` becomes a `query` record. Each
record has the SQL with literals stripped, the duration, the connection name (last argument,
default: the driver name) and the file and line of your code that ran it.

### Outgoing HTTP

```php
// cURL: pass your headers so a W3C traceparent can be added to them.
$ch = curl_init('https://api.stripe.com/v1/charges');
curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body]);
$response = WatchTower::curlExec($ch, ['Content-Type: application/x-www-form-urlencoded', 'Authorization: Bearer '.$key]);

// Guzzle
$stack = GuzzleHttp\HandlerStack::create();
$stack->push(WatchTower::guzzleMiddleware(), 'watchtower');
$client = new GuzzleHttp\Client(['handler' => $stack]);
```

When the called service also reports to Watch Tower, the `traceparent` header links the two
executions.

### CLI scripts and cron jobs

When `init()` runs under the CLI, the script is recorded as a `command`. Its name is the
script file name and its duration and exception count are included:

```php
#!/usr/bin/env php
<?php
require __DIR__.'/vendor/autoload.php';
WatchTower::init();

$failed = run_backup();

WatchTower::exitCode($failed ? 1 : 0); // PHP cannot read the exit code at shutdown, so tell the SDK
exit($failed ? 1 : 0);
```

Uncaught exceptions and fatal errors are reported with exit code 255 automatically.

To report several tasks from one script, or to capture their output, wrap them. Each
wrapped task becomes its own command, and the script itself is then not reported
separately:

```php
WatchTower::command('backup:database', function () {
    echo "Dumping...\n";       // still printed; also sent as wt-output when capture is on
    return 0;                  // int = exit code, false = 1, anything else = 0
}, captureOutput: true);
```

Records are sent when each wrapped task ends. A long-running worker should call
`WatchTower::flush()` from time to time, because one execution keeps at most 500 records.

### WordPress

1. Copy `wordpress/watchtower.php` to `wp-content/mu-plugins/watchtower.php`.
2. Copy `dist/watchtower.php` to `wp-content/mu-plugins/watchtower-sdk/watchtower.php`
   (or install the Composer package and load its autoloader, or set `WATCHTOWER_SDK_PATH`).
3. Add to `wp-config.php`:

```php
define('WATCHTOWER_TOKEN', 'your-environment-token');
define('WATCHTOWER_URL', 'https://watchtower.example.com');
// optional
define('WATCHTOWER_DEPLOY', 'theme-2.3.0');
define('WATCHTOWER_OPTIONS', ['sample_rate' => 0.25, 'ignore_paths' => ['/health', '/wp-cron.php']]);
// behind a proxy or CDN, see "Client IP behind proxies"
define('WATCHTOWER_CLIENT_IP_PRESET', 'cloudflare');
define('WATCHTOWER_TRUSTED_PROXIES', 'private'); // '*', 'private' or ['10.0.0.0/8', ...]
define('WATCHTOWER_CLIENT_IP_HEADER', 'X-Forwarded-For'); // when no preset fits
```

`WATCHTOWER_OPTIONS['client_ip']` wins over these constants, key by key.

The plugin:

- names each request after the main query's template type (`wp.single.post` on
  `/post/{slug}`, `wp.page`, `wp.category`, `wp.404`, ...) and the theme template used
  (`theme/single.php`). REST requests use the registered route pattern
  (`/wp-json/wp/v2/posts/{id}`), wp-admin uses `admin.{screen}`, and AJAX uses
  `ajax.{action}`.
- attributes the request to the logged-in user. It sends the user ID, display name and
  login name, never the email address.
- records `$wpdb` queries with literals stripped.
- reports PHP errors, uncaught exceptions and fatal errors (WordPress's own fatal error
  handler keeps working).
- sends `framework: "wordpress"` in `wt-hello`.
- with Shield enabled (`define('WATCHTOWER_OPTIONS', ['shield' => ['enabled' => true]]);`),
  adds the honeypot field to the comment, login, registration and lost password forms
  (`comment_form`, `login_form`, `register_form`, `lostpassword_form`). Shield runs when the
  mu-plugin loads, before WordPress handles the request.
- with a browser key (`define('WATCHTOWER_BROWSER_KEY', '...');`), prints the browser
  monitoring script tag in `wp_head` and serves its first-party proxy when the mu-plugin
  loads, see [Browser monitoring](#browser-monitoring).

Query timing limits:

- **Without `SAVEQUERIES`** (recommended in production), a query starts at WordPress's
  `query` filter and ends at the next hook that fires after it. That is an upper bound:
  it includes any PHP work between the query and that hook, usually microseconds. File and
  line point at the code that called `$wpdb`.
- **With `SAVEQUERIES`**, the exact durations WordPress measures are used. WordPress only
  records the calling functions, so `file` holds the innermost caller (e.g.
  `WP_Query->get_posts`) and `line` is 0.
- Queries that bypass `$wpdb` (a plugin's own PDO/mysqli connection) are not seen.

## Shield (bot and spam protection)

Off by default. Shield applies rules you manage in Watch Tower inside `WatchTower::init()`,
before any application code runs:

```php
WatchTower::init([
    'token' => '...',
    'base_url' => 'https://watchtower.example.com',
    'shield' => [
        'enabled' => true,            // env WATCHTOWER_SHIELD
        'mode_override' => null,      // 'monitor' or 'enforce' instead of the mode Watch Tower sends
        'cache_dir' => null,          // default: spool_dir
        'turnstile_secret' => null,   // env TURNSTILE_SECRET (never sent by Watch Tower)
        'counters' => 'auto',         // 'auto' (APCu when enabled, else files), 'apcu' or 'file'
        'pass_minutes' => 30,         // lifetime of the wt_shield_pass cookie
        'honeypot_field' => 'wt_hp_website', // until Watch Tower sends one
        'throttle_seconds' => 10,     // one wt-shield record per (action, rule, IP) per window, env WATCHTOWER_SHIELD_THROTTLE_SECONDS; 0 = every one
    ],
]);
```

- **Rules** come from `GET {base_url}/api/shield/v1/rules` with the environment token (1 s
  connect, 2 s total, `If-None-Match` so unchanged rules are a 304) and are cached in a file
  (written atomically) for their `ttl_seconds`. A stale copy is refreshed in the shutdown
  handler, after the response is sent and at most once per 15 seconds across workers, so
  requests never wait for Watch Tower. The last good copy is kept when Watch Tower is
  unreachable; the very first request without any copy is allowed (fail open).
- **Order:** allow list (wins over everything), blocked IPs (IPv4/IPv6 CIDR; IPv4-mapped IPv6
  addresses match IPv4 ranges; expired entries ignored), user agent expressions (PCRE without
  delimiters; invalid expressions are skipped, matching is capped at 1 KB with a low
  backtracking limit), path globs (`*` crosses `/`, case-insensitive, decoded path with
  duplicate slashes collapsed), rate limits, honeypot, challenge. The client IP is the one the
  request record uses (see [Client IP behind proxies](#client-ip-behind-proxies)), so a
  forged `X-Forwarded-For` can't dodge an IP block, reset a rate limit or reuse a pass cookie.
- **Rate limits** count in a fixed window from the first hit, per `ip`, `ip_path` or `user`
  (the SDK runs before your app knows the user, so `user` counts by IP), in APCu or in
  `flock()`ed files under `cache_dir/shield-counters`. Over the limit: 429 with `Retry-After`,
  for `block_seconds` when set.
- **Responses:** 403 `Forbidden.` or 429 `Too Many Requests.` (JSON `{"message": ...}` when
  the `Accept` header asks for JSON), then `exit`. Bodies never name the rule. The request is
  still reported, with its `wt-shield` record in the same batch.
- **Challenge:** a rate limit with `action: challenge` shows a browser GET (`Accept` with
  `text/html`, not XHR) a Turnstile interstitial (429). It posts back to the same URL with the
  reserved `_wt_shield_verify` field; the SDK checks the token with Cloudflare's siteverify
  using `turnstile_secret`, sets the signed `wt_shield_pass` cookie (HMAC of expiry and client
  IP keyed with a hash of the token, HttpOnly, SameSite=Lax) and redirects (303) to the same
  URL. Other clients, or a missing secret, get the 429. 20 verifications per IP per minute.
- **Honeypot:** `echo WatchTower::honeypotField();` inside your forms renders an off-screen,
  `aria-hidden`, `tabindex="-1"`, `autocomplete="off"` input. A POST that fills it is blocked
  (`action: block`) or only reported (`flag`).
- **Monitor mode** blocks nothing and reports `would_block`.
- **Sampling:** a decision is a security signal, so it keeps its execution even when
  `sample_rate` dropped it: the `wt-shield` record and the request it was made for are
  delivered. Repeats of the same (action, rule, IP) are recorded at most once per
  `throttle_seconds` (10), counted in the same APCu or file counters as the rate limits so all
  workers share the window; a throttled decision is not recorded and its request follows
  `sample_rate`. Records carry no count, so during an attack Watch Tower sees one decision
  per window and its rule hit counters count windows, not requests.

## Browser monitoring

Page views, Core Web Vitals and JavaScript errors from your visitors' browsers, reported to
the same Watch Tower environment. Off until you set a browser key (Watch Tower: the
environment's settings, "Browser"). The key is public: it is printed in your pages.

### Plain PHP

```php
WatchTower::init([
    'token' => getenv('WATCHTOWER_TOKEN'),
    'base_url' => 'https://watchtower.example.com',
    'browser_key' => 'your-public-browser-key',  // env WATCHTOWER_BROWSER_KEY
]);

// Right after init(): answers GET /_t/s.js and POST /_t/e, then exits.
// Every other request returns false and continues untouched.
WatchTower::handleBrowserProxy();

// ... your application ...
```

and in the `<head>` of your layout:

```php
<?= WatchTower::browserTag() ?>
```

which renders

```html
<script src="/_t/s.js" data-key="your-public-browser-key" data-endpoint="/_t/e" data-release="v1.4.2" defer></script>
```

`browserTag()` is an empty string while no browser key is configured, and the tag never
contains the environment token. Pass `handleBrowserProxy(false)` to have it return `true`
after answering instead of calling `exit` (frameworks that must finish their own response
cycle).

### WordPress

```php
// wp-config.php, next to WATCHTOWER_TOKEN and WATCHTOWER_URL
define('WATCHTOWER_BROWSER_KEY', 'your-public-browser-key');
// optional
define('WATCHTOWER_OPTIONS', ['browser_sample' => 0.5]);
```

The mu-plugin prints the tag in `wp_head` (priority 1) and answers the proxy paths as soon as
it loads, before WordPress loads other plugins, the theme or the main query, so a beacon
costs very little. `WATCHTOWER_OPTIONS['browser_key']` wins over the constant.

### Options

```php
'browser_key' => null,       // public browser key; env WATCHTOWER_BROWSER_KEY. Nothing happens without it
'browser_proxy' => true,     // serve the script and beacons from your domain; env WATCHTOWER_BROWSER_PROXY
'browser_path' => '/_t',     // proxy prefix: {path}/s.js and {path}/e; env WATCHTOWER_BROWSER_PATH
'browser_release' => null,   // data-release, default: the `deploy` option; env WATCHTOWER_BROWSER_RELEASE
'browser_sample' => null,    // data-sample, share of page views kept (0..1); env WATCHTOWER_BROWSER_SAMPLE
```

With `browser_proxy` off (or without a token) the tag loads
`{base_url}/watchtower-browser.js` and the script sends beacons straight to Watch Tower.

### Why a proxy

Content blockers (uBlock Origin, Brave Shields, Safari and Firefox tracking protection,
Pi-hole lists) drop requests to third-party monitoring hosts, so a script served from
`watchtower.example.com` misses a sizeable share of visitors. The proxy keeps both the script
and its beacons on your own domain:

- **GET `{browser_path}/s.js`** serves `{base_url}/watchtower-browser.js`, cached in
  `spool_dir` for an hour (`application/javascript`, `Cache-Control: public, max-age=3600`).
  When Watch Tower can't be reached the last good copy is served; with no copy, an empty
  200 script (`no-store`). A failed fetch is retried after a minute, not on every page view.
- **POST `{browser_path}/e`** forwards the raw beacon body (up to 64 KB; larger or empty
  bodies are dropped) to `POST {base_url}/api/browser/v1/{browser_key}` with the environment
  token as `Authorization: Bearer`, `Content-Type: text/plain`, and the visitor's details in
  `X-Watchtower-Origin` (the site's scheme://host), `X-Watchtower-User-Agent` and
  `X-Watchtower-Client-Ip` (resolved with the [client IP rules](#client-ip-behind-proxies),
  so a forged `X-Forwarded-For` is ignored). Watch Tower only trusts those headers together
  with the token of the environment that owns the browser key. The visitor always gets a 204.
- Both calls use a 3 s connect and total timeout, go through the SDK's own HTTP client (never
  recorded as outgoing requests) and the proxy requests themselves are left out of your
  reported requests.

The proxy paths are matched from the domain root and must reach PHP. If your web server
serves `*.js` files itself without falling back to the front controller (an nginx
`location ~* \.js$` block without `try_files ... /index.php`), route `{browser_path}/` to PHP
or pick a `browser_path` it passes through.

## Client IP behind proxies

By default the client IP is `REMOTE_ADDR`, the address that opened the TCP connection. Behind
a load balancer, CDN or reverse proxy that is the proxy, and the visitor's address is in a
header the proxy sets. Headers come from the client too, so the SDK only reads one when the
connection itself comes from a proxy you trust:

```php
'client_ip' => [
    'preset' => null,          // 'cloudflare', 'digitalocean', 'aws_alb', 'heroku', 'fly', 'vercel', 'nginx', 'traefik'
    'header' => null,          // 'X-Forwarded-For', 'Forwarded', 'X-Real-IP', 'CF-Connecting-IP', 'do-connecting-ip', 'Fly-Client-IP', 'True-Client-IP', ...
    'trusted_proxies' => null, // list of CIDRs, '*' or 'private' (default 'private' when a header is set)
],
```

- **The header is ignored unless `REMOTE_ADDR` is inside `trusted_proxies`.** A request that
  reaches the app directly gets its `REMOTE_ADDR`, whatever it sends.
- `trusted_proxies` is a list of IPv4/IPv6 CIDRs or addresses, which may include the keywords
  `'private'` (10/8, 172.16/12, 192.168/16, 100.64/10, 127/8, 169.254/16, ::1, fc00::/7,
  fe80::/10) and `'cloudflare'` (Cloudflare's edge ranges). A comma-separated string works too.
- `'*'` trusts any peer. **Only use it when nothing can reach the app except through the
  proxy**, e.g. DigitalOcean App Platform, Heroku, Render or Fly. On a server with a public IP
  it lets anyone pick their IP.
- **`X-Forwarded-For` and `Forwarded`** (RFC 7239 `for=`) are lists every proxy appends to, so
  only the right end is trustworthy. The SDK walks them right to left, skips addresses in
  `trusted_proxies`, and uses the first one that is not (the left-most when every hop is
  trusted). With `'*'` only the peer is trusted, so the right-most entry wins. Ports, brackets
  and quotes (`[2001:db8::1]:443`, `"203.0.113.7:8080"`) are removed; `unknown`, obfuscated
  identifiers and garbage are skipped.
- **Single-value headers** (`CF-Connecting-IP`, `do-connecting-ip`, `X-Real-IP`,
  `Fly-Client-IP`, `True-Client-IP`, anything else) are used as they are when valid.
- An invalid or missing header falls back to `REMOTE_ADDR`.
- A preset sets `header` and `trusted_proxies`; either key set explicitly wins. Unknown presets
  are ignored (`REMOTE_ADDR`).
- The same address is used everywhere: the request record's `ip`, Shield (IP allow and block
  lists, rate-limit keys, the IP-bound pass cookie, Turnstile `remoteip`) and `wt-shield`
  records.

| Hosting | Config | Why |
| --- | --- | --- |
| Cloudflare (orange cloud) | `['preset' => 'cloudflare']` | Cloudflare overwrites `CF-Connecting-IP`; only its [published ranges](https://www.cloudflare.com/ips/) (embedded, as of 2026-11-10) are trusted, so direct-to-origin requests can't spoof it. Override with `'trusted_proxies' => ['cloudflare', ...]` if the list changes or another proxy sits between. |
| DigitalOcean App Platform | `['preset' => 'digitalocean']` | App Platform sets `do-connecting-ip` and only exposes apps through its edge, so the peer is always the platform and `'*'` is safe. Not for Droplets. |
| AWS ALB / ELB | `['preset' => 'aws_alb']` | Header `X-Forwarded-For`, trusted `'private'`: the load balancer appends the client and connects to targets from private VPC addresses. Lock the instance's security group to the load balancer. |
| nginx / Apache reverse proxy (same host or LAN) | `['preset' => 'nginx']` | Header `X-Forwarded-For`, trusted `'private'`. nginx: `proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;`. Apache `mod_proxy` adds it. A proxy on a public address: `'trusted_proxies' => ['198.51.100.10']`. Using `X-Real-IP $remote_addr` instead: `['header' => 'X-Real-IP', 'trusted_proxies' => 'private']`. |
| Traefik / Docker / Kubernetes ingress | `['preset' => 'traefik']` | Header `X-Forwarded-For`, trusted `'private'` (container networks are private ranges). Add your ingress CIDR if it is not. |
| Heroku | `['preset' => 'heroku']` | The router appends the client to `X-Forwarded-For` and dynos are only reachable through it: `'*'`, right-most entry. |
| Render | `['header' => 'X-Forwarded-For', 'trusted_proxies' => '*']` | Services are only reachable through Render's proxy. Check a request's `X-Forwarded-For` once to confirm the right-most entry is the client. |
| Fly.io | `['preset' => 'fly']` | fly-proxy sets `Fly-Client-IP`; public traffic only reaches machines through it. |
| Vercel (PHP runtime) | `['preset' => 'vercel']` | Vercel overwrites `X-Forwarded-For` with the client address. |
| Cloudflare in front of another proxy (e.g. Cloudflare + nginx) | `['header' => 'CF-Connecting-IP', 'trusted_proxies' => ['private']]` | nginx is the peer; make sure nginx only accepts Cloudflare's ranges. |
| No proxy | nothing (default) | `REMOTE_ADDR` is the client. Don't set a header: anyone could send it. |

The deprecated `trusted_proxy_header` option still works as `client_ip.header`, but since
0.2.0 it is only honoured when the peer is a private address (or one in
`client_ip.trusted_proxies`), and `X-Forwarded-For` is read right to left instead of taking the
first entry.

## What is captured

| Watch Tower record | Source |
| --- | --- |
| `request` | Every web request: method, URL (redacted), route, status, duration (bootstrap plus action), peak memory, request and response size, IP, user agent and sanitised headers, counters |
| `command` | The CLI script, or each `WatchTower::command()` task, with exit code |
| `exception` | Uncaught exceptions (unhandled), `captureException()` (handled by default), PHP warnings and notices (as handled `ErrorException`), fatal errors at shutdown (`WatchTower\FatalError`). Includes stack frames with code snippets of your own files |
| `log` | `WatchTower::logger()` (PSR-3), `MonologHandler` |
| `query` | `TracedPdo`, WordPress `$wpdb` |
| `outgoing-request` | `WatchTower::curlExec()`, Guzzle middleware |
| `user` | `WatchTower::user()` |
| `wt-trace-link` | Incoming W3C `traceparent` header |
| `wt-output` | Output of wrapped commands (opt-in) |
| `wt-shield` | Each Shield decision that is not "allow" (`blocked`, `rate_limited`, `challenged`, `challenge_passed`, `challenge_failed`, `honeypot`, `would_block`) |
| `wt-hello` | Once a day per server: `sdk: "php"`, versions, framework |

Every in-execution record carries the execution header (`trace_id`, `execution_source`,
`execution_id`, `execution_preview`, `execution_stage`, `user`), so Watch Tower shows it
inside its request or command.

## Options

```php
WatchTower::init([
    // Connection
    'token' => null,                  // env WATCHTOWER_TOKEN
    'base_url' => null,               // env WATCHTOWER_URL
    'enabled' => true,                // env WATCHTOWER_ENABLED

    // Identification
    'service' => null,                // app name (sent in wt-hello)
    'environment' => null,            // informational (sent in wt-hello), env WATCHTOWER_ENV
    'deploy' => '',                   // version or git sha, env WATCHTOWER_DEPLOY
    'server' => gethostname(),        // env WATCHTOWER_SERVER
    'framework' => null,              // e.g. 'codeigniter' (sent in wt-hello)

    // What to capture (all shown with their defaults)
    'capture' => [
        'requests' => true,
        'commands' => true,           // automatic CLI script record
        'exceptions' => true,         // installs an exception handler
        'errors' => true,             // installs an error handler (warnings, notices)
        'deprecations' => false,      // E_DEPRECATED / E_USER_DEPRECATED as exceptions
        'fatal_errors' => true,
        'logs' => true,
        'queries' => true,
        'outgoing_requests' => true,
        'headers' => true,            // sanitised request headers
        'response_size' => true,      // counts output bytes with a pass-through buffer
        'payload' => false,           // redacted $_POST of 500 responses (Nightwatch semantics)
        'code_snippets' => true,      // source lines around frames in your own files
        'command_output' => false,    // default for WatchTower::command(captureOutput:)
        'trace_context' => true,      // traceparent in and out
        'hello' => true,              // daily wt-hello
    ],

    // Sampling
    'sample_rate' => 1.0,             // share of requests/commands kept, env WATCHTOWER_SAMPLE_RATE
    'exception_sample_rate' => 1.0,   // an unhandled exception keeps an unsampled execution
    'slow_request_ms' => null,        // slower executions are always kept
    'slow_query_ms' => null,          // an execution with a slower query is always kept

    // Requests
    'ignore_paths' => ['/health'],    // exact paths or fnmatch() patterns ('/assets/*')
    'client_ip' => [                  // see "Client IP behind proxies"; default REMOTE_ADDR only
        'preset' => null,             // env WATCHTOWER_CLIENT_IP_PRESET
        'header' => null,             // e.g. 'X-Forwarded-For', 'CF-Connecting-IP'
        'trusted_proxies' => null,    // '*', 'private' or a list; env WATCHTOWER_TRUSTED_PROXIES
    ],
    'trusted_proxy_header' => null,   // deprecated alias for client_ip.header (trusts private peers)

    // Privacy
    'redact_keys' => Config::DEFAULT_REDACT_KEYS, // regex, same as Watch Tower's server-side pattern
    'redact_headers' => [],           // added to the built-in list
    'strip_numeric_literals' => true, // also replace bare numbers in SQL with ?

    // Errors and logs
    'error_reporting' => null,        // extra mask on top of error_reporting()
    'log_level' => 'debug',           // minimum PSR-3 level sent

    // Delivery
    'mode' => 'auto',                 // 'auto' = send after the response, spool on failure; 'spool' = always spool (cron sends)
    'connect_timeout' => 1.0,         // seconds
    'timeout' => 2.0,                 // seconds, total budget per execution (including spool draining)
    'finish_request' => true,         // call fastcgi_finish_request()/litespeed_finish_request() before sending
    'max_records' => 500,             // per execution and per HTTP request; extras are dropped and summarised in one log
    'spool_dir' => sys_get_temp_dir().'/watchtower', // env WATCHTOWER_SPOOL_DIR
    'spool_max_bytes' => 52428800,    // 50 MB, oldest batches dropped first
    'spool_drain_batches' => 5,       // spooled batches sent after each successful delivery

    // Misc
    'base_path' => null,              // project root for relative file names (default: Composer root, else DOCUMENT_ROOT)
    'location_skip' => [],            // path fragments treated as library code when finding the caller
    'output_max_bytes' => 65536,      // wt-output keeps the last N bytes
    'install_handlers' => true,       // set false to install no global handlers
    'http_client' => null,            // a WatchTower\Transport\HttpClient (tests, proxies)
    'shield' => ['enabled' => false], // see Shield

    // Browser monitoring (see "Browser monitoring")
    'browser_key' => null,            // env WATCHTOWER_BROWSER_KEY
    'browser_proxy' => true,          // env WATCHTOWER_BROWSER_PROXY
    'browser_path' => '/_t',          // env WATCHTOWER_BROWSER_PATH
    'browser_release' => null,        // default: deploy; env WATCHTOWER_BROWSER_RELEASE
    'browser_sample' => null,         // 0..1; env WATCHTOWER_BROWSER_SAMPLE
]);
```

`finish_request` hands the response to the client before records are sent. Turn it off if
your app streams the response or runs other shutdown work that must stay attached to the
connection.

## API

| Method | Purpose |
| --- | --- |
| `WatchTower::init(array $options)` | Configure and start observing (idempotent) |
| `WatchTower::route(string $path, ?string $name, ?string $action, ?array $methods)` | Route pattern of the request, e.g. `/users/{id}` |
| `WatchTower::user($id, ?string $name, ?string $email)` | User of the execution (`username` in Watch Tower) |
| `WatchTower::captureException(Throwable $e, bool $handled = true)` | Report a caught exception |
| `WatchTower::logger()` | PSR-3 logger |
| `new WatchTower\Log\MonologHandler($level = 'debug')` | Monolog 2/3 handler |
| `new WatchTower\Pdo\TracedPdo(...)` | Traced PDO |
| `WatchTower::curlExec(CurlHandle $ch, ?array $headers)` | Traced `curl_exec()` |
| `WatchTower::guzzleMiddleware()` | Traced Guzzle |
| `WatchTower::traceparent()` | `traceparent` value for any other HTTP client |
| `WatchTower::command(string $name, callable $task, ?bool $captureOutput)` | Report a CLI task |
| `WatchTower::exitCode(int $code)` | Exit code of the auto-recorded script |
| `WatchTower::ignore()` | Do not report this request or script |
| `WatchTower::flush()` | Send what is buffered now |
| `WatchTower::honeypotField()` | The hidden Shield honeypot input (empty when Shield is off) |
| `WatchTower::browserTag()` | The browser monitoring `<script>` tag (empty without `browser_key`) |
| `WatchTower::handleBrowserProxy(bool $exit = true)` | Answer `{browser_path}/s.js` and `{browser_path}/e` and exit; `false` for other requests |

Every method is a no-op before `init()` and never throws.

## Privacy and redaction

Redaction runs in your app before anything is sent. Watch Tower then applies its own
server-side pass.

- **Keys**: values whose key matches `redact_keys` are replaced by `[redacted]` in query
  strings, log context and extra, and request payloads. The default matches
  password/secret/token/api key/private key/credential/authorization/cookie/session/card
  number/CVV/SSN/IBAN, the same pattern Watch Tower uses.
- **Headers**: `authorization`, `proxy-authorization`, `cookie`, `set-cookie`, CSRF/XSRF
  tokens, `x-api-key`, `x-auth-token` and `x-amz-security-token` are masked, as is any
  header whose name matches `redact_keys`. `php-auth-*` headers are dropped.
- **Free text** (exception and log messages, command lines, output): `Bearer`/`Basic`
  credentials, JWTs and `key=value` pairs with sensitive keys are masked.
- **SQL**: string literals become `'?'` (exactly like Watch Tower) and, by default, bare
  numbers become `?`. Bound parameters are never read.
- **URLs**: user info (`user:pass@`) is removed.
- **Users**: only what you pass to `WatchTower::user()` is sent. The WordPress plugin
  sends the login name, not the email.
- **Request bodies** are not sent unless `capture.payload` is on. Even then only
  form fields (`$_POST`) of 500 responses are sent, redacted.

## Performance

- **After the response.** On PHP-FPM and LiteSpeed, the SDK calls
  `fastcgi_finish_request()` in the last shutdown function, then sends. Visitors do not wait.
  Under Apache mod_php or the CLI, sending happens at shutdown, bounded by `timeout`.
- **Bounded.** Connecting gets at most 1 s and the whole delivery (including draining
  spooled batches) at most 2 s per execution. Use `mode => 'spool'` to keep network I/O out
  of web workers completely.
- **Spool and cron.** Failed deliveries (and everything in `spool` mode) go to `spool_dir`
  as JSON-lines files, one batch per file, capped at 50 MB. Drain them from cron:

  ```cron
  * * * * * php /path/to/vendor/bin/watchtower-flush --config=/path/to/watchtower-config.php
  ```

  `--config` points to a PHP file that returns your `init()` options. `--token`, `--url`
  and `--spool-dir` (or the env variables) also work. `--status` shows the queue size.
  Without Composer, copy `bin/watchtower-flush` next to `watchtower.php`.
- **401 back-off.** If Watch Tower rejects the token, the SDK stops sending for 10
  minutes (a marker file in the spool directory) and spools meanwhile.
- **Overhead.** Records are plain arrays buffered in memory (at most 500 per execution).
  The response-size counter is a pass-through output buffer that never holds output back.
  Disable it with `capture.response_size => false` if your app manages output buffers
  itself.

## Transport contract

```
POST {base_url}/api/ingest/v1
Authorization: Bearer {environment token}
Content-Type: application/json
Content-Encoding: gzip            (omitted only when ext-zlib is missing)
User-Agent: watchtower-php/{version}

gzip({"records":[ ...Nightwatch v1 records... ]})
```

| Response | SDK behaviour |
| --- | --- |
| 2xx | Accepted; then up to `spool_drain_batches` spooled batches are sent |
| 401 | Stop sending for 10 minutes; the batch is spooled |
| 413 | Batch split in half and retried; a single oversized record is dropped |
| anything else / no response | Batch spooled |

Batches hold at most `max_records` records. Records are flat JSON objects. Timestamps are
float UNIX seconds, durations integer microseconds, memory bytes, and `headers`,
`context`, `extra`, `trace` and `payload` are JSON strings, exactly like
`laravel/nightwatch`.

## Development

```bash
composer install
composer test     # PHPUnit: unit + integration (uses `php -S` and subprocesses)
composer build    # regenerate dist/watchtower.php
```

The record-shape tests compare every record type against field and type maps of
Watch Tower's `tests/Support/NightwatchRecords.php` builders. When the Watch Tower
repository is checked out next to this one, or `WATCHTOWER_BUILDERS` points at that file,
a test also checks the maps against the builders themselves.

## License

MIT
