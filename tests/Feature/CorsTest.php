<?php

use ItsJustVita\VectorTiles\Facades\VectorTiles;
use ItsJustVita\VectorTiles\Tests\Fixtures\SetsOriginMiddleware;

beforeEach(function () {
    VectorTiles::fake();
    VectorTiles::layer('buildings')->from('buildings')->minZoom(10)->maxZoom(18);
    config()->set('vector-tiles.cors.allowed_origins', 'https://maps.example.com');
});

it('sends the configured origin on TileJSON responses', function () {
    $this->getJson('/tiles/buildings.json')
        ->assertOk()
        ->assertHeader('Access-Control-Allow-Origin', 'https://maps.example.com');
});

it('sends the configured origin on GeoJSON responses', function () {
    $this->getJson('/geojson/buildings')
        ->assertUnprocessable()
        ->assertHeader('Access-Control-Allow-Origin', 'https://maps.example.com');
});

it('sends the configured origin on tile error responses', function (string $uri, int $status) {
    $this->get($uri)
        ->assertStatus($status)
        ->assertHeader('Access-Control-Allow-Origin', 'https://maps.example.com');
})->with([
    'unknown layer' => ['/tiles/unknown/14/8532/5765.mvt', 404],
    'zoom out of range' => ['/tiles/buildings/5/10/10.mvt', 422],
    'unknown TileJSON layer' => ['/tiles/unknown.json', 404],
]);

it('sends the configured origin on throttled responses', function () {
    config()->set('vector-tiles.throttle', '1,1');
    reloadTileRoutes();

    $this->get('/tiles/buildings/14/8532/5765.mvt')->assertOk();
    $this->get('/tiles/buildings/14/8532/5765.mvt')
        ->assertTooManyRequests()
        ->assertHeader('Access-Control-Allow-Origin', 'https://maps.example.com');
});

it('omits the header on all endpoints when allowed_origins is null', function (string $uri) {
    config()->set('vector-tiles.cors.allowed_origins', null);

    expect($this->getJson($uri)->headers->has('Access-Control-Allow-Origin'))->toBeFalse();
})->with([
    '/tiles/buildings/14/8532/5765.mvt',
    '/tiles/buildings.json',
    '/geojson/buildings',
]);

it('keeps an origin that layer middleware has set', function () {
    VectorTiles::layer('app')->from('buildings')->middleware([SetsOriginMiddleware::class]);

    $this->get('/tiles/app/14/8532/5765.mvt')->assertHeader('Access-Control-Allow-Origin', 'https://app.example.com');
    $this->getJson('/tiles/app.json')->assertHeader('Access-Control-Allow-Origin', 'https://app.example.com');
});

it('sends the configured origin when global middleware rejects the request', function () {
    config()->set('vector-tiles.middleware', ['auth']);
    reloadTileRoutes();

    $this->getJson('/tiles/buildings/14/8532/5765.mvt')
        ->assertUnauthorized()
        ->assertHeader('Access-Control-Allow-Origin', 'https://maps.example.com');
    $this->getJson('/geojson/buildings')
        ->assertUnauthorized()
        ->assertHeader('Access-Control-Allow-Origin', 'https://maps.example.com');
});
