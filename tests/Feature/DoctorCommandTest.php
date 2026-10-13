<?php

use Illuminate\Support\Facades\Artisan;
use ItsJustVita\VectorTiles\Facades\VectorTiles;

it('fails when postgis is not available on the layer connection', function () {
    VectorTiles::layer('trassen')->from('trassen_table');

    $exitCode = Artisan::call('vector-tiles:doctor');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())
        ->toContain('✗ PostGIS is not available on connection '.config('database.default'))
        ->toContain('CREATE EXTENSION postgis;');
});

it('fails for an unknown layer', function () {
    expect(Artisan::call('vector-tiles:doctor', ['layer' => 'unknown']))->toBe(1)
        ->and(Artisan::output())->toContain("Layer 'unknown' not found.");
});

it('reports when no layers are defined', function () {
    expect(Artisan::call('vector-tiles:doctor'))->toBe(0)
        ->and(Artisan::output())->toContain('No layers defined.');
});
