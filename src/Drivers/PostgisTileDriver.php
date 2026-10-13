<?php

namespace ItsJustVita\VectorTiles\Drivers;

use Illuminate\Contracts\Auth\Authenticatable;
use ItsJustVita\VectorTiles\Layer;
use ItsJustVita\VectorTiles\Query\LayerQueryFactory;

class PostgisTileDriver implements TileDriverInterface
{
    public function __construct(
        protected LayerQueryFactory $queries,
    ) {}

    public function getTile(Layer $layer, int $z, int $x, int $y, ?Authenticatable $user = null): ?string
    {
        $query = $this->queries->make($layer, $user);
        $connection = $query->getConnection();

        $wrappedGeom = $query->getGrammar()->wrap($layer->geometry);

        $query->select([]);
        $query->selectRaw(
            "ST_AsMVTGeom(ST_Transform({$wrappedGeom}, 3857), ST_TileEnvelope(?, ?, ?), ?, ?, true) AS geom",
            [$z, $x, $y, $layer->extent, $layer->buffer],
        );

        if (! empty($layer->properties)) {
            $query->addSelect($layer->properties);
        }

        $query->whereRaw(
            "ST_Intersects(ST_Transform({$wrappedGeom}, 3857), ST_TileEnvelope(?, ?, ?))",
            [$z, $x, $y],
        );

        $innerSql = $query->toSql();
        $innerBindings = $query->getBindings();

        $sql = "SELECT ST_AsMVT(tile, ?) AS tile FROM ({$innerSql}) AS tile";
        $bindings = array_merge([$layer->name], $innerBindings);

        $result = $connection->selectOne($sql, $bindings);

        return $result?->tile;
    }
}
