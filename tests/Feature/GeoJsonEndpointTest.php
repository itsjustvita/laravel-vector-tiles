<?php

use ItsJustVita\VectorTiles\Facades\VectorTiles;

beforeEach(function () {
    VectorTiles::layer('trassen')
        ->from('trassen_table')
        ->properties(['id', 'typ'])
        ->minZoom(10)
        ->maxZoom(18);
});

it('returns 422 when bbox is missing', function () {
    $this->getJson('/geojson/trassen')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['bbox']);
});

it('returns 422 for invalid bbox format', function () {
    $this->getJson('/geojson/trassen?bbox=invalid')
        ->assertUnprocessable();
});

it('returns 422 for bbox with wrong number of coordinates', function () {
    $this->getJson('/geojson/trassen?bbox=1,2,3')
        ->assertUnprocessable();
});

it('returns 404 for unknown layer', function () {
    $this->getJson('/geojson/unknown?bbox=9.0,48.0,9.5,48.5')
        ->assertNotFound();
});

it('returns geojson response with correct structure', function () {
    \Illuminate\Support\Facades\DB::pretend(function () {
        $this->getJson('/geojson/trassen?bbox=9.0,48.0,9.5,48.5')
            ->assertOk()
            ->assertHeader('Content-Type', 'application/geo+json')
            ->assertJsonStructure(['type', 'features']);
    });
});

it('caps limit at max_limit from config', function () {
    config()->set('vector-tiles.geojson.max_limit', 100);

    $queries = \Illuminate\Support\Facades\DB::pretend(function () {
        $this->getJson('/geojson/trassen?bbox=9.0,48.0,9.5,48.5&limit=9999')
            ->assertOk();
    });

    expect($queries[0]['query'])->toMatch('/limit\s+100/i');
});

it('preserves whereIn constraints from eloquent source', function () {
    $model = new class extends \Illuminate\Database\Eloquent\Model
    {
        protected $table = 'trassen_table';

        protected $guarded = [];
    };

    VectorTiles::layer('filtered')
        ->from($model->newQuery()->whereIn('status', ['aktiv', 'geplant']))
        ->properties(['id']);

    $queries = \Illuminate\Support\Facades\DB::pretend(function () {
        $this->getJson('/geojson/filtered?bbox=9.0,48.0,9.5,48.5')->assertOk();
    });

    expect($queries[0]['query'])->toContain("'aktiv'");
    expect($queries[0]['query'])->toContain("'geplant'");
});

it('applies scope closure constraints', function () {
    VectorTiles::layer('scoped')
        ->from('trassen_table')
        ->scope(function ($query) {
            $query->where('owner_id', 42);
        });

    $queries = \Illuminate\Support\Facades\DB::pretend(function () {
        $this->getJson('/geojson/scoped?bbox=9.0,48.0,9.5,48.5')->assertOk();
    });

    expect($queries[0]['query'])->toContain('"owner_id" = 42');
});

it('blocks geojson requests when layer middleware rejects the request', function () {
    VectorTiles::layer('protected')
        ->from('trassen_table')
        ->middleware([\ItsJustVita\VectorTiles\Tests\Fixtures\RejectingMiddleware::class]);

    $this->getJson('/geojson/protected?bbox=9.0,48.0,9.5,48.5')
        ->assertUnauthorized();
});

it('returns 422 for longitudes outside [-180, 180]', function () {
    $this->getJson('/geojson/trassen?bbox=-200,48.0,9.5,48.5')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['bbox']);
});

it('returns 422 for latitudes outside [-90, 90]', function () {
    $this->getJson('/geojson/trassen?bbox=9.0,-95,9.5,48.5')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['bbox']);
});

it('returns 422 when west is greater than east', function () {
    $this->getJson('/geojson/trassen?bbox=9.5,48.0,9.0,48.5')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['bbox']);
});

it('returns 422 when south is greater than north', function () {
    $this->getJson('/geojson/trassen?bbox=9.0,48.5,9.5,48.0')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['bbox']);
});

it('filters 4326 layers directly against the bbox', function () {
    $queries = \Illuminate\Support\Facades\DB::pretend(function () {
        $this->getJson('/geojson/trassen?bbox=9.0,48.0,9.5,48.5')->assertOk();
    });

    expect($queries[0]['query'])
        ->toContain('ST_Intersects("geom", ST_MakeEnvelope(9, 48, 9.5, 48.5, 4326))')
        ->toContain('ST_AsGeoJSON("geom")::jsonb');
});

it('transforms the geometry to 4326 for layers in other srids', function () {
    VectorTiles::layer('utm')->from('utm_table')->srid(25832);

    $queries = \Illuminate\Support\Facades\DB::pretend(function () {
        $this->getJson('/geojson/utm?bbox=9.0,48.0,9.5,48.5')->assertOk();
    });

    expect($queries[0]['query'])
        ->toContain('ST_Intersects(ST_Transform("geom", 4326), ST_MakeEnvelope(9, 48, 9.5, 48.5, 4326))')
        ->toContain('ST_AsGeoJSON(ST_Transform("geom", 4326))::jsonb');
});

it('clamps the bbox latitude for web mercator layers', function () {
    VectorTiles::layer('merc')->from('merc_table')->srid(3857);

    $queries = \Illuminate\Support\Facades\DB::pretend(function () {
        $this->getJson('/geojson/merc?bbox=-10,-90,10,90')->assertOk();
    });

    expect($queries[0]['query'])
        ->toMatch('/ST_Intersects\("geom", ST_Transform\(ST_MakeEnvelope\(-10, -85\.05112877\d*, 10, 85\.05112877\d*, 4326\), 3857\)\)/');
});
