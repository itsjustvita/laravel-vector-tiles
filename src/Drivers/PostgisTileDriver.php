<?php

namespace ItsJustVita\VectorTiles\Drivers;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use ItsJustVita\VectorTiles\Layer;

class PostgisTileDriver implements TileDriverInterface
{
    public function __construct(
        protected DatabaseManager $db,
    ) {}

    public function getTile(Layer $layer, int $z, int $x, int $y, ?Authenticatable $user = null): ?string
    {
        $table = $layer->getTable();
        $geometry = $layer->geometry;
        $extent = $layer->extent;
        $buffer = $layer->buffer;
        $properties = $this->buildPropertyColumns($layer->properties);

        $connection = $this->db->connection($layer->connection);

        // Collect extra WHERE clauses and bindings
        $extraWhere = '';
        $extraBindings = [];

        // From Eloquent Builder source
        if ($layer->source instanceof EloquentBuilder) {
            $base = $layer->source->toBase();
            $rawBindings = $base->getRawBindings();
            foreach ($base->wheres as $where) {
                if ($where['type'] === 'Basic') {
                    $extraWhere .= " AND \"{$where['column']}\" {$where['operator']} ?";
                }
            }
            $extraBindings = array_merge($extraBindings, $rawBindings['where'] ?? []);
        }

        // From scope closure (receives a query builder + user)
        if ($layer->scope !== null) {
            $scopeQuery = $connection->table($table);
            ($layer->scope)($scopeQuery, $user);
            foreach ($scopeQuery->wheres ?? [] as $where) {
                if ($where['type'] === 'Basic') {
                    $extraWhere .= " AND \"{$where['column']}\" {$where['operator']} ?";
                }
            }
            $extraBindings = array_merge($extraBindings, $scopeQuery->getBindings());
        }

        // Build the full ST_AsMVT SQL
        $propertySql = $properties ? ", {$properties}" : '';
        $spatialWhere = "ST_Intersects(ST_Transform(\"{$geometry}\", 3857), ST_TileEnvelope(?, ?, ?))";

        $sql = <<<SQL
            SELECT ST_AsMVT(tile, ?) AS tile
            FROM (
                SELECT
                    ST_AsMVTGeom(
                        ST_Transform("{$geometry}", 3857),
                        ST_TileEnvelope(?, ?, ?),
                        {$extent},
                        {$buffer},
                        true
                    ) AS geom{$propertySql}
                FROM "{$table}"
                WHERE {$spatialWhere}{$extraWhere}
            ) AS tile
            SQL;

        $bindings = array_merge(
            [$layer->name],     // ST_AsMVT layer name
            [$z, $x, $y],      // ST_TileEnvelope for ST_AsMVTGeom
            [$z, $x, $y],      // ST_TileEnvelope for ST_Intersects
            $extraBindings,     // Eloquent + scope bindings
        );

        $result = $connection->selectOne($sql, $bindings);

        if ($result === null || $result->tile === null) {
            return null;
        }

        return $result->tile;
    }

    protected function buildPropertyColumns(array $properties): string
    {
        if (empty($properties)) {
            return '';
        }

        return implode(', ', array_map(
            fn (string $col) => "\"{$col}\"",
            $properties,
        ));
    }
}
