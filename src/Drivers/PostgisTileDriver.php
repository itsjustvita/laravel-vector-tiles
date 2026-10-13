<?php

namespace ItsJustVita\VectorTiles\Drivers;

use Illuminate\Contracts\Auth\Authenticatable;
use ItsJustVita\VectorTiles\Layer;
use ItsJustVita\VectorTiles\Query\CompiledQuery;
use ItsJustVita\VectorTiles\Query\LayerQueryFactory;
use ItsJustVita\VectorTiles\Support\WebMercator;

class PostgisTileDriver implements TileDriverInterface
{
    public function __construct(
        protected LayerQueryFactory $queries,
    ) {}

    /**
     * Render the tile as MVT bytes. An empty string means the tile has no features.
     */
    public function getTile(Layer $layer, int $z, int $x, int $y, ?Authenticatable $user = null): ?string
    {
        $query = $this->tileQuery($layer, $z, $x, $y, $user);

        $tile = $query->connection->selectOne($query->sql, $query->bindings)?->tile;

        return is_resource($tile) ? (string) stream_get_contents($tile) : (string) $tile;
    }

    /**
     * The complete SQL statement that renders the tile, so tools can inspect
     * exactly what a tile request runs.
     */
    public function tileQuery(Layer $layer, int $z, int $x, int $y, ?Authenticatable $user = null): CompiledQuery
    {
        $query = $this->queries->make($layer, $user, $z);

        $geometry = $query->getGrammar()->wrap($layer->geometry);
        $srid = (int) $layer->srid;

        $mercator = $this->toMercator($geometry, $srid);
        $bindings = [$z, $x, $y, $layer->extent, $layer->buffer];

        $tolerance = $this->simplifyTolerance($layer, $z);

        if ($tolerance !== null) {
            $mercator = "ST_Simplify({$mercator}, ?::float8, false)";
            array_unshift($bindings, $tolerance);
        }

        $query->select([]);
        $query->selectRaw(
            "ST_AsMVTGeom({$mercator}, ST_TileEnvelope(?, ?, ?), ?::int4, ?::int4, true) AS __vt_geom",
            $bindings,
        );

        if (! empty($layer->properties)) {
            $query->addSelect($layer->properties);
        }

        $query->whereRaw(
            $this->tileFilter($geometry, $srid),
            WebMercator::tileBounds($z, $x, $y, $layer->buffer / $layer->extent),
        );

        return new CompiledQuery(
            $query->getConnection(),
            "SELECT ST_AsMVT(__vt_tile, ?::text, ?::int4, '__vt_geom') AS tile FROM ({$query->toSql()}) AS __vt_tile",
            array_merge([$layer->name, $layer->extent], $query->getBindings()),
        );
    }

    /**
     * Simplification tolerance in EPSG:3857 meters, or null when the layer is
     * not simplified at zoom $z. Tiles at the layer's maxZoom keep full detail,
     * because map clients overzoom them.
     */
    protected function simplifyTolerance(Layer $layer, int $z): ?float
    {
        if ($layer->simplify === null || $z >= $layer->maxZoom) {
            return null;
        }

        return $layer->simplify * WebMercator::tileWidth($z) / 512;
    }

    /**
     * Geometry expression in EPSG:3857. WGS84 geometries reaching beyond the
     * Web Mercator latitude limit are clipped first, because transforming a
     * pole to EPSG:3857 fails.
     */
    protected function toMercator(string $geometry, int $srid): string
    {
        $lat = WebMercator::MAX_LATITUDE;

        return match ($srid) {
            3857 => $geometry,
            4326 => "ST_Transform(CASE WHEN ST_YMin({$geometry}) < -{$lat} OR ST_YMax({$geometry}) > {$lat}"
                ." THEN ST_ClipByBox2D({$geometry}, ST_MakeEnvelope(-180, -{$lat}, 180, {$lat}, 4326))"
                ." ELSE {$geometry} END, 3857)",
            default => "ST_Transform({$geometry}, 3857)",
        };
    }

    /**
     * Bounding-box filter that keeps a spatial index usable for the layer's SRID.
     *
     * The envelope is only ever transformed to EPSG:4326, which is valid for the
     * whole world. Other SRIDs transform the geometry instead, which can use an
     * expression index on ST_Transform(geom, 3857).
     */
    protected function tileFilter(string $geometry, int $srid): string
    {
        $envelope = 'ST_MakeEnvelope(?, ?, ?, ?, 3857)';

        return match ($srid) {
            3857 => "{$geometry} && {$envelope}",
            4326 => "{$geometry} && ST_Transform({$envelope}, 4326)",
            default => "ST_Transform({$geometry}, 3857) && {$envelope}",
        };
    }
}
