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
    | Throttle definition applied to tile and TileJSON routes as
    | "throttle:{value}". Set to null or false to disable.
    |
    | Maps load many tiles at once, so tiles are not throttled by default.
    | Without a limit, anyone who can reach these routes can request many
    | tiles that are not cached yet. For public layers on large tables,
    | set a limit here or in your web server. Requests and minutes are
    | counted separately from other routes of the application.
    |
    | Examples:
    |   '600,1'     => 600 requests per minute
    |   'tiles'     => named limiter from a RateLimiter::for() definition
    |
    */
    'throttle' => null,

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
    | '*' lets any website read the responses. For layers that are only
    | protected by network access, such as an intranet or a VPN, set
    | this to the origin of your map.
    |
    */
    'cors' => [
        'allowed_origins' => '*',
    ],

    /*
    |--------------------------------------------------------------------------
    | GeoJSON Endpoint Settings
    |--------------------------------------------------------------------------
    |
    | 'throttle' takes the same values as the throttle setting above and
    | applies to the GeoJSON route. Without this key, the throttle setting
    | above applies.
    |
    */
    'geojson' => [
        'prefix' => 'geojson',
        'default_limit' => 1000,
        'max_limit' => 5000,
        'throttle' => '60,1',
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
    |     'simplify' => 1.5,
    |     'middleware' => [],
    | ],
    |
    */
    'layers' => [],

];
