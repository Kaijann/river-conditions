# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0] - 2026-10-13

### Added
- `river-conditions/gauge` block (card and compact layouts, live site validation, server-rendered editor preview).
- `[river_conditions]` shortcode sharing the block's renderer.
- USGS Water Data OGC API client with transient caching, 48-hour last-known-good fallback, fetch lock and optional cron warming.
- Settings page: API key, cache duration, default temperature unit, attribution, Test connection, Clear cache.
- REST API `river-conditions/v1`: `/sites/{id}`, `/render`, `/test`.
- Unit tests against real USGS responses, plus a wp-env smoke test in CI against the live API.

### Fixed (found by testing against the live API before release)
- The 7-day flow series now uses an explicit datetime interval; the API rejects `P7D`.
- Site names drop the trailing state code ("…, MT") when the state is shown.
- The cache-warming cron is scheduled correctly on activation.
- The stylesheet is not loaded for invalid site IDs.
