<?php

use ItsJustVita\VectorTiles\Facades\VectorTiles;

it('generates source config for a single layer', function () {
    VectorTiles::layer('trassen')
        ->from('trassen_table')
        ->minZoom(10)
        ->maxZoom(18);

    $result = VectorTiles::maplibreSource('trassen');

    expect($result)->toBe([
        'type' => 'vector',
        'tiles' => [url('tiles/trassen/{z}/{x}/{y}.mvt')],
        'minzoom' => 10,
        'maxzoom' => 18,
    ]);
});

it('generates source config for all layers', function () {
    VectorTiles::layer('trassen')->from('trassen_table')->minZoom(10)->maxZoom(18);
    VectorTiles::layer('hausanschluesse')->from('ha_table')->minZoom(12)->maxZoom(20);

    $result = VectorTiles::maplibreSources();

    expect($result)->toHaveCount(2)
        ->and($result)->toHaveKeys(['trassen', 'hausanschluesse'])
        ->and($result['trassen']['type'])->toBe('vector')
        ->and($result['hausanschluesse']['minzoom'])->toBe(12);
});

it('returns null for unknown layer', function () {
    $result = VectorTiles::maplibreSource('nope');

    expect($result)->toBeNull();
});

it('uses configured prefix in tile URL', function () {
    config()->set('vector-tiles.prefix', 'api/tiles');

    VectorTiles::layer('trassen')->from('trassen_table');

    $result = VectorTiles::maplibreSource('trassen');

    expect($result['tiles'][0])->toContain('api/tiles/trassen/{z}/{x}/{y}.mvt');
});
