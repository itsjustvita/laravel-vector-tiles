<?php

use Illuminate\Support\Facades\Route;
use ItsJustVita\VectorTiles\Http\Controllers\GeoJsonController;
use ItsJustVita\VectorTiles\Http\Controllers\TileController;
use ItsJustVita\VectorTiles\Http\Controllers\TileJsonController;
use ItsJustVita\VectorTiles\Http\Middleware\ValidateTileRequest;
use ItsJustVita\VectorTiles\Http\Throttle;

$globalMiddleware = config('vector-tiles.middleware', []);

Route::prefix(config('vector-tiles.prefix', 'tiles'))
    ->middleware(array_merge(
        $globalMiddleware,
        Throttle::middleware(config('vector-tiles.throttle'), 'vector-tiles'),
    ))
    ->group(function () {
        Route::get('{layer}.json', TileJsonController::class)
            ->where('layer', '[a-zA-Z0-9_-]+');

        Route::get('{layer}/{z}/{x}/{y}.mvt', TileController::class)
            ->middleware(ValidateTileRequest::class)
            ->where(['z' => '[0-9]{1,2}', 'x' => '[0-9]+', 'y' => '[0-9]+']);
    });

Route::prefix(config('vector-tiles.geojson.prefix', 'geojson'))
    ->middleware(array_merge(
        $globalMiddleware,
        Throttle::middleware(
            config('vector-tiles.geojson.throttle', config('vector-tiles.throttle')),
            'vector-tiles-geojson',
        ),
    ))
    ->group(function () {
        Route::get('{layer}', GeoJsonController::class);
    });
