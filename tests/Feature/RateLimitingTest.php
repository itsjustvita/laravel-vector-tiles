<?php

use Illuminate\Support\Facades\Route;
use ItsJustVita\VectorTiles\Facades\VectorTiles;

beforeEach(function () {
    VectorTiles::fake();
    VectorTiles::layer('buildings')->from('buildings')->maxZoom(18);
});

it('does not throttle tiles and TileJSON by default', function () {
    foreach (range(1, 61) as $i) {
        $this->get('/tiles/buildings/14/8532/5765.mvt')->assertOk();
        $this->get('/tiles/buildings.json')->assertOk();
    }
});

it('throttles GeoJSON to 60 requests per minute by default', function () {
    foreach (range(1, 60) as $i) {
        $this->getJson('/geojson/buildings')->assertUnprocessable();
    }

    $this->getJson('/geojson/buildings')->assertTooManyRequests();
});

it('does not share its request count with throttled routes of the application', function () {
    Route::get('/app-route', fn () => 'ok')->middleware('throttle:60,1');

    foreach (range(1, 60) as $i) {
        $this->get('/app-route')->assertOk();
    }

    $this->getJson('/geojson/buildings')->assertUnprocessable();
});

it('throttles tiles and TileJSON with the throttle setting', function () {
    config()->set('vector-tiles.throttle', '2,1');
    reloadTileRoutes();

    $this->get('/tiles/buildings/14/8532/5765.mvt')->assertOk();
    $this->get('/tiles/buildings.json')->assertOk();
    $this->get('/tiles/buildings/14/8532/5765.mvt')->assertTooManyRequests();
});

it('keeps separate request counts for tiles and GeoJSON', function () {
    config()->set('vector-tiles.throttle', '2,1');
    config()->set('vector-tiles.geojson.throttle', '2,1');
    reloadTileRoutes();

    $this->get('/tiles/buildings/14/8532/5765.mvt')->assertOk();
    $this->get('/tiles/buildings/14/8532/5765.mvt')->assertOk();

    $this->getJson('/geojson/buildings')->assertUnprocessable();
});

it('applies the throttle setting to GeoJSON when geojson.throttle is missing', function () {
    config()->set('vector-tiles.throttle', '2,1');
    config()->set('vector-tiles.geojson', ['prefix' => 'geojson']);
    reloadTileRoutes();

    $this->getJson('/geojson/buildings')->assertUnprocessable();
    $this->getJson('/geojson/buildings')->assertUnprocessable();
    $this->getJson('/geojson/buildings')->assertTooManyRequests();
});

it('does not throttle GeoJSON when geojson.throttle is null', function () {
    config()->set('vector-tiles.throttle', '2,1');
    config()->set('vector-tiles.geojson.throttle', null);
    reloadTileRoutes();

    foreach (range(1, 5) as $i) {
        $this->getJson('/geojson/buildings')->assertUnprocessable();
    }
});
