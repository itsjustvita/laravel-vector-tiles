<?php

use ItsJustVita\VectorTiles\Facades\VectorTiles;

beforeEach(function () {
    VectorTiles::layer('trassen')
        ->from('trassen_table')
        ->properties(['id', 'typ', 'status'])
        ->minZoom(10)
        ->maxZoom(18);
});

it('returns 404 for unknown layer', function () {
    $this->getJson('/tiles/unknown.json')
        ->assertNotFound();
});

it('returns tilejson 3.0.0 document for known layer', function () {
    $response = $this->getJson('/tiles/trassen.json')->assertOk();

    $data = $response->json();
    expect($data['tilejson'])->toBe('3.0.0');
    expect($data['name'])->toBe('trassen');
    expect($data['scheme'])->toBe('xyz');
    expect($data['minzoom'])->toBe(10);
    expect($data['maxzoom'])->toBe(18);
});

it('includes an absolute tile url with z/x/y placeholders', function () {
    $data = $this->getJson('/tiles/trassen.json')->assertOk()->json();

    expect($data['tiles'])->toBeArray()->not->toBeEmpty();
    expect($data['tiles'][0])->toContain('/tiles/trassen/{z}/{x}/{y}.mvt');
});

it('includes default bounds and center', function () {
    $data = $this->getJson('/tiles/trassen.json')->assertOk()->json();

    expect($data['bounds'])->toBeArray()->toHaveCount(4);
    expect($data['bounds'][0])->toEqual(-180);
    expect($data['bounds'][2])->toEqual(180);
    expect($data['center'])->toBeArray()->toHaveCount(3);
});

it('includes vector_layers with layer properties as fields', function () {
    $data = $this->getJson('/tiles/trassen.json')->assertOk()->json();

    expect($data['vector_layers'])->toBeArray()->toHaveCount(1);

    $vectorLayer = $data['vector_layers'][0];
    expect($vectorLayer['id'])->toBe('trassen');
    expect($vectorLayer['minzoom'])->toBe(10);
    expect($vectorLayer['maxzoom'])->toBe(18);
    expect($vectorLayer['fields'])->toHaveKey('id');
    expect($vectorLayer['fields'])->toHaveKey('typ');
    expect($vectorLayer['fields'])->toHaveKey('status');
});

it('serializes empty fields as a json object, not array', function () {
    VectorTiles::layer('empty')->from('empty_table');

    $response = $this->getJson('/tiles/empty.json')->assertOk();

    expect($response->content())->toContain('"fields":{}');
});

it('does not run ValidateTileRequest for tilejson endpoint', function () {
    // ValidateTileRequest checks z/x/y bounds — it should not apply here
    // since the tilejson URL has no z/x/y at all.
    $this->getJson('/tiles/trassen.json')->assertOk();
});

it('applies throttle middleware from config to tilejson route', function () {
    $route = collect($this->app['router']->getRoutes())
        ->first(fn ($r) => $r->uri() === 'tiles/{layer}.json');

    expect($route)->not->toBeNull();
    expect($route->gatherMiddleware())->toContain('throttle:60,1');
});
