# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

This release makes the package work against a real PostGIS database and verifies it with integration tests in CI.

### Added

- Support for Laravel 12 and PHP 8.3.
- TileJSON 3.0.0 metadata endpoint at `GET /tiles/{layer}.json`.
- PostGIS version check in `vector-tiles:install`.
- PostGIS integration test suite, run in CI against `postgis/postgis`.

### Fixed

- Tile requests failed with a `TypeError`, because `pdo_pgsql` returns `bytea` columns as streams.
- The tile query transformed the geometry column, which prevented the use of spatial indexes.
- Tiles at the antimeridian and at low zoom levels could select features from the wrong part of the world.
- The GeoJSON endpoint failed for layers whose SRID is not 4326.
- The GeoJSON endpoint failed on PostgreSQL for layers with properties (`could not determine data type of parameter`).
- A custom `extent` was not passed to `ST_AsMVT`.
- Eloquent sources on a non-default connection were queried on the default connection.
- The tile cache kept an ever-growing key registry with race conditions; flushing is now O(1).
- The `Content-Encoding: identity` header prevented web servers from compressing tiles.

### Changed

- **Breaking:** tiles of layers with middleware or a scope, or with global middleware configured, are sent with `Cache-Control: private`.
- **Breaking:** new tile cache key format; previously cached tiles are ignored and expire on their own.
- **Breaking:** Eloquent sources always use the model's connection; `connection()` applies to table sources only.
- **Breaking:** the GeoJSON endpoint rejects bounding boxes outside [-180, 180] / [-90, 90] or with west > east or south > north.
- **Breaking:** `PostgisTileDriver` depends on `LayerQueryFactory` instead of `DatabaseManager`.
- Empty tiles are cached and returned as `204 No Content` with cache headers.
- Layers in SRIDs other than 3857 and 4326 transform the geometry for filtering; see the README for the recommended expression indexes.

## [0.1.2-alpha] - 2026-04-09

### Added

- Configurable rate limiting, `Cache-Control` max-age and CORS header for tile routes.

### Fixed

- Query constraints of Eloquent sources were lost in the tile driver and the GeoJSON endpoint.
- Per-layer middleware was not applied to GeoJSON requests.

## [0.1.1-alpha] - 2026-04-02

### Changed

- README uses generic examples.

## [0.1.0-alpha] - 2026-04-01

### Added

- Initial release: MVT tiles from PostGIS, fluent and config-based layers, auth-aware scopes, per-layer middleware, tile caching with model-based invalidation, GeoJSON endpoint, MapLibre source helper, `VectorTiles::fake()`, and the `vector-tiles:install` command.
