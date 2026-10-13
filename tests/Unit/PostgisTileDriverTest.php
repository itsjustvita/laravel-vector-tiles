<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use ItsJustVita\VectorTiles\Drivers\PostgisTileDriver;
use ItsJustVita\VectorTiles\Layer;

beforeEach(function () {
    $this->driver = app(PostgisTileDriver::class);
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

it('builds an index-friendly tile query for a 4326 table layer', function () {
    $layer = (new Layer('trassen'))
        ->from('trassen_table')
        ->geometry('geom')
        ->properties(['id', 'typ', 'status']);

    $queries = captureTileQueries(fn () => $this->driver->getTile($layer, 14, 8532, 5765));

    expect($queries)->toHaveCount(1);

    expect($queries[0]['query'])
        ->toContain("ST_AsMVT(tile, 'trassen'::text, 4096::int4, '__vt_geom')")
        ->toContain('ST_AsMVTGeom(ST_Transform("geom", 3857), ST_TileEnvelope(14, 8532, 5765), 4096::int4, 256::int4, true) AS __vt_geom')
        ->toMatch('/"geom" && ST_Transform\(ST_MakeEnvelope\([-\d., E+]+, 3857\), 4326\)/')
        ->toContain('"trassen_table"')
        ->toContain('"id"')
        ->toContain('"typ"')
        ->toContain('"status"')
        ->not->toContain('ST_Intersects');
});

it('returns an empty string when the query yields no tile', function () {
    $layer = (new Layer('trassen'))->from('trassen_table');

    $result = null;
    DB::pretend(function () use ($layer, &$result) {
        $result = $this->driver->getTile($layer, 14, 8532, 5765);
    });

    expect($result)->toBe('');
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

it('passes a custom extent to both mvt functions', function () {
    $layer = (new Layer('trassen'))
        ->from('trassen_table')
        ->extent(8192)
        ->buffer(512);

    $queries = captureTileQueries(fn () => $this->driver->getTile($layer, 14, 8532, 5765));

    expect($queries[0]['query'])
        ->toContain("ST_AsMVT(tile, 'trassen'::text, 8192::int4, '__vt_geom')")
        ->toContain('8192::int4, 512::int4, true');
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

it('runs eloquent sources on the model connection', function () {
    config()->set('database.connections.secondary', config('database.connections.'.config('database.default')));

    $model = new class extends Model
    {
        protected $connection = 'secondary';

        protected $table = 'trassen_table';
    };

    $layer = (new Layer('trassen'))->from($model->newQuery());

    $onSecondary = [];
    $onDefault = DB::pretend(function () use ($layer, &$onSecondary) {
        $onSecondary = DB::connection('secondary')->pretend(
            fn () => $this->driver->getTile($layer, 14, 8532, 5765),
        );
    });

    expect($onSecondary)->toHaveCount(1)
        ->and($onDefault)->toBeEmpty();
});

it('builds identical queries for repeated requests', function () {
    $layer = (new Layer('trassen'))
        ->from(trassenModel()->newQuery()->where('status', 'aktiv'))
        ->properties(['id'])
        ->scope(fn ($query) => $query->where('owner_id', 1));

    $first = captureTileQueries(fn () => $this->driver->getTile($layer, 14, 8532, 5765));
    $second = captureTileQueries(fn () => $this->driver->getTile($layer, 14, 8532, 5765));

    expect(array_column($second, 'query'))->toBe(array_column($first, 'query'));
});

it('uses geometry and envelope as-is for 3857 layers', function () {
    $layer = (new Layer('merc'))->from('merc_table')->srid(3857);

    $queries = captureTileQueries(fn () => $this->driver->getTile($layer, 14, 8532, 5765));

    expect($queries[0]['query'])
        ->toContain('ST_AsMVTGeom("geom", ST_TileEnvelope(14, 8532, 5765)')
        ->toMatch('/"geom" && ST_MakeEnvelope\([-\d., E+]+, 3857\)/')
        ->not->toContain('ST_Transform');
});

it('transforms the geometry instead of the envelope for other srids', function () {
    $layer = (new Layer('utm'))->from('utm_table')->srid(25832);

    $queries = captureTileQueries(fn () => $this->driver->getTile($layer, 14, 8532, 5765));

    expect($queries[0]['query'])
        ->toMatch('/ST_Transform\("geom", 3857\) && ST_MakeEnvelope\([-\d., E+]+, 3857\)/');
});
