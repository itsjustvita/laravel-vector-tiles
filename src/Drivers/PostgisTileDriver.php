<?php

namespace ItsJustVita\VectorTiles\Drivers;

use Illuminate\Contracts\Auth\Authenticatable;
use ItsJustVita\VectorTiles\Layer;
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
        $query = $this->queries->make($layer, $user);

        $geometry = $query->getGrammar()->wrap($layer->geometry);
        $srid = (int) $layer->srid;
        $mercator = $srid === 3857 ? $geometry : "ST_Transform({$geometry}, 3857)";

        $query->select([]);
        $query->selectRaw(
            "ST_AsMVTGeom({$mercator}, ST_TileEnvelope(?, ?, ?), ?::int4, ?::int4, true) AS __vt_geom",
            [$z, $x, $y, $layer->extent, $layer->buffer],
        );

        if (! empty($layer->properties)) {
            $query->addSelect($layer->properties);
        }

        $query->whereRaw(
            $this->tileFilter($geometry, $srid),
            WebMercator::tileBounds($z, $x, $y, $layer->buffer / $layer->extent),
        );

        $result = $query->getConnection()->selectOne(
            "SELECT ST_AsMVT(tile, ?::text, ?::int4, '__vt_geom') AS tile FROM ({$query->toSql()}) AS tile",
            array_merge([$layer->name, $layer->extent], $query->getBindings()),
        );

        $tile = $result?->tile;

        return is_resource($tile) ? (string) stream_get_contents($tile) : (string) $tile;
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
