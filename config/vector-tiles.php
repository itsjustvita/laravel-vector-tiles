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
    | Rate Limiting
    |--------------------------------------------------------------------------
    |
    | Throttle definition applied to tile and geojson routes as
    | "throttle:{value}". Set to null or false to disable.
    |
    | Examples:
    |   '60,1'      => 60 requests per minute
    |   'api'       => named limiter from a RateLimiter::for() definition
    |
    */
    'throttle' => '60,1',

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
    | CORS
    |--------------------------------------------------------------------------
    |
    | Value sent as the Access-Control-Allow-Origin response header.
    | Set to null to omit the header entirely and let a dedicated CORS
    | middleware handle it.
    |
    */
    'cors' => [
        'allowed_origins' => '*',
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
