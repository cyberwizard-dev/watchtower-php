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
```

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

Query timing limits:

- **Without `SAVEQUERIES`** (recommended in production), a query starts at WordPress's
  `query` filter and ends at the next hook that fires after it. That is an upper bound:
  it includes any PHP work between the query and that hook, usually microseconds. File and
  line point at the code that called `$wpdb`.
- **With `SAVEQUERIES`**, the exact durations WordPress measures are used. WordPress only
  records the calling functions, so `file` holds the innermost caller (e.g.
  `WP_Query->get_posts`) and `line` is 0.
- Queries that bypass `$wpdb` (a plugin's own PDO/mysqli connection) are not seen.

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
    'trusted_proxy_header' => null,   // e.g. 'X-Forwarded-For' (first address wins); default REMOTE_ADDR

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
