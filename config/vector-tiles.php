<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Tile Route Prefix
    |--------------------------------------------------------------------------
    */
    'prefix' => 'tiles',

    /*
    |--------------------------------------------------------------------------
    | Global Middleware
    |--------------------------------------------------------------------------
    |
    | Applied to all tile and geojson routes.
    |
    */
    'middleware' => [],

    /*
    |--------------------------------------------------------------------------
    | Cache Settings
    |--------------------------------------------------------------------------
    */
    'cache' => [
        'enabled' => true,
        'store' => null,
        'ttl' => 3600,
    ],

    /*
    |--------------------------------------------------------------------------
    | GeoJSON Endpoint Settings
    |--------------------------------------------------------------------------
    */
    'geojson' => [
        'prefix' => 'geojson',
        'default_limit' => 1000,
        'max_limit' => 5000,
    ],

    /*
    |--------------------------------------------------------------------------
    | Layer Definitions
    |--------------------------------------------------------------------------
    |
    | Static layer definitions. Layers defined via the fluent API in a
    | ServiceProvider override same-named layers from this config.
    |
    | Example:
    | 'glasfaser_trassen' => [
    |     'table' => 'trassen',
    |     'geometry' => 'geom',
    |     'srid' => 4326,
    |     'properties' => ['id', 'typ', 'status'],
    |     'min_zoom' => 10,
    |     'max_zoom' => 18,
    |     'middleware' => [],
    | ],
    |
    */
    'layers' => [],

];
