<?php

namespace ItsJustVita\VectorTiles\Benchmark;

use Illuminate\Contracts\Auth\Authenticatable;
use ItsJustVita\VectorTiles\Layer;
use ItsJustVita\VectorTiles\Query\BboxFilter;
use ItsJustVita\VectorTiles\Query\LayerQueryFactory;
use ItsJustVita\VectorTiles\Support\WebMercator;

class TileSampler
{
    public function __construct(
        protected LayerQueryFactory $queries,
    ) {}

    /**
     * Random positions on the layer's features in WGS84, so benchmarks hit
     * tiles where the layer has data. Ordering by random() scans the layer
     * query once.
     *
     * @param  array{0: float, 1: float, 2: float, 3: float}|null  $bbox
     * @return list<array{0: float, 1: float}>
     */
    public function points(Layer $layer, int $limit, ?array $bbox = null, ?Authenticatable $user = null): array
    {
        $query = $this->queries->make($layer, $user);
        $geometry = $query->getGrammar()->wrap($layer->geometry);

        if ($bbox !== null) {
            BboxFilter::apply($query, $layer->geometry, (int) $layer->srid, $bbox);
        }

        $query->select([])
            ->selectRaw("{$geometry} AS __vt_geom")
            ->whereNotNull($layer->geometry)
            ->orderByRaw('random()')
            ->limit($limit);

        $rows = $query->getConnection()->select(
            'SELECT ST_X(__vt_point) AS lon, ST_Y(__vt_point) AS lat FROM ('
            .'SELECT ST_Transform(ST_PointOnSurface(__vt_geom), 4326) AS __vt_point'
            ." FROM ({$query->toSql()}) AS __vt_sample"
            .') AS __vt_points WHERE NOT ST_IsEmpty(__vt_point)',
            $query->getBindings(),
        );

        return array_map(fn (object $row) => [(float) $row->lon, (float) $row->lat], $rows);
    }

    /**
     * Distinct tiles per zoom level that contain the given points, in the
     * order of the points.
     *
     * @param  list<array{0: float, 1: float}>  $points  WGS84 lon/lat
     * @param  list<int>  $zooms
     * @return list<array{0: int, 1: int, 2: int}> z, x, y
     */
    public static function tiles(array $points, array $zooms, int $perZoom): array
    {
        $tiles = [];

        foreach ($zooms as $z) {
            $picked = [];

            foreach ($points as [$lon, $lat]) {
                [$x, $y] = WebMercator::tileAt($lon, $lat, $z);
                $picked["{$x}/{$y}"] ??= [$z, $x, $y];

                if (count($picked) >= $perZoom) {
                    break;
                }
            }

            array_push($tiles, ...array_values($picked));
        }

        return $tiles;
    }
}
