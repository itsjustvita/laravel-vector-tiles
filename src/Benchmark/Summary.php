<?php

namespace ItsJustVita\VectorTiles\Benchmark;

/**
 * Aggregates benchmark measurements of the form
 * array{tile: string, zoom: int, ms: float, bytes: int, gzip_bytes: int}.
 */
final class Summary
{
    /**
     * @param  list<array{tile: string, zoom: int, ms: float, bytes: int, gzip_bytes: int}>  $measurements
     * @return list<array{zoom: int, tiles: int, empty: int, ms: array{p50: float, p95: float, max: float}, bytes: array{avg: int, max: int, gzip_avg: int}}>
     */
    public static function byZoom(array $measurements): array
    {
        $groups = [];

        foreach ($measurements as $measurement) {
            $groups[$measurement['zoom']][] = $measurement;
        }

        ksort($groups);

        $rows = [];

        foreach ($groups as $zoom => $group) {
            $ms = array_column($group, 'ms');
            $bytes = array_column($group, 'bytes');

            $rows[] = [
                'zoom' => $zoom,
                'tiles' => count($group),
                'empty' => count(array_filter($bytes, fn (int $size) => $size === 0)),
                'ms' => [
                    'p50' => self::percentile($ms, 50),
                    'p95' => self::percentile($ms, 95),
                    'max' => round(max($ms), 1),
                ],
                'bytes' => [
                    'avg' => (int) round(array_sum($bytes) / count($bytes)),
                    'max' => max($bytes),
                    'gzip_avg' => (int) round(array_sum(array_column($group, 'gzip_bytes')) / count($group)),
                ],
            ];
        }

        return $rows;
    }

    /**
     * Nearest-rank percentile.
     *
     * @param  non-empty-list<float>  $values
     */
    public static function percentile(array $values, int $percent): float
    {
        sort($values);
        $rank = (int) ceil($percent / 100 * count($values));

        return round($values[max($rank, 1) - 1], 1);
    }

    /**
     * @param  list<array{tile: string, zoom: int, ms: float, bytes: int, gzip_bytes: int}>  $measurements
     * @return list<array{tile: string, zoom: int, ms: float, bytes: int, gzip_bytes: int}>
     */
    public static function slowest(array $measurements, int $limit = 5): array
    {
        usort($measurements, fn (array $a, array $b) => $b['ms'] <=> $a['ms']);

        return array_slice($measurements, 0, $limit);
    }
}
