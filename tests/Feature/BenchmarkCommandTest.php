<?php

use Illuminate\Support\Facades\Artisan;
use ItsJustVita\VectorTiles\Facades\VectorTiles;

beforeEach(function () {
    VectorTiles::layer('points')->from('vt_points')->maxZoom(14);
});

it('rejects invalid input with exit code 1', function (array $parameters, string $message) {
    expect(Artisan::call('vector-tiles:benchmark', $parameters))->toBe(1)
        ->and(Artisan::output())->toContain($message);
})->with([
    'unknown layer' => [['layer' => 'unknown'], "Layer 'unknown' not found."],
    'zoom not a number' => [['layer' => 'points', '--zoom' => 'abc'], 'Invalid --zoom value abc'],
    'zoom range reversed' => [['layer' => 'points', '--zoom' => '12-8'], 'Invalid --zoom value 12-8'],
    'zoom outside the layer' => [['layer' => 'points', '--zoom' => '15-16'], "No zoom level within the layer's range 0-14."],
    'tiles below one' => [['layer' => 'points', '--tiles' => '0'], '--tiles must be a positive integer.'],
    'invalid bbox' => [['layer' => 'points', '--bbox' => '1,2,3'], 'The bbox must contain exactly 4 comma-separated coordinates'],
    'tiles-from with zoom' => [['layer' => 'points', '--tiles-from' => 'tiles.txt', '--zoom' => '8'], '--tiles-from cannot be combined with --zoom, --tiles or --bbox.'],
    'missing tile list' => [['layer' => 'points', '--tiles-from' => '/nonexistent/vt-tiles.txt'], 'Tile list /nonexistent/vt-tiles.txt cannot be read.'],
]);
