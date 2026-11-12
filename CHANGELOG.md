# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

### Added

- Session replay through the browser proxy: `handleBrowserProxy()` also answers
  GET `{browser_path}/r.js` (Watch Tower's recorder, cached like the script), GET
  `{browser_path}/c` (the replay settings, cached for a minute; `{"replay":null}` when Watch
  Tower is unreachable or does not know replay) and POST `{browser_path}/r` (one recorded
  chunk, up to 1 MB). A 4xx from Watch Tower on a chunk is passed on so the recorder stops.

## [0.3.0] - 2026-11-11

### Added

- Browser monitoring (page views, Core Web Vitals, JavaScript errors), off until
  `browser_key` (env `WATCHTOWER_BROWSER_KEY`) is set. Options `browser_proxy` (default true),
  `browser_path` (default `/_t`), `browser_release` (default: `deploy`) and `browser_sample`,
  each with a `WATCHTOWER_BROWSER_*` environment fallback.
- `WatchTower::browserTag()`: the `<script>` tag for the page's `<head>`. With the proxy it
  loads `{browser_path}/s.js` with `data-endpoint="{browser_path}/e"`; without it,
  `{base_url}/watchtower-browser.js`. Attributes are HTML-escaped and the environment token is
  never included.
- `WatchTower::handleBrowserProxy()`: a first-party proxy, so content blockers don't drop the
  script and its beacons. GET `{browser_path}/s.js` serves Watch Tower's script, cached in
  `spool_dir` for an hour (last good copy kept, failed fetches retried after a minute);
  POST `{browser_path}/e` forwards beacons up to 64 KB to `/api/browser/v1/{browser_key}` with
  the environment token and `X-Watchtower-Origin`, `X-Watchtower-User-Agent` and
  `X-Watchtower-Client-Ip` (from the `client_ip` rules). 3 s timeouts; visitors always get a
  200 script or a 204, whatever happens to Watch Tower. The proxy's requests are not
  reported and its calls are not recorded as outgoing requests. Returns false, doing nothing,
  for every other request.
- WordPress: `WATCHTOWER_BROWSER_KEY` (or `WATCHTOWER_OPTIONS['browser_key']`); the mu-plugin
  prints the tag in `wp_head` and answers the proxy paths as soon as it loads.
- `wt-hello` lists `browser` when a browser key is configured.

## [0.2.1] - 2026-11-10

### Fixed

- `wt-shield` decisions were dropped with the rest of an execution that `sample_rate` did not
  keep (with `sample_rate` 0.1, nine blocked requests in ten were never reported). A recorded
  decision now keeps its execution, so the `wt-shield` record and the blocked (or, in monitor
  mode, allowed) request are always delivered.

### Added

- `shield.throttle_seconds` (env `WATCHTOWER_SHIELD_THROTTLE_SECONDS`, default 10, 0 = off):
  at most one `wt-shield` record per (action, rule, IP) per window, shared by all workers
  through the APCu or file counters, so a client hammering the app costs one kept request per
  window. Throttled decisions are not recorded and leave the request to its sample rate.

## [0.2.0] - 2026-11-10

### Added

- Shield (bot & spam protection), off by default (`'shield' => ['enabled' => true]` or
  `WATCHTOWER_SHIELD`): `WatchTower::init()` evaluates rules managed in Watch Tower before the
  application runs: allow list, blocked IPs (IPv4/IPv6 CIDR), user agent expressions, path
  globs, rate limits (per `ip`, `user` or `ip_path`, with `block_seconds` penalties; APCu or
  `flock()`ed file counters), honeypot and Turnstile challenges. Blocks answer 403, rate limits
  429 with `Retry-After` (JSON when asked), then exit; monitor mode only reports. `mode_override`
  replaces the mode Watch Tower sends.
- Shield rules pulled from `/api/shield/v1/rules` with the environment token (1 s connect, 2 s
  total, `If-None-Match`/304), cached in an atomically written file for their ttl with the last
  good copy kept, and refreshed in the shutdown handler after the response. No copy means no
  rules (fail open).
- Turnstile challenge: a minimal interstitial for browser GETs that posts back to the same URL
  (`_wt_shield_verify`), siteverify with `turnstile_secret` / `TURNSTILE_SECRET`, and a signed,
  IP-bound `wt_shield_pass` cookie (30 minutes).
- `WatchTower::honeypotField()`; the WordPress mu-plugin adds it to the comment, login,
  registration and lost password forms when Shield is enabled.
- `wt-shield` records in the request's batch; `wt-hello` lists `shield` when enabled.
- `client_ip` option (`preset`, `header`, `trusted_proxies`; env `WATCHTOWER_CLIENT_IP_PRESET`
  and `WATCHTOWER_TRUSTED_PROXIES`): presets for Cloudflare (embedded edge ranges),
  DigitalOcean App Platform, AWS ALB/ELB, Heroku, Fly.io, Vercel, nginx and Traefik;
  `trusted_proxies` as CIDRs, `'private'`, `'cloudflare'` or `'*'`; RFC 7239 `Forwarded`
  support. One resolver (`WatchTower\Support\ClientIp`) feeds the request record, Shield and
  `wt-shield` records. The WordPress mu-plugin reads `WATCHTOWER_CLIENT_IP_PRESET`,
  `WATCHTOWER_CLIENT_IP_HEADER` and `WATCHTOWER_TRUSTED_PROXIES`.

### Deprecated

- `trusted_proxy_header`: use `client_ip.header`. It keeps working as an alias.

### Security

- Client IP spoofing: `trusted_proxy_header` was honoured from any sender and took the first
  (client-supplied) `X-Forwarded-For` entry, so anyone could choose the IP in request records,
  and with Shield bypass IP blocks and rate limits. The header is now only read when
  `REMOTE_ADDR` is a trusted proxy (private addresses by default for the legacy option, or
  `client_ip.trusted_proxies`), and `X-Forwarded-For` is walked right to left past trusted
  hops. **Behaviour change:** setups whose proxy connects from a public address must set
  `client_ip.trusted_proxies` (or a preset), otherwise `REMOTE_ADDR` is used.

## [0.1.0] - 2026-11-10

### Added

- `WatchTower::init()` with options and `WATCHTOWER_TOKEN` / `WATCHTOWER_URL` environment fallbacks.
- Laravel Nightwatch v1 records: `request`, `command`, `exception`, `log`, `query`,
  `outgoing-request` and `user`, plus Watch Tower `wt-hello` (with `sdk`, `sdk_version`,
  `php_version`, `framework`), `wt-trace-link` and `wt-output`.
- Request capture: route patterns (`WatchTower::route()` or derived from the path), status,
  duration, peak memory, request and response size, client IP (optional trusted proxy
  header), sanitised headers, users.
- Error capture: chained exception and error handlers that respect `error_reporting()` and
  `@`, plus fatal errors at shutdown.
- PSR-3 logger and a Monolog 2/3 handler.
- `TracedPdo`, a PDO subclass that records queries with literals stripped and the calling
  file and line.
- Outgoing HTTP: `WatchTower::curlExec()` and a Guzzle middleware, both adding W3C `traceparent`.
- CLI: automatic command record for scripts, `WatchTower::command()` wrappers with optional
  output capture, and `WatchTower::exitCode()`.
- Sampling (`sample_rate`, `exception_sample_rate`, `slow_request_ms`, `slow_query_ms`) and
  `ignore_paths`.
- Transport: gzip POST to `/api/ingest/v1` after the response, 1 s connect and 2 s total
  timeouts, 401 back-off, 413 splitting, a size-capped spool and the `bin/watchtower-flush` cron CLI.
- WordPress must-use plugin (`wordpress/watchtower.php`).
- Single-file build (`dist/watchtower.php`, `composer build`).
