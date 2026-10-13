<?php

use Illuminate\Support\Facades\DB;
use ItsJustVita\VectorTiles\Query\BboxFilter;

it('parses a bbox into floats', function () {
    expect(BboxFilter::parse(' 9.0, 48 ,9.5,48.5'))->toBe([9.0, 48.0, 9.5, 48.5]);
});

it('rejects invalid bboxes with a validation message', function (string $bbox, string $message) {
    expect(fn () => BboxFilter::parse($bbox))->toThrow(InvalidArgumentException::class, $message);
})->with([
    'three coordinates' => ['1,2,3', 'exactly 4 comma-separated coordinates'],
    'not numeric' => ['a,2,3,4', 'must be numeric'],
    'longitude out of range' => ['-200,48,9.5,48.5', 'longitudes must lie within [-180, 180]'],
    'west after east' => ['9.5,48,9,48.5', 'longitudes must lie within [-180, 180]'],
    'latitude out of range' => ['9,-95,9.5,48.5', 'latitudes must lie within [-90, 90]'],
    'south after north' => ['9,48.5,9.5,48', 'latitudes must lie within [-90, 90]'],
]);

it('clamps both latitudes for web mercator layers', function () {
    $query = DB::table('merc_table');

    BboxFilter::apply($query, 'geom', 3857, [0.0, 90.0, 10.0, 90.0]);

    expect($query->getBindings())->toEqualWithDelta([0.0, 85.0511287798066, 10.0, 85.0511287798066], 1e-9);
});

it('keeps the latitudes for other srids', function () {
    $query = DB::table('wgs_table');

    BboxFilter::apply($query, 'geom', 4326, [0.0, 89.0, 10.0, 90.0]);

    expect($query->getBindings())->toBe([0.0, 89.0, 10.0, 90.0]);
});
