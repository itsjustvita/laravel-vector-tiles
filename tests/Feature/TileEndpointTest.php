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

it('applies default throttle middleware to tile routes', function () {
    $route = collect($this->app['router']->getRoutes())
        ->first(fn ($r) => $r->uri() === 'tiles/{layer}/{z}/{x}/{y}.mvt');

    expect($route)->not->toBeNull();
    expect($route->gatherMiddleware())->toContain('throttle:60,1');
});

it('applies default throttle middleware to geojson routes', function () {
    $route = collect($this->app['router']->getRoutes())
        ->first(fn ($r) => $r->uri() === 'geojson/{layer}');

    expect($route)->not->toBeNull();
    expect($route->gatherMiddleware())->toContain('throttle:60,1');
});

it('uses cache.ttl from config for Cache-Control max-age', function () {
    config()->set('vector-tiles.cache.ttl', 7200);

    $driver = Mockery::mock(\ItsJustVita\VectorTiles\Drivers\TileDriverInterface::class);
    $driver->shouldReceive('getTile')->andReturn('fake_tile');
    app()->instance(\ItsJustVita\VectorTiles\Drivers\TileDriverInterface::class, $driver);

    $this->get('/tiles/trassen/14/8532/5765.mvt')
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=7200, public');
});

it('uses cors.allowed_origins from config for CORS header', function () {
    config()->set('vector-tiles.cors.allowed_origins', 'https://maps.example.com');

    $driver = Mockery::mock(\ItsJustVita\VectorTiles\Drivers\TileDriverInterface::class);
    $driver->shouldReceive('getTile')->andReturn('fake_tile');
    app()->instance(\ItsJustVita\VectorTiles\Drivers\TileDriverInterface::class, $driver);

    $this->get('/tiles/trassen/14/8532/5765.mvt')
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', 'https://maps.example.com');
});

it('omits CORS header when cors.allowed_origins is null', function () {
    config()->set('vector-tiles.cors.allowed_origins', null);

    $driver = Mockery::mock(\ItsJustVita\VectorTiles\Drivers\TileDriverInterface::class);
    $driver->shouldReceive('getTile')->andReturn('fake_tile');
    app()->instance(\ItsJustVita\VectorTiles\Drivers\TileDriverInterface::class, $driver);

    $response = $this->get('/tiles/trassen/14/8532/5765.mvt')->assertOk();

    expect($response->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
});
