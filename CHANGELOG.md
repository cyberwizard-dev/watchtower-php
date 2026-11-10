# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

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
