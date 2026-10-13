<?php

use ItsJustVita\VectorTiles\Benchmark\Summary;

function benchmarkMeasurements(): array
{
    return [
        ['tile' => '8/1/1', 'zoom' => 8, 'ms' => 30.0, 'bytes' => 3000, 'gzip_bytes' => 1000],
        ['tile' => '12/1/1', 'zoom' => 12, 'ms' => 5.0, 'bytes' => 0, 'gzip_bytes' => 0],
        ['tile' => '8/2/1', 'zoom' => 8, 'ms' => 10.0, 'bytes' => 1000, 'gzip_bytes' => 500],
        ['tile' => '12/2/1', 'zoom' => 12, 'ms' => 7.0, 'bytes' => 2000, 'gzip_bytes' => 800],
    ];
}

it('computes nearest-rank percentiles', function () {
    $twenty = array_map('floatval', range(20, 1));

    expect(Summary::percentile([5.0], 50))->toBe(5.0)
        ->and(Summary::percentile([5.0], 95))->toBe(5.0)
        ->and(Summary::percentile([2.0, 1.0], 50))->toBe(1.0)
        ->and(Summary::percentile([2.0, 1.0], 95))->toBe(2.0)
        ->and(Summary::percentile($twenty, 50))->toBe(10.0)
        ->and(Summary::percentile($twenty, 95))->toBe(19.0);
});

it('summarizes measurements per zoom level and counts empty tiles', function () {
    expect(Summary::byZoom(benchmarkMeasurements()))->toBe([
        [
            'zoom' => 8, 'tiles' => 2, 'empty' => 0,
            'ms' => ['p50' => 10.0, 'p95' => 30.0, 'max' => 30.0],
            'bytes' => ['avg' => 2000, 'max' => 3000, 'gzip_avg' => 750],
        ],
        [
            'zoom' => 12, 'tiles' => 2, 'empty' => 1,
            'ms' => ['p50' => 5.0, 'p95' => 7.0, 'max' => 7.0],
            'bytes' => ['avg' => 1000, 'max' => 2000, 'gzip_avg' => 400],
        ],
    ]);
});

it('lists the slowest tiles first', function () {
    expect(array_column(Summary::slowest(benchmarkMeasurements(), 2), 'tile'))->toBe(['8/1/1', '8/2/1']);
});
