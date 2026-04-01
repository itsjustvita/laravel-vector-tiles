<?php

use ItsJustVita\VectorTiles\Facades\VectorTiles;

beforeEach(function () {
    VectorTiles::layer('trassen')
        ->from('trassen_table')
        ->properties(['id'])
        ->minZoom(10)
        ->maxZoom(18);
});

it('returns 404 for unknown layer', function () {
    $this->get('/tiles/unknown/14/8532/5765.mvt')
        ->assertNotFound();
});

it('returns 422 for z below min zoom', function () {
    $this->get('/tiles/trassen/5/8532/5765.mvt')
        ->assertUnprocessable();
});

it('returns 422 for z above max zoom', function () {
    $this->get('/tiles/trassen/20/8532/5765.mvt')
        ->assertUnprocessable();
});

it('returns 422 for x out of bounds', function () {
    $this->get('/tiles/trassen/14/99999/5765.mvt')
        ->assertUnprocessable();
});

it('returns 422 for y out of bounds', function () {
    $this->get('/tiles/trassen/14/8532/99999.mvt')
        ->assertUnprocessable();
});

it('returns 204 for empty tile', function () {
    $driver = Mockery::mock(\ItsJustVita\VectorTiles\Drivers\TileDriverInterface::class);
    $driver->shouldReceive('getTile')->andReturn(null);
    app()->instance(\ItsJustVita\VectorTiles\Drivers\TileDriverInterface::class, $driver);

    $this->get('/tiles/trassen/14/8532/5765.mvt')
        ->assertNoContent();
});

it('returns mvt tile with correct content type', function () {
    $driver = Mockery::mock(\ItsJustVita\VectorTiles\Drivers\TileDriverInterface::class);
    $driver->shouldReceive('getTile')->andReturn('fake_binary_mvt');
    app()->instance(\ItsJustVita\VectorTiles\Drivers\TileDriverInterface::class, $driver);

    $this->get('/tiles/trassen/14/8532/5765.mvt')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.mapbox-vector-tile');
});

it('serves cached tile on second request', function () {
    $driver = Mockery::mock(\ItsJustVita\VectorTiles\Drivers\TileDriverInterface::class);
    $driver->shouldReceive('getTile')->once()->andReturn('cached_tile');
    app()->instance(\ItsJustVita\VectorTiles\Drivers\TileDriverInterface::class, $driver);

    $this->get('/tiles/trassen/14/8532/5765.mvt')->assertOk();
    $this->get('/tiles/trassen/14/8532/5765.mvt')
        ->assertOk();
});
