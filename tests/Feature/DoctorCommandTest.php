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

it('reports a connection that cannot be reached instead of missing postgis', function () {
    config()->set('database.connections.vt_down', [
        'driver' => 'pgsql',
        'host' => '127.0.0.1',
        'port' => '1',
        'database' => 'none',
        'username' => 'none',
        'password' => 'none',
        'charset' => 'utf8',
        'prefix' => '',
        'search_path' => 'public',
        'sslmode' => 'disable',
    ]);
    VectorTiles::layer('down')->from('trassen_table')->connection('vt_down');

    $exitCode = Artisan::call('vector-tiles:doctor');

    expect($exitCode)->toBe(1)
        ->and(Artisan::output())
        ->toContain('✗ Cannot connect to connection vt_down')
        ->not->toContain('CREATE EXTENSION');
});
