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
