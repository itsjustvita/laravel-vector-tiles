<?php

namespace ItsJustVita\VectorTiles\Benchmark;

use ItsJustVita\VectorTiles\Support\WebMercator;

class TileSampler
{
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
