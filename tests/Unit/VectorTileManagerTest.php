<?php

use ItsJustVita\VectorTiles\Layer;
use ItsJustVita\VectorTiles\VectorTileManager;

it('registers a layer via fluent API', function () {
    $manager = new VectorTileManager(app());

    $layer = $manager->layer('trassen');

    expect($layer)->toBeInstanceOf(Layer::class)
        ->and($layer->name)->toBe('trassen');
});

it('retrieves a registered layer', function () {
    $manager = new VectorTileManager(app());
    $manager->layer('trassen')->from('trassen_table');

    $layer = $manager->getLayer('trassen');

    expect($layer)->toBeInstanceOf(Layer::class)
        ->and($layer->source)->toBe('trassen_table');
});

it('checks if a layer exists', function () {
    $manager = new VectorTileManager(app());

    expect($manager->hasLayer('trassen'))->toBeFalse();

    $manager->layer('trassen');

    expect($manager->hasLayer('trassen'))->toBeTrue();
});

it('returns all registered layers', function () {
    $manager = new VectorTileManager(app());
    $manager->layer('trassen');
    $manager->layer('hausanschluesse');

    $layers = $manager->getLayers();

    expect($layers)->toHaveCount(2)
        ->and(array_keys($layers))->toBe(['trassen', 'hausanschluesse']);
});

it('loads layers from config', function () {
    config()->set('vector-tiles.layers', [
        'trassen' => [
            'table' => 'trassen_table',
            'geometry' => 'geom',
            'properties' => ['id', 'typ'],
            'min_zoom' => 10,
            'max_zoom' => 18,
        ],
    ]);

    $manager = new VectorTileManager(app());
    $manager->loadConfigLayers();

    $layer = $manager->getLayer('trassen');

    expect($layer->source)->toBe('trassen_table')
        ->and($layer->properties)->toBe(['id', 'typ'])
        ->and($layer->minZoom)->toBe(10);
});

it('fluent layers override config layers', function () {
    config()->set('vector-tiles.layers', [
        'trassen' => [
            'table' => 'trassen_table',
            'geometry' => 'geom',
            'properties' => ['id'],
            'min_zoom' => 0,
            'max_zoom' => 22,
        ],
    ]);

    $manager = new VectorTileManager(app());
    $manager->loadConfigLayers();
    $manager->layer('trassen')->from('trassen_v2')->properties(['id', 'typ', 'status']);

    $layer = $manager->getLayer('trassen');

    expect($layer->source)->toBe('trassen_v2')
        ->and($layer->properties)->toBe(['id', 'typ', 'status']);
});

it('returns null for unknown layer', function () {
    $manager = new VectorTileManager(app());

    expect($manager->getLayer('nope'))->toBeNull();
});
