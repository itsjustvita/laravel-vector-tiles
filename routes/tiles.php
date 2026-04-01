<?php

use Illuminate\Support\Facades\Route;
use ItsJustVita\VectorTiles\Http\Controllers\TileController;
use ItsJustVita\VectorTiles\Http\Middleware\ValidateTileRequest;

Route::prefix(config('vector-tiles.prefix', 'tiles'))
    ->middleware(array_merge(
        config('vector-tiles.middleware', []),
        [ValidateTileRequest::class],
    ))
    ->group(function () {
        Route::get('{layer}/{z}/{x}/{y}.mvt', TileController::class)
            ->where(['z' => '[0-9]{1,2}', 'x' => '[0-9]+', 'y' => '[0-9]+']);
    });
