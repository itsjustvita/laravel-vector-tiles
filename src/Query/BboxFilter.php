<?php

namespace ItsJustVita\VectorTiles\Query;

use Illuminate\Database\Query\Builder as QueryBuilder;
use InvalidArgumentException;
use ItsJustVita\VectorTiles\Support\WebMercator;

final class BboxFilter
{
    /**
     * Parse "west,south,east,north" in WGS84.
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     *
     * @throws InvalidArgumentException with a message fit for validation errors
     */
    public static function parse(string $bbox): array
    {
        $parts = explode(',', $bbox);

        if (count($parts) !== 4) {
            throw new InvalidArgumentException('The bbox must contain exactly 4 comma-separated coordinates (west,south,east,north).');
        }

        foreach ($parts as $part) {
            if (! is_numeric(trim($part))) {
                throw new InvalidArgumentException('All bbox coordinates must be numeric.');
            }
        }

        [$west, $south, $east, $north] = array_map(fn (string $value) => (float) trim($value), $parts);

        if ($west < -180 || $east > 180 || $west > $east) {
            throw new InvalidArgumentException('The bbox longitudes must lie within [-180, 180] with west <= east.');
        }

        if ($south < -90 || $north > 90 || $south > $north) {
            throw new InvalidArgumentException('The bbox latitudes must lie within [-90, 90] with south <= north.');
        }

        return [$west, $south, $east, $north];
    }

    /**
     * Restrict the query to geometries intersecting the bbox, keeping a
     * spatial index usable for the layer's SRID.
     *
     * The bbox is only transformed to EPSG:3857, after clamping both latitudes
     * to the range EPSG:3857 is defined for. Other SRIDs transform the geometry
     * instead, which can use an expression index on ST_Transform(geom, 4326).
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}  $bbox  west, south, east, north in WGS84
     */
    public static function apply(QueryBuilder $query, string $geometryColumn, int $srid, array $bbox): void
    {
        [$west, $south, $east, $north] = $bbox;

        if ($srid === 3857) {
            $south = max(-WebMercator::MAX_LATITUDE, min(WebMercator::MAX_LATITUDE, $south));
            $north = max(-WebMercator::MAX_LATITUDE, min(WebMercator::MAX_LATITUDE, $north));
        }

        $geometry = $query->getGrammar()->wrap($geometryColumn);
        $envelope = 'ST_MakeEnvelope(?, ?, ?, ?, 4326)';

        $filter = match ($srid) {
            4326 => "ST_Intersects({$geometry}, {$envelope})",
            3857 => "ST_Intersects({$geometry}, ST_Transform({$envelope}, 3857))",
            default => "ST_Intersects(ST_Transform({$geometry}, 4326), {$envelope})",
        };

        $query->whereRaw($filter, [$west, $south, $east, $north]);
    }
}
