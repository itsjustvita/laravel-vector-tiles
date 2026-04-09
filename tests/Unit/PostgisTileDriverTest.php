<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use ItsJustVita\VectorTiles\Drivers\PostgisTileDriver;
use ItsJustVita\VectorTiles\Layer;

beforeEach(function () {
    $this->driver = new PostgisTileDriver($this->app['db']);
});

function captureTileQueries(callable $fn): array
{
    return DB::pretend(function () use ($fn) {
        $fn();
    });
}

function trassenModel(): Model
{
    return new class extends Model
    {
        protected $table = 'trassen_table';

        protected $guarded = [];
    };
}

it('builds correct SQL for simple table layer', function () {
    $layer = (new Layer('trassen'))
        ->from('trassen_table')
        ->geometry('geom')
        ->properties(['id', 'typ', 'status']);

    $queries = captureTileQueries(fn () => $this->driver->getTile($layer, 14, 8532, 5765));

    expect($queries)->toHaveCount(1);

    $sql = $queries[0]['query'];
    expect($sql)
        ->toContain('ST_AsMVT')
        ->toContain('ST_AsMVTGeom')
        ->toContain('ST_Transform("geom", 3857)')
        ->toContain('ST_TileEnvelope(14, 8532, 5765)')
        ->toContain('"trassen_table"')
        ->toContain('ST_Intersects')
        ->toContain('"id"')
        ->toContain('"typ"')
        ->toContain('"status"');
});

it('returns null when the underlying query returns no tile', function () {
    $layer = (new Layer('trassen'))->from('trassen_table');

    $result = null;
    DB::pretend(function () use ($layer, &$result) {
        $result = $this->driver->getTile($layer, 14, 8532, 5765);
    });

    expect($result)->toBeNull();
});

it('uses configured connection', function () {
    $defaultName = config('database.default');
    config()->set('database.connections.pgsql_gis', config("database.connections.{$defaultName}"));

    $layer = (new Layer('trassen'))
        ->from('trassen_table')
        ->connection('pgsql_gis');

    $queries = DB::connection('pgsql_gis')->pretend(function () use ($layer) {
        $this->driver->getTile($layer, 14, 8532, 5765);
    });

    expect($queries)->toHaveCount(1);
    expect($queries[0]['query'])->toContain('ST_AsMVT');
});

it('uses custom extent and buffer', function () {
    $layer = (new Layer('trassen'))
        ->from('trassen_table')
        ->extent(8192)
        ->buffer(512);

    $queries = captureTileQueries(fn () => $this->driver->getTile($layer, 14, 8532, 5765));

    expect($queries[0]['query'])
        ->toContain('8192')
        ->toContain('512');
});

it('handles eloquent builder source', function () {
    $builder = trassenModel()->newQuery()->where('status', '=', 'aktiv');

    $layer = (new Layer('trassen'))
        ->from($builder)
        ->properties(['id']);

    $queries = captureTileQueries(fn () => $this->driver->getTile($layer, 14, 8532, 5765));

    expect($queries[0]['query'])
        ->toContain('"trassen_table"')
        ->toContain('"status" = \'aktiv\'');
});

it('preserves whereIn constraints from eloquent source', function () {
    $builder = trassenModel()->newQuery()->whereIn('status', ['aktiv', 'geplant']);

    $layer = (new Layer('trassen'))
        ->from($builder)
        ->properties(['id']);

    $queries = captureTileQueries(fn () => $this->driver->getTile($layer, 14, 8532, 5765));

    expect($queries[0]['query'])->toContain('"status" in (\'aktiv\', \'geplant\')');
});

it('preserves whereNull constraints from eloquent source', function () {
    $builder = trassenModel()->newQuery()->whereNull('deleted_at');

    $layer = (new Layer('trassen'))->from($builder);

    $queries = captureTileQueries(fn () => $this->driver->getTile($layer, 14, 8532, 5765));

    expect($queries[0]['query'])->toContain('"deleted_at" is null');
});

it('preserves whereBetween constraints from eloquent source', function () {
    $builder = trassenModel()->newQuery()->whereBetween('built_year', [1990, 2020]);

    $layer = (new Layer('trassen'))->from($builder);

    $queries = captureTileQueries(fn () => $this->driver->getTile($layer, 14, 8532, 5765));

    expect($queries[0]['query'])->toContain('"built_year" between 1990 and 2020');
});

it('preserves nested OR constraints from eloquent source', function () {
    $builder = trassenModel()->newQuery()->where(function ($q) {
        $q->where('priority', 'high')->orWhere('override', true);
    });

    $layer = (new Layer('trassen'))->from($builder);

    $queries = captureTileQueries(fn () => $this->driver->getTile($layer, 14, 8532, 5765));

    expect($queries[0]['query'])->toContain("'high'");
    expect($queries[0]['query'])->toContain('or "override"');
});

it('applies scope closure constraints', function () {
    $layer = (new Layer('trassen'))
        ->from('trassen_table')
        ->scope(function ($query, $user) {
            $query->where('owner_id', 42)->whereIn('visibility', ['public', 'shared']);
        });

    $queries = captureTileQueries(fn () => $this->driver->getTile($layer, 14, 8532, 5765));

    expect($queries[0]['query'])
        ->toContain('"owner_id" = 42')
        ->toContain('"visibility" in (\'public\', \'shared\')');
});

it('passes user to scope closure for auth-aware filtering', function () {
    $user = new Illuminate\Auth\GenericUser(['id' => 1337]);

    $capturedUser = null;
    $layer = (new Layer('trassen'))
        ->from('trassen_table')
        ->scope(function ($query, $u) use (&$capturedUser) {
            $capturedUser = $u;
            $query->where('owner_id', $u->id);
        });

    $queries = DB::pretend(fn () => $this->driver->getTile($layer, 14, 8532, 5765, $user));

    expect($capturedUser)->toBe($user);
    expect($queries[0]['query'])->toContain('"owner_id" = 1337');
});
