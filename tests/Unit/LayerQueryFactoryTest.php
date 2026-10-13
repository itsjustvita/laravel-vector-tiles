<?php

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use ItsJustVita\VectorTiles\Layer;
use ItsJustVita\VectorTiles\Query\LayerQueryFactory;

beforeEach(function () {
    config()->set('database.connections.secondary', config('database.connections.'.config('database.default')));

    $this->factory = app(LayerQueryFactory::class);
});

it('builds table sources on the layer connection', function () {
    $query = $this->factory->make((new Layer('trassen'))->from('trassen_table')->connection('secondary'));

    expect($query->getConnection()->getName())->toBe('secondary')
        ->and($query->toSql())->toBe('select * from "trassen_table"');
});

it('falls back to the layer name as table', function () {
    $query = $this->factory->make(new Layer('trassen'));

    expect($query->toSql())->toBe('select * from "trassen"');
});

it('builds eloquent sources on the model connection', function () {
    $model = new class extends Model
    {
        protected $connection = 'secondary';

        protected $table = 'trassen_table';
    };

    $query = $this->factory->make((new Layer('trassen'))->from($model->newQuery()));

    expect($query->getConnection()->getName())->toBe('secondary');
});

it('applies the layer scope with the given user', function () {
    $layer = (new Layer('trassen'))
        ->from('trassen_table')
        ->scope(fn ($query, $user) => $query->where('owner_id', $user->id));

    $query = $this->factory->make($layer, new GenericUser(['id' => 7]));

    expect($query->toSql())->toBe('select * from "trassen_table" where "owner_id" = ?')
        ->and($query->getBindings())->toBe([7]);
});

it('never mutates the eloquent source of a layer', function () {
    $model = new class extends Model
    {
        protected $table = 'trassen_table';
    };
    $source = $model->newQuery()->where('status', 'aktiv');
    $layer = (new Layer('trassen'))->from($source)->scope(fn ($query) => $query->where('owner_id', 1));

    $first = $this->factory->make($layer);
    $first->where('extra', 1);
    $second = $this->factory->make($layer);

    expect($source->toBase()->wheres)->toHaveCount(1)
        ->and($second->wheres)->toHaveCount(2);
});

it('applies global scopes such as soft deletes from eloquent sources', function () {
    $model = new class extends Model
    {
        use SoftDeletes;

        protected $table = 'trassen_table';
    };

    $query = $this->factory->make((new Layer('trassen'))->from($model->newQuery()));

    expect($query->toSql())->toContain('"trassen_table"."deleted_at" is null');
});

it('groups or-constraints of the source so the scope cannot be bypassed', function () {
    $model = new class extends Model
    {
        protected $table = 'trassen_table';
    };
    $layer = (new Layer('trassen'))
        ->from($model->newQuery()->where('status', 'aktiv')->orWhere('status', 'geplant'))
        ->scope(fn ($query) => $query->where('owner_id', 1));

    $query = $this->factory->make($layer);

    expect($query->toSql())->toBe('select * from "trassen_table" where ("status" = ? or "status" = ?) and "owner_id" = ?')
        ->and($query->getBindings())->toBe(['aktiv', 'geplant', 1]);
});

it('groups or-constraints of the scope so later filters cannot be bypassed', function () {
    $layer = (new Layer('trassen'))
        ->from('trassen_table')
        ->scope(fn ($query) => $query->where('owner_id', 1)->orWhere('public', 1));

    $query = $this->factory->make($layer);
    $query->where('tile_filter', 1);

    expect($query->toSql())->toBe('select * from "trassen_table" where ("owner_id" = ? or "public" = ?) and "tile_filter" = ?')
        ->and($query->getBindings())->toBe([1, 1, 1]);
});

it('applies the zoom filter after the scope and groups its or-constraints', function () {
    $receivedZoom = null;
    $layer = (new Layer('trassen'))
        ->from('trassen_table')
        ->scope(fn ($query) => $query->where('owner_id', 1))
        ->filterByZoom(function ($query, int $z) use (&$receivedZoom) {
            $receivedZoom = $z;
            $query->where('major', true)->orWhere('min_zoom', '<=', $z);
        });

    $query = $this->factory->make($layer, null, 8);

    expect($receivedZoom)->toBe(8)
        ->and($query->toSql())->toBe('select * from "trassen_table" where "owner_id" = ? and ("major" = ? or "min_zoom" <= ?)')
        ->and($query->getBindings())->toBe([1, true, 8]);
});

it('ignores the zoom filter without a zoom level', function () {
    $layer = (new Layer('trassen'))
        ->from('trassen_table')
        ->filterByZoom(fn ($query) => $query->where('major', true));

    expect($this->factory->make($layer)->toSql())->toBe('select * from "trassen_table"');
});
