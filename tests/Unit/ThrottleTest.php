<?php

use ItsJustVita\VectorTiles\Http\Throttle;

it('returns no middleware when throttling is off', function (mixed $value) {
    expect(Throttle::middleware($value, 'vector-tiles'))->toBe([]);
})->with([null, false, '']);

it('keeps request counts under its own prefix', function (mixed $value, string $expected) {
    expect(Throttle::middleware($value, 'vector-tiles'))->toBe([$expected]);
})->with([
    ['60,1', 'throttle:60,1,vector-tiles'],
    ['600, 5', 'throttle:600,5,vector-tiles'],
    ['60', 'throttle:60,1,vector-tiles'],
    [600, 'throttle:600,1,vector-tiles'],
]);

it('passes named limiters and explicit prefixes through', function (string $value) {
    expect(Throttle::middleware($value, 'vector-tiles'))->toBe(["throttle:{$value}"]);
})->with(['tiles', '60,1,custom']);
