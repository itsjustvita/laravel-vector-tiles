<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use ItsJustVita\VectorTiles\Facades\VectorTiles;

it('returns features of a 4326 layer inside the bbox', function () {
    $this->createPoints('vt_points', [[16.37, 48.21], [2.35, 48.86]]);
    VectorTiles::layer('points')->from('vt_points')->properties(['id', 'name']);

    $response = $this->getJson('/geojson/points?bbox=16.0,48.0,17.0,48.5')->assertOk();

    expect($response->json('features'))->toHaveCount(1)
        ->and($response->json('features.0.properties.name'))->toBe('p0')
        ->and($response->json('features.0.geometry.coordinates.0'))->toEqualWithDelta(16.37, 1e-6);
});

it('returns features of a layer stored in a regional srid', function () {
    $this->createPoints('vt_utm', [[9.0, 48.75]], 25832);
    VectorTiles::layer('utm')->from('vt_utm')->srid(25832)->properties(['name']);

    $response = $this->getJson('/geojson/utm?bbox=8.5,48.5,9.5,49.0')->assertOk();

    expect($response->json('features'))->toHaveCount(1)
        ->and($response->json('features.0.geometry.coordinates.0'))->toEqualWithDelta(9.0, 1e-6)
        ->and($response->json('features.0.geometry.coordinates.1'))->toEqualWithDelta(48.75, 1e-6);
});

it('does not fail for a regional-srid layer when the bbox lies outside its projection area', function () {
    $this->createPoints('vt_utm', [[9.0, 48.75]], 25832);
    VectorTiles::layer('utm')->from('vt_utm')->srid(25832);

    $this->getJson('/geojson/utm?bbox=100,30,110,40')
        ->assertOk()
        ->assertJsonCount(0, 'features');
});

it('accepts a full-latitude bbox for web mercator layers', function () {
    $this->createPoints('vt_merc', [[0.0, 10.0]], 3857);
    VectorTiles::layer('merc')->from('vt_merc')->srid(3857);

    $this->getJson('/geojson/merc?bbox=-180,-90,180,90')
        ->assertOk()
        ->assertJsonCount(1, 'features');
});

it('queries eloquent sources on the model connection', function () {
    DB::connection('pgsql_gis')->statement('CREATE SCHEMA IF NOT EXISTS vt_gis');
    $this->createPoints('vt_remote', [[16.37, 48.21]], connection: 'pgsql_gis');

    $model = new class extends Model
    {
        protected $connection = 'pgsql_gis';

        protected $table = 'vt_remote';
    };
    VectorTiles::layer('remote')->from($model->newQuery());

    $this->getJson('/geojson/remote?bbox=16.0,48.0,17.0,48.5')
        ->assertOk()
        ->assertJsonCount(1, 'features');
});
