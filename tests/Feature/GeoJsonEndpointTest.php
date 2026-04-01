<?php

use ItsJustVita\VectorTiles\Facades\VectorTiles;

beforeEach(function () {
    VectorTiles::layer('trassen')
        ->from('trassen_table')
        ->properties(['id', 'typ'])
        ->minZoom(10)
        ->maxZoom(18);
});

it('returns 422 when bbox is missing', function () {
    $this->getJson('/geojson/trassen')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['bbox']);
});

it('returns 422 for invalid bbox format', function () {
    $this->getJson('/geojson/trassen?bbox=invalid')
        ->assertUnprocessable();
});

it('returns 422 for bbox with wrong number of coordinates', function () {
    $this->getJson('/geojson/trassen?bbox=1,2,3')
        ->assertUnprocessable();
});

it('returns 404 for unknown layer', function () {
    $this->getJson('/geojson/unknown?bbox=9.0,48.0,9.5,48.5')
        ->assertNotFound();
});

it('returns geojson response with correct structure', function () {
    $connection = Mockery::mock(\Illuminate\Database\Connection::class);
    $connection->shouldReceive('select')->andReturn([]);

    \Illuminate\Support\Facades\DB::shouldReceive('connection')
        ->with(null)
        ->andReturn($connection);

    $this->getJson('/geojson/trassen?bbox=9.0,48.0,9.5,48.5')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/geo+json')
        ->assertJsonStructure(['type', 'features']);
});

it('caps limit at max_limit from config', function () {
    config()->set('vector-tiles.geojson.max_limit', 100);

    $connection = Mockery::mock(\Illuminate\Database\Connection::class);
    $connection->shouldReceive('select')
        ->withArgs(function (string $sql, array $bindings) {
            // Last binding is the limit, should be capped at 100
            expect(end($bindings))->toBe(100);
            return true;
        })
        ->andReturn([]);

    \Illuminate\Support\Facades\DB::shouldReceive('connection')
        ->with(null)
        ->andReturn($connection);

    $this->getJson('/geojson/trassen?bbox=9.0,48.0,9.5,48.5&limit=9999')
        ->assertOk();
});
