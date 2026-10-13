<?php

use ItsJustVita\VectorTiles\Benchmark\TileSampler;

it('picks distinct tiles per zoom level in the order of the points', function () {
    $points = [[16.37, 48.21], [16.38, 48.21], [2.35, 48.86]];

    expect(TileSampler::tiles($points, [8, 10], 5))->toBe([
        [8, 139, 88], [8, 129, 88],
        [10, 558, 355], [10, 518, 352],
    ]);
});

it('stops at the requested number of tiles per zoom level', function () {
    $points = [[16.37, 48.21], [2.35, 48.86]];

    expect(TileSampler::tiles($points, [10], 1))->toBe([[10, 558, 355]]);
});
