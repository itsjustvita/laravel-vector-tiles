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

### Layer Options

| Config key | Fluent method | Default | Description |
|------------|---------------|---------|-------------|
| `table` | `from()` | layer name | Source table; `from()` also accepts an Eloquent query |
| `geometry` | `geometry()` | `geom` | Geometry column |
| `srid` | `srid()` | `4326` | SRID of the geometry column |
| `properties` | `properties()` | `[]` | Columns included as feature attributes |
| `min_zoom` | `minZoom()` | `0` | Lowest zoom level served |
| `max_zoom` | `maxZoom()` | `22` | Highest zoom level served |
| `simplify` | `simplify()` | off | Simplification tolerance in pixels, see [Zoom-Dependent Simplification](#zoom-dependent-simplification-and-filtering) |
| `middleware` | `middleware()` | `[]` | Middleware for this layer, see [Auth-Aware Tiles](#auth-aware-tiles) |
| `connection` | `connection()` | default connection | Database connection of table sources |
| `extent` | `extent()` | `4096` | Tile coordinate resolution |
| `buffer` | `buffer()` | `256` | Area around the tile, in extent units, from which features are included, so labels and icons at tile edges are not clipped |
| | `scope()` | | Per-user filter, see [Auth-Aware Tiles](#auth-aware-tiles) |
| | `filterByZoom()` | | Per-zoom filter, see [Zoom-Dependent Simplification](#zoom-dependent-simplification-and-filtering) |
| | `invalidateOn()` | | Model whose changes flush the layer, see [Cache Invalidation](#cache-invalidation) |

If your data is not stored in EPSG:4326, set its SRID, for example `->srid(25832)`. `php artisan vector-tiles:doctor` reports a mismatch.

### Endpoints

| Endpoint | Returns |
|----------|---------|
| `GET /tiles/{layer}/{z}/{x}/{y}.mvt` | The vector tile |
| `GET /tiles/{layer}.json` | [TileJSON 3.0.0](https://github.com/mapbox/tilejson-spec) metadata: tile URL, zoom range and property names |
| `GET /geojson/{layer}?bbox=…` | Features in a bounding box, see [GeoJSON Endpoint](#geojson-endpoint) |

The URL prefixes are set with `prefix` and `geojson.prefix` in the config. Layer middleware applies to all three endpoints.

An unknown layer returns `404`. A zoom level outside the layer's zoom range, or an `x` or `y` outside the tile grid of the zoom level, returns `422`. A tile without features returns `204 No Content`.

### Auth-Aware Tiles

Combine middleware for access control with scopes for per-user data filtering:

```php
use Illuminate\Database\Query\Builder;

VectorTiles::layer('buildings')
    ->from(Building::query())
    ->middleware(['auth:sanctum'])
    ->scope(fn (Builder $query, ?User $user) =>
        $user?->isAdmin() ? $query : $query->where('owner_id', $user?->id)
    );
```

Layer middleware accepts the same aliases and parameters as route middleware, for example `auth:sanctum` or `can:view-map`. The scope receives the underlying `Illuminate\Database\Query\Builder`. OR conditions in the source query and in the scope are grouped automatically, so a scope can never be bypassed.

### Cache Invalidation

Tile cache is automatically flushed when observed models change:

```php
VectorTiles::layer('buildings')
    ->from(Building::query())
    ->invalidateOn(Building::class);
```

To flush cached tiles yourself, for example after a bulk import that bypasses model events, use the `TileCache` service:

```php
use ItsJustVita\VectorTiles\Cache\TileCache;

app(TileCache::class)->flushLayer('buildings');
app(TileCache::class)->flushAll();
```

Flushing only affects the server-side cache. Browsers keep tiles they already loaded for up to `cache.ttl` seconds, see [HTTP Caching](#http-caching-and-compression).

### Zoom-Dependent Simplification and Filtering

By default, every zoom level gets full geometry detail. For overview zoom levels, simplify geometries and limit which features a tile contains:

```php
use Illuminate\Database\Query\Builder;

VectorTiles::layer('roads')
    ->from(Road::query())
    ->maxZoom(16)
    ->simplify(1.5)
    ->filterByZoom(function (Builder $query, int $z) {
        if ($z < 11) {
            $query->whereIn('highway', ['motorway', 'trunk', 'primary']);
        }
    });
```

`simplify()` takes a tolerance in screen pixels of a 512 px tile, so lines look the same while detail below the screen resolution is dropped. Values between 1 and 2 work well. Polygons smaller than the tolerance disappear. Tiles at the layer's `maxZoom` keep full detail, because map clients overzoom them. In config arrays, use the `simplify` key; `0` or an empty value turns simplification off.

`filterByZoom()` receives the query and the zoom level and runs after the layer scope. Neither option applies to the GeoJSON endpoint, which has no zoom level.

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

Run `php artisan vector-tiles:doctor` to check whether PostgreSQL can use an index for each layer.

### HTTP Caching and Compression

Tiles are sent with `Cache-Control: public, max-age={cache.ttl}`. As soon as a layer has middleware or a scope, or global middleware is configured, tiles are sent as `private`, so shared caches and CDNs never serve one user's tiles to another.

Empty tiles (`204 No Content`) are cached as well.

Cached tiles are stored per layer, tile and authenticated user, so a user never receives a tile rendered for someone else. If a scope depends on something other than the user, such as the current team, [flush the layer](#cache-invalidation) when that context changes.

The package does not compress tiles itself. Enable compression for `application/vnd.mapbox-vector-tile` in your web server, for example `encode gzip zstd` in Caddy or `gzip_types application/vnd.mapbox-vector-tile;` in nginx.

### Rate Limiting

A map loads many tiles at once: one view needs a dozen or more tiles per layer, and every pan or zoom loads new ones. Tiles and TileJSON are therefore not throttled by default. The GeoJSON endpoint is limited to 60 requests per minute, per IP address, or per user if the global `middleware` authenticates the request.

Both limits take a number of requests and minutes, the name of a rate limiter, or `null`:

```php
// config/vector-tiles.php
'throttle' => '600,1', // tiles and TileJSON: 600 requests per minute
'throttle' => 'tiles', // a limiter defined with RateLimiter::for('tiles', ...)

'geojson' => [
    // ...
    'throttle' => null, // GeoJSON: no rate limiting
],
```

Requests to the package routes are counted separately from other throttled routes of your application, and tiles separately from GeoJSON.

If you published the config before 0.3.1, it still contains `'throttle' => '60,1'`, which limits tiles to 60 requests per minute and also applies to the GeoJSON endpoint as long as `geojson.throttle` is missing. Set `throttle` to `null` and add `geojson.throttle` to get the new defaults.

### CORS

All responses of the package routes carry `Access-Control-Allow-Origin: *`, including errors such as `404` or `429`, so maps on other domains can load tiles, TileJSON and GeoJSON. Set `cors.allowed_origins` to a single origin to restrict this, or to `null` to omit the header. An `Access-Control-Allow-Origin` set by your own middleware is kept.

Requests with credentials, such as cookies or an `Authorization` header, need more than this header, and the browser sends a preflight `OPTIONS` request first. For those, let Laravel's CORS middleware handle the routes instead: set `cors.allowed_origins` to `null`, run `php artisan config:publish cors` and add your prefixes to `paths` in `config/cors.php`, for example `'tiles/*'` and `'geojson/*'`.

### GeoJSON Endpoint

Each layer also exposes a GeoJSON endpoint with required bounding box filtering:

```
GET /geojson/{layer}?bbox=9.0,48.0,9.5,48.5&limit=500
```

The `bbox` is `west,south,east,north` in WGS84 and must lie within [-180, 180] / [-90, 90] with west ≤ east and south ≤ north.

### MapLibre Integration

Point a MapLibre GL JS source at the layer's TileJSON URL. The `source-layer` is the layer name:

```js
map.on('load', () => {
    map.addSource('buildings', {
        type: 'vector',
        url: 'https://example.com/tiles/buildings.json',
    });

    map.addLayer({
        id: 'buildings',
        type: 'fill',
        source: 'buildings',
        'source-layer': 'buildings',
        paint: { 'fill-color': '#4a90d9', 'fill-opacity': 0.6 },
    });
});
```

Or generate the source configuration in PHP, for example to pass it to your frontend:

```php
$source = VectorTiles::maplibreSource('buildings');
// Returns: ['type' => 'vector', 'tiles' => [...], 'minzoom' => 12, 'maxzoom' => 18]

$allSources = VectorTiles::maplibreSources();
```

### Checking Layers

```bash
php artisan vector-tiles:doctor
php artisan vector-tiles:doctor roads --strict
```

For each layer, on its own connection, the doctor checks:

- that PostGIS 3.0 or newer is installed
- that the source, the geometry column and all properties exist
- that the SRID matches the layer
- whether PostgreSQL can use a spatial index for tile queries; for a missing index it prints the matching `CREATE INDEX` statement

```
roads
  ✓ PostGIS 3.5.2 on connection pgsql
  ✓ Source roads exists
  ✓ Geometry column geom (geometry, SRID 4326)
  ✓ Properties: id, highway, name
  ⚠ No usable spatial index (Seq Scan on roads)
      CREATE INDEX roads_geom_idx ON roads USING gist (geom);
```

The doctor only runs `SELECT` and `EXPLAIN`, inside transactions that are always rolled back, so it is safe against production and read-only databases. It exits with 1 when a check fails, and with `--strict` also on warnings. If a layer scope needs a user, pass `--user=ID`.

### Benchmarking

```bash
php artisan vector-tiles:benchmark roads --zoom=8-16 --tiles=20
```

The benchmark renders tiles where the layer has data. Per zoom level it reports:

- p50, p95 and maximum render time
- average and maximum tile size, raw and gzip-compressed

Timings cover the SQL query and the MVT encoding, without HTTP and cache. Sampling picks random features, which scans the layer query once and can take a few seconds on tables with millions of rows.

- `--output=tiles.txt` saves the sampled tiles. `--tiles-from=tiles.txt` measures exactly these tiles again, for example before and after a change. The file has one `z/x/y` per line, so other tools can replay it too.
- `--bbox=west,south,east,north` limits sampling to an area.
- `--json` prints machine-readable results.
- `--user=ID` runs a user-dependent scope.

## Configuration

| Key | Default | Description |
|-----|---------|-------------|
| `prefix` | `tiles` | URL prefix for tile and TileJSON routes |
| `middleware` | `[]` | Global middleware for all routes |
| `throttle` | `null` | Rate limit for tile and TileJSON routes, see [Rate Limiting](#rate-limiting) |
| `cache.enabled` | `true` | Enable tile caching |
| `cache.store` | `null` | Cache store (null = default) |
| `cache.ttl` | `3600` | Cache TTL in seconds, also sent as `max-age` |
| `cors.allowed_origins` | `*` | `Access-Control-Allow-Origin` of all responses, see [CORS](#cors); `null` omits it |
| `geojson.prefix` | `geojson` | URL prefix for GeoJSON routes |
| `geojson.default_limit` | `1000` | Default feature limit |
| `geojson.max_limit` | `5000` | Maximum feature limit |
| `geojson.throttle` | `60,1` | Rate limit for the GeoJSON route; without this key, `throttle` applies |
| `layers` | `[]` | Layer definitions, see [Layer Options](#layer-options) |

## Testing

The package provides a fake driver for testing without PostGIS:

```php
use ItsJustVita\VectorTiles\Facades\VectorTiles;

VectorTiles::fake();

$response = $this->get('/tiles/buildings/14/8532/5765.mvt');
$response->assertOk();

VectorTiles::assertTileRequested('buildings', 14, 8532, 5765);
VectorTiles::assertTileNotRequested('buildings', 14, 8533, 5765);
```

## How It Works

The package uses PostGIS functions `ST_AsMVT` and `ST_AsMVTGeom` to render vector tiles server-side. Eloquent query builders are supported as layer sources, enabling standard Laravel scopes, relations, and authorization patterns to control tile content.

Tile caching uses Laravel's cache system with versioned keys: flushing a layer bumps its version counter, so stale tiles are never read again and expire on their own. This works with every cache store. Model observers can automatically invalidate cached tiles when source data changes.

## License

MIT
