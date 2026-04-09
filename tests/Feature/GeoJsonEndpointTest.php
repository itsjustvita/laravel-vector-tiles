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
