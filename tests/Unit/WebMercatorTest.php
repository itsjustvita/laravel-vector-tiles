<?php

use ItsJustVita\VectorTiles\Support\WebMercator;

it('returns the whole world for tile 0/0/0', function () {
    $m = WebMercator::MAX_EXTENT;

    expect(WebMercator::tileBounds(0, 0, 0))->toEqualWithDelta([-$m, -$m, $m, $m], 1e-6);
});

it('returns the north-west quadrant for tile 1/0/0', function () {
    $m = WebMercator::MAX_EXTENT;

    expect(WebMercator::tileBounds(1, 0, 0))->toEqualWithDelta([-$m, 0.0, 0.0, $m], 1e-6);
});

it('expands interior tiles by the margin on every side', function () {
    $m = WebMercator::MAX_EXTENT;

    // Tile 2/1/1 spans x [-m/2, 0] and y [0, m/2]; a margin of 0.0625 adds m/32 per side.
    expect(WebMercator::tileBounds(2, 1, 1, 0.0625))
        ->toEqualWithDelta([-$m / 2 - $m / 32, -$m / 32, $m / 32, $m / 2 + $m / 32], 1e-6);
});

it('clamps the margin at the edges of the world', function () {
    $m = WebMercator::MAX_EXTENT;

    // Tile 1/0/0 touches the antimeridian and the north edge, so the margin only grows inwards.
    expect(WebMercator::tileBounds(1, 0, 0, 0.0625))
        ->toEqualWithDelta([-$m, -$m / 16, $m / 16, $m], 1e-6);
});
