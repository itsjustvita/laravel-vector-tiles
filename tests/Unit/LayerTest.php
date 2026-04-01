<?php

use Illuminate\Database\Eloquent\Builder;
use ItsJustVita\VectorTiles\Layer;

it('creates a layer with name', function () {
    $layer = new Layer('trassen');

    expect($layer->name)->toBe('trassen');
});

it('has sensible defaults', function () {
    $layer = new Layer('trassen');

    expect($layer->geometry)->toBe('geom')
        ->and($layer->srid)->toBe(4326)
        ->and($layer->properties)->toBe([])
        ->and($layer->minZoom)->toBe(0)
        ->and($layer->maxZoom)->toBe(22)
        ->and($layer->middleware)->toBe([])
        ->and($layer->scope)->toBeNull()
        ->and($layer->invalidateOn)->toBe([])
        ->and($layer->extent)->toBe(4096)
        ->and($layer->buffer)->toBe(256)
        ->and($layer->connection)->toBeNull()
        ->and($layer->source)->toBeNull();
});

it('supports fluent builder', function () {
    $scope = fn (Builder $q) => $q;

    $layer = (new Layer('trassen'))
        ->from('trassen_table')
        ->geometry('the_geom')
        ->srid(3857)
        ->properties(['id', 'typ'])
        ->minZoom(10)
        ->maxZoom(18)
        ->middleware(['auth'])
        ->scope($scope)
        ->invalidateOn(\stdClass::class)
        ->extent(8192)
        ->buffer(512)
        ->connection('pgsql_gis');

    expect($layer->source)->toBe('trassen_table')
        ->and($layer->geometry)->toBe('the_geom')
        ->and($layer->srid)->toBe(3857)
        ->and($layer->properties)->toBe(['id', 'typ'])
        ->and($layer->minZoom)->toBe(10)
        ->and($layer->maxZoom)->toBe(18)
        ->and($layer->middleware)->toBe(['auth'])
        ->and($layer->scope)->toBe($scope)
        ->and($layer->invalidateOn)->toBe([\stdClass::class])
        ->and($layer->extent)->toBe(8192)
        ->and($layer->buffer)->toBe(512)
        ->and($layer->connection)->toBe('pgsql_gis');
});

it('creates from config array', function () {
    $layer = Layer::fromConfig('trassen', [
        'table' => 'trassen_table',
        'geometry' => 'the_geom',
        'srid' => 4326,
        'properties' => ['id', 'typ'],
        'min_zoom' => 10,
        'max_zoom' => 18,
        'middleware' => ['auth'],
    ]);

    expect($layer->name)->toBe('trassen')
        ->and($layer->source)->toBe('trassen_table')
        ->and($layer->geometry)->toBe('the_geom')
        ->and($layer->properties)->toBe(['id', 'typ'])
        ->and($layer->minZoom)->toBe(10)
        ->and($layer->maxZoom)->toBe(18)
        ->and($layer->middleware)->toBe(['auth']);
});

it('resolves table name from string source', function () {
    $layer = (new Layer('trassen'))->from('trassen_table');

    expect($layer->getTable())->toBe('trassen_table');
});

it('resolves table name from eloquent builder', function () {
    $builder = Mockery::mock(Builder::class);
    $baseBuilder = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    $baseBuilder->from = 'trassen_table';
    $builder->shouldReceive('toBase')->andReturn($baseBuilder);

    $layer = (new Layer('trassen'))->from($builder);

    expect($layer->getTable())->toBe('trassen_table');
});

it('accumulates multiple invalidateOn calls', function () {
    $layer = (new Layer('trassen'))
        ->invalidateOn(\stdClass::class)
        ->invalidateOn(\ArrayObject::class);

    expect($layer->invalidateOn)->toBe([\stdClass::class, \ArrayObject::class]);
});
