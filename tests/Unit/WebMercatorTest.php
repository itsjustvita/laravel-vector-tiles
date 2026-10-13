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

it('returns the width of a tile in meters', function () {
    expect(WebMercator::tileWidth(0))->toEqualWithDelta(2 * WebMercator::MAX_EXTENT, 1e-6)
        ->and(WebMercator::tileWidth(8))->toEqualWithDelta(156543.03392804097, 1e-6);
});

it('finds the tile containing a position', function () {
    expect(WebMercator::tileAt(16.37, 48.21, 8))->toBe([139, 88])
        ->and(WebMercator::tileAt(16.37, 48.21, 12))->toBe([2234, 1420])
        ->and(WebMercator::tileAt(2.35, 48.86, 10))->toBe([518, 352]);
});

it('clamps positions at the poles and the antimeridian to the edge tiles', function () {
    expect(WebMercator::tileAt(0.0, 90.0, 8))->toBe([128, 0])
        ->and(WebMercator::tileAt(-180.0, -90.0, 8))->toBe([0, 255])
        ->and(WebMercator::tileAt(180.0, 0.0, 8))->toBe([255, 128])
        ->and(WebMercator::tileAt(0.0, 0.0, 0))->toBe([0, 0]);
});
