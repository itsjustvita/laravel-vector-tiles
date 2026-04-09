<?php

namespace ItsJustVita\VectorTiles\Drivers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use ItsJustVita\VectorTiles\Layer;

class PostgisTileDriver implements TileDriverInterface
{
    public function __construct(
        protected DatabaseManager $db,
    ) {}

    public function getTile(Layer $layer, int $z, int $x, int $y, ?Authenticatable $user = null): ?string
    {
        $connection = $this->db->connection($layer->connection);
        $grammar = $connection->getQueryGrammar();

        $query = $this->buildBaseQuery($layer, $connection);

        if ($layer->scope !== null) {
            ($layer->scope)($query, $user);
        }

        $wrappedGeom = $grammar->wrap($layer->geometry);

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

    protected function buildBaseQuery(Layer $layer, $connection): QueryBuilder
    {
        if ($layer->source instanceof EloquentBuilder) {
            return clone $layer->source->toBase();
        }

        if (is_string($layer->source)) {
            return $connection->table($layer->source);
        }

        return $connection->table($layer->name);
    }
}
