# Laravel Vector Tiles

Serve Mapbox Vector Tiles (MVT) directly from PostGIS in Laravel. No separate tile server required.

## Requirements

- PHP 8.4+
- Laravel 13+
- PostgreSQL with PostGIS extension

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
    'glasfaser_trassen' => [
        'table' => 'trassen',
        'geometry' => 'geom',
        'srid' => 4326,
        'properties' => ['id', 'typ', 'status', 'ausbaustufe'],
        'min_zoom' => 10,
        'max_zoom' => 18,
    ],
],
```

**Fluent API:**

```php
use ItsJustVita\VectorTiles\Facades\VectorTiles;

// In a service provider's boot() method
VectorTiles::layer('glasfaser_trassen')
    ->from(Trasse::query()->where('status', 'aktiv'))
    ->geometry('geom')
    ->properties(['id', 'typ', 'status', 'ausbaustufe'])
    ->minZoom(10)
    ->maxZoom(18);
```

Tiles are served at `GET /tiles/{layer}/{z}/{x}/{y}.mvt`.

### Auth-Aware Tiles

Combine middleware for access control with scopes for per-user data filtering:

```php
VectorTiles::layer('glasfaser_trassen')
    ->from(Trasse::query())
    ->middleware(['auth:sanctum'])
    ->scope(fn (Builder $query, ?User $user) =>
        $user?->isAdmin() ? $query : $query->where('kunde_id', $user?->kunde_id)
    );
```

### Cache Invalidation

Tile cache is automatically flushed when observed models change:

```php
VectorTiles::layer('glasfaser_trassen')
    ->from(Trasse::query())
    ->invalidateOn(Trasse::class);
```

### GeoJSON Endpoint

Each layer also exposes a GeoJSON endpoint with required bounding box filtering:

```
GET /geojson/{layer}?bbox=9.0,48.0,9.5,48.5&limit=500
```

### MapLibre Integration

Generate source configuration for MapLibre GL JS:

```php
$source = VectorTiles::maplibreSource('glasfaser_trassen');
// Returns: ['type' => 'vector', 'tiles' => [...], 'minzoom' => 10, 'maxzoom' => 18]

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

$response = $this->get('/tiles/trassen/14/8532/5765.mvt');
$response->assertOk();

VectorTiles::assertTileRequested('trassen', 14, 8532, 5765);
```

## How It Works

The package uses PostGIS functions `ST_AsMVT` and `ST_AsMVTGeom` to render vector tiles server-side. Eloquent query builders are supported as layer sources, enabling standard Laravel scopes, relations, and authorization patterns to control tile content.

Tile caching uses Laravel's cache system with a key-registry approach for efficient per-layer flushing. Model observers can automatically invalidate cached tiles when source data changes.

## License

MIT
