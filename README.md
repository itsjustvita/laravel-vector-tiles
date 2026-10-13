# Laravel Vector Tiles

Serve Mapbox Vector Tiles (MVT) directly from PostGIS in Laravel. No separate tile server required.

## Requirements

- PHP 8.3+
- Laravel 12 or 13
- PostgreSQL with PostGIS 3.0+

## Installation

```bash
composer require itsjustvita/laravel-vector-tiles
php artisan vector-tiles:install
```

## Usage

### Defining Layers

Layers can be defined in `config/vector-tiles.php` for static definitions, or via the fluent API in a service provider for dynamic, auth-aware layers.

**Config-based:**

```php
// config/vector-tiles.php
'layers' => [
    'buildings' => [
        'table' => 'buildings',
        'geometry' => 'geom',
        'srid' => 4326,
        'properties' => ['id', 'name', 'type', 'height'],
        'min_zoom' => 12,
        'max_zoom' => 18,
    ],
],
```

**Fluent API:**

```php
use ItsJustVita\VectorTiles\Facades\VectorTiles;

// In a service provider's boot() method
VectorTiles::layer('buildings')
    ->from(Building::query()->where('status', 'published'))
    ->geometry('geom')
    ->properties(['id', 'name', 'type', 'height'])
    ->minZoom(12)
    ->maxZoom(18);
```

Eloquent sources run on the model's database connection. For table sources, set the connection with `->connection('gis')` or the `connection` config key.

Tiles are served at `GET /tiles/{layer}/{z}/{x}/{y}.mvt`.

### Auth-Aware Tiles

Combine middleware for access control with scopes for per-user data filtering:

```php
VectorTiles::layer('buildings')
    ->from(Building::query())
    ->middleware(['auth:sanctum'])
    ->scope(fn (Builder $query, ?User $user) =>
        $user?->isAdmin() ? $query : $query->where('owner_id', $user?->id)
    );
```

### Cache Invalidation

Tile cache is automatically flushed when observed models change:

```php
VectorTiles::layer('buildings')
    ->from(Building::query())
    ->invalidateOn(Building::class);
```

### Spatial Indexes

For layers stored in EPSG:3857 or EPSG:4326, tile and GeoJSON queries use a GiST index on the geometry column:

```sql
CREATE INDEX buildings_geom_idx ON buildings USING gist (geom);
```

Layers stored in any other SRID (for example EPSG:25832) are transformed on the fly. Add expression indexes so these queries stay fast:

```sql
CREATE INDEX buildings_geom_3857_idx ON buildings USING gist (ST_Transform(geom, 3857)); -- tiles
CREATE INDEX buildings_geom_4326_idx ON buildings USING gist (ST_Transform(geom, 4326)); -- GeoJSON
```

### HTTP Caching and Compression

Tiles are sent with `Cache-Control: public, max-age={cache.ttl}`. As soon as a layer has middleware or a scope, or global middleware is configured, tiles are sent as `private`, so shared caches and CDNs never serve one user's tiles to another.

Empty tiles return `204 No Content` and are cached as well.

The package does not compress tiles itself. Enable compression for `application/vnd.mapbox-vector-tile` in your web server, for example `encode gzip zstd` in Caddy or `gzip_types application/vnd.mapbox-vector-tile;` in nginx.

### GeoJSON Endpoint

Each layer also exposes a GeoJSON endpoint with required bounding box filtering:

```
GET /geojson/{layer}?bbox=9.0,48.0,9.5,48.5&limit=500
```

The `bbox` is `west,south,east,north` in WGS84 and must lie within [-180, 180] / [-90, 90] with west ≤ east and south ≤ north.

### MapLibre Integration

Generate source configuration for MapLibre GL JS:

```php
$source = VectorTiles::maplibreSource('buildings');
// Returns: ['type' => 'vector', 'tiles' => [...], 'minzoom' => 12, 'maxzoom' => 18]

$allSources = VectorTiles::maplibreSources();
```

## Configuration

| Key | Default | Description |
|-----|---------|-------------|
| `prefix` | `tiles` | URL prefix for tile routes |
| `middleware` | `[]` | Global middleware for all routes |
| `cache.enabled` | `true` | Enable tile caching |
| `cache.store` | `null` | Cache store (null = default) |
| `cache.ttl` | `3600` | Cache TTL in seconds |
| `geojson.prefix` | `geojson` | URL prefix for GeoJSON routes |
| `geojson.default_limit` | `1000` | Default feature limit |
| `geojson.max_limit` | `5000` | Maximum feature limit |

## Testing

The package provides a fake driver for testing without PostGIS:

```php
use ItsJustVita\VectorTiles\Facades\VectorTiles;

VectorTiles::fake();

$response = $this->get('/tiles/buildings/14/8532/5765.mvt');
$response->assertOk();

VectorTiles::assertTileRequested('buildings', 14, 8532, 5765);
```

## How It Works

The package uses PostGIS functions `ST_AsMVT` and `ST_AsMVTGeom` to render vector tiles server-side. Eloquent query builders are supported as layer sources, enabling standard Laravel scopes, relations, and authorization patterns to control tile content.

Tile caching uses Laravel's cache system with versioned keys: flushing a layer bumps its version counter, so stale tiles are never read again and expire on their own. This works with every cache store. Model observers can automatically invalidate cached tiles when source data changes.

## License

MIT
