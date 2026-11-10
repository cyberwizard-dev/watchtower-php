# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

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
