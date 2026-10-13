<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use ItsJustVita\VectorTiles\Drivers\PostgisTileDriver;
use ItsJustVita\VectorTiles\Layer;
use ItsJustVita\VectorTiles\Query\CompiledQuery;

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
        ->toContain("ST_AsMVT(__vt_tile, 'trassen'::text, 4096::int4, '__vt_geom')")
        ->toContain(') AS __vt_tile')
        ->toContain('ST_ClipByBox2D("geom", ST_MakeEnvelope(-180, -85.0511')
        ->toContain('END, 3857), ST_TileEnvelope(14, 8532, 5765), 4096::int4, 256::int4, true) AS __vt_geom')
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
        ->toContain("ST_AsMVT(__vt_tile, 'trassen'::text, 8192::int4, '__vt_geom')")
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

it('simplifies geometries below the max zoom with a tolerance in pixels', function () {
    $layer = (new Layer('trassen'))->from('trassen_table')->maxZoom(16)->simplify(1.5);

    $z8 = captureTileQueries(fn () => $this->driver->getTile($layer, 8, 139, 88));
    $z12 = captureTileQueries(fn () => $this->driver->getTile($layer, 12, 2234, 1420));

    expect($z8[0]['query'])
        ->toMatch('/ST_AsMVTGeom\(ST_Simplify\(ST_Transform\(CASE WHEN .+ END, 3857\), [\d.]+::float8, false\), ST_TileEnvelope\(8, 139, 88\)/')
        ->and($z8[0]['bindings'][2])->toEqualWithDelta(458.6221697, 1e-6)
        ->and($z12[0]['bindings'][2])->toEqualWithDelta(28.6638856, 1e-6);
});

it('keeps full detail at and beyond the max zoom', function () {
    $layer = (new Layer('trassen'))->from('trassen_table')->maxZoom(16)->simplify(1.5);

    $z16 = captureTileQueries(fn () => $this->driver->getTile($layer, 16, 35748, 22724));
    $z17 = captureTileQueries(fn () => $this->driver->getTile($layer, 17, 71496, 45448));

    expect($z16[0]['query'])->not->toContain('ST_Simplify')
        ->and($z17[0]['query'])->not->toContain('ST_Simplify');
});

it('does not simplify layers without a tolerance', function () {
    $queries = captureTileQueries(fn () => $this->driver->getTile((new Layer('trassen'))->from('trassen_table'), 8, 139, 88));

    expect($queries[0]['query'])->not->toContain('ST_Simplify');
});

it('passes the zoom level to the zoom filter', function () {
    $layer = (new Layer('trassen'))
        ->from('trassen_table')
        ->filterByZoom(fn ($query, int $z) => $query->where('min_zoom', '<=', $z));

    $queries = captureTileQueries(fn () => $this->driver->getTile($layer, 14, 8532, 5765));

    expect($queries[0]['query'])->toContain('"min_zoom" <= 14');
});

it('binds the tolerance before the tile and filter values', function () {
    $layer = (new Layer('trassen'))
        ->from('trassen_table')
        ->maxZoom(16)
        ->simplify(1.5)
        ->scope(fn ($query) => $query->where('owner_id', 42))
        ->filterByZoom(fn ($query, int $z) => $query->where('min_zoom', '<=', $z));

    $bindings = captureTileQueries(fn () => $this->driver->getTile($layer, 8, 139, 88))[0]['bindings'];

    expect($bindings[0])->toBe('trassen')
        ->and($bindings[1])->toBe(4096)
        ->and($bindings[2])->toEqualWithDelta(458.6221697, 1e-6)
        ->and(array_slice($bindings, 3, 7))->toBe([8, 139, 88, 4096, 256, 42, 8]);
});

it('exposes the complete tile query', function () {
    $compiled = $this->driver->tileQuery((new Layer('trassen'))->from('trassen_table'), 14, 8532, 5765);

    expect($compiled)->toBeInstanceOf(CompiledQuery::class)
        ->and($compiled->sql)->toStartWith("SELECT ST_AsMVT(__vt_tile, ?::text, ?::int4, '__vt_geom') AS tile FROM (")
        ->and(array_slice($compiled->bindings, 0, 7))->toBe(['trassen', 4096, 14, 8532, 5765, 4096, 256])
        ->and($compiled->connection->getName())->toBe(config('database.default'));
});
