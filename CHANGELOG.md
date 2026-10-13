# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/) and the project uses
[Semantic Versioning](https://semver.org/).

## [Unreleased]

## [1.0.0]

### Added
- `river-conditions/gauge` block (card and compact layouts, live site validation, server-rendered editor preview).
- `[river_conditions]` shortcode sharing the block's renderer.
- USGS Water Data OGC API client with transient caching, 48-hour last-known-good fallback, fetch lock and optional cron warming.
- Settings page: API key, cache duration, default temperature unit, attribution, Test connection, Clear cache.
- REST API `river-conditions/v1`: `/sites/{id}`, `/render`, `/test`.
