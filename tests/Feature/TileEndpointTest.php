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

it('applies no throttle middleware to tile routes by default', function () {
    $route = collect($this->app['router']->getRoutes())
        ->first(fn ($r) => $r->uri() === 'tiles/{layer}/{z}/{x}/{y}.mvt');

    expect($route)->not->toBeNull();
    expect(collect($route->gatherMiddleware())->filter(fn ($m) => str_starts_with($m, 'throttle')))->toBeEmpty();
});

it('applies default throttle middleware to geojson routes', function () {
    $route = collect($this->app['router']->getRoutes())
        ->first(fn ($r) => $r->uri() === 'geojson/{layer}');

    expect($route)->not->toBeNull();
    expect($route->gatherMiddleware())->toContain('throttle:60,1,vector-tiles-geojson');
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

it('returns 204 with cache and cors headers for an empty tile', function () {
    config()->set('vector-tiles.cors.allowed_origins', 'https://maps.example.com');

    $driver = Mockery::mock(\ItsJustVita\VectorTiles\Drivers\TileDriverInterface::class);
    $driver->shouldReceive('getTile')->andReturn('');
    app()->instance(\ItsJustVita\VectorTiles\Drivers\TileDriverInterface::class, $driver);

    $this->get('/tiles/trassen/14/8532/5765.mvt')
        ->assertNoContent()
        ->assertHeader('Cache-Control', 'max-age=3600, public')
        ->assertHeader('Access-Control-Allow-Origin', 'https://maps.example.com');
});

it('caches empty tiles', function () {
    $driver = Mockery::mock(\ItsJustVita\VectorTiles\Drivers\TileDriverInterface::class);
    $driver->shouldReceive('getTile')->once()->andReturn('');
    app()->instance(\ItsJustVita\VectorTiles\Drivers\TileDriverInterface::class, $driver);

    $this->get('/tiles/trassen/14/8532/5765.mvt')->assertNoContent();
    $this->get('/tiles/trassen/14/8532/5765.mvt')->assertNoContent();
});

it('does not set a content encoding so web servers can compress tiles', function () {
    $driver = Mockery::mock(\ItsJustVita\VectorTiles\Drivers\TileDriverInterface::class);
    $driver->shouldReceive('getTile')->andReturn('fake_tile');
    app()->instance(\ItsJustVita\VectorTiles\Drivers\TileDriverInterface::class, $driver);

    $response = $this->get('/tiles/trassen/14/8532/5765.mvt')->assertOk();

    expect($response->headers->has('Content-Encoding'))->toBeFalse();
});

it('marks tiles private when the layer has middleware', function () {
    VectorTiles::layer('protected')
        ->from('trassen_table')
        ->middleware([\ItsJustVita\VectorTiles\Tests\Fixtures\PassThroughMiddleware::class]);

    $driver = Mockery::mock(\ItsJustVita\VectorTiles\Drivers\TileDriverInterface::class);
    $driver->shouldReceive('getTile')->andReturn('fake_tile');
    app()->instance(\ItsJustVita\VectorTiles\Drivers\TileDriverInterface::class, $driver);

    $this->get('/tiles/protected/14/8532/5765.mvt')
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=3600, private');
});

it('marks tiles private when the layer has a scope', function () {
    VectorTiles::layer('scoped')
        ->from('trassen_table')
        ->scope(fn ($query, $user) => $query);

    $driver = Mockery::mock(\ItsJustVita\VectorTiles\Drivers\TileDriverInterface::class);
    $driver->shouldReceive('getTile')->andReturn('fake_tile');
    app()->instance(\ItsJustVita\VectorTiles\Drivers\TileDriverInterface::class, $driver);

    $this->get('/tiles/scoped/14/8532/5765.mvt')
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=3600, private');
});

it('marks tiles private when global middleware is configured', function () {
    config()->set('vector-tiles.middleware', [\ItsJustVita\VectorTiles\Tests\Fixtures\PassThroughMiddleware::class]);

    $driver = Mockery::mock(\ItsJustVita\VectorTiles\Drivers\TileDriverInterface::class);
    $driver->shouldReceive('getTile')->andReturn('fake_tile');
    app()->instance(\ItsJustVita\VectorTiles\Drivers\TileDriverInterface::class, $driver);

    $this->get('/tiles/trassen/14/8532/5765.mvt')
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=3600, private');
});

it('still serves tiles and empty tiles when caching is disabled', function () {
    config()->set('vector-tiles.cache.enabled', false);

    $driver = Mockery::mock(\ItsJustVita\VectorTiles\Drivers\TileDriverInterface::class);
    $driver->shouldReceive('getTile')->twice()->andReturn('fake_tile', '');
    app()->instance(\ItsJustVita\VectorTiles\Drivers\TileDriverInterface::class, $driver);

    $this->get('/tiles/trassen/14/8532/5765.mvt')->assertOk();
    $this->get('/tiles/trassen/14/8532/5765.mvt')->assertNoContent();
});

it('never serves one user a tile cached for another user', function () {
    VectorTiles::layer('tenant')
        ->from('trassen_table')
        ->middleware([\ItsJustVita\VectorTiles\Tests\Fixtures\PassThroughMiddleware::class]);

    $driver = Mockery::mock(\ItsJustVita\VectorTiles\Drivers\TileDriverInterface::class);
    $driver->shouldReceive('getTile')->andReturnUsing(
        fn ($layer, $z, $x, $y, $user) => 'tile_for_'.$user->getAuthIdentifier(),
    );
    app()->instance(\ItsJustVita\VectorTiles\Drivers\TileDriverInterface::class, $driver);

    $this->actingAs(new \Illuminate\Auth\GenericUser(['id' => 1]))
        ->get('/tiles/tenant/14/8532/5765.mvt')
        ->assertContent('tile_for_1');

    $this->actingAs(new \Illuminate\Auth\GenericUser(['id' => 2]))
        ->get('/tiles/tenant/14/8532/5765.mvt')
        ->assertContent('tile_for_2');
});

it('resolves middleware aliases with parameters for layer middleware', function () {
    app('router')->aliasMiddleware('vt.reject', \ItsJustVita\VectorTiles\Tests\Fixtures\RejectingMiddleware::class);
    VectorTiles::layer('aliased')->from('trassen_table')->middleware(['vt.reject:extra']);

    $this->get('/tiles/aliased/14/8532/5765.mvt')->assertUnauthorized();
});

it('supports the auth middleware alias on layers', function () {
    VectorTiles::layer('private')->from('trassen_table')->middleware(['auth']);

    $this->getJson('/tiles/private/14/8532/5765.mvt')->assertUnauthorized();
});

it('renders again after a flush that happened while the tile was rendering', function () {
    $driver = Mockery::mock(\ItsJustVita\VectorTiles\Drivers\TileDriverInterface::class);
    $driver->shouldReceive('getTile')->twice()->andReturnUsing(
        function () {
            app(\ItsJustVita\VectorTiles\Cache\TileCache::class)->flushLayer('trassen');

            return 'stale_tile';
        },
        fn () => 'fresh_tile',
    );
    app()->instance(\ItsJustVita\VectorTiles\Drivers\TileDriverInterface::class, $driver);

    $this->get('/tiles/trassen/14/8532/5765.mvt')->assertContent('stale_tile');
    $this->get('/tiles/trassen/14/8532/5765.mvt')->assertContent('fresh_tile');
});
