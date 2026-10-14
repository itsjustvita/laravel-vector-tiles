<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use ItsJustVita\VectorTiles\Facades\VectorTiles;

/**
 * @return array{0: int, 1: string} exit code and output
 */
function runDoctor(array $parameters = []): array
{
    $exitCode = Artisan::call('vector-tiles:doctor', $parameters);

    return [$exitCode, Artisan::output()];
}

it('passes every check for a well set up layer', function () {
    $this->createPoints('vt_points', [[16.37, 48.21]]);
    VectorTiles::layer('points')->from('vt_points')->properties(['id', 'name']);

    [$exitCode, $output] = runDoctor(['layer' => 'points']);

    expect($exitCode)->toBe(0)
        ->and($output)
        ->toContain('✓ PostGIS ')
        ->toContain('on connection pgsql')
        ->toContain('✓ Source vt_points exists')
        ->toContain('✓ Geometry column geom (geometry, SRID 4326)')
        ->toContain('✓ Properties: id, name')
        ->toContain('✓ Spatial index is used')
        ->not->toContain('⚠')
        ->not->toContain('✗');
});

it('suggests a column index when the spatial index is missing', function () {
    $this->createPoints('vt_points', [[16.37, 48.21]], index: false);
    VectorTiles::layer('points')->from('vt_points');

    [$exitCode, $output] = runDoctor(['layer' => 'points']);
    [$strictExitCode] = runDoctor(['layer' => 'points', '--strict' => true]);

    expect($exitCode)->toBe(0)
        ->and($strictExitCode)->toBe(1)
        ->and($output)
        ->toContain('⚠ No usable spatial index (Seq Scan on vt_points)')
        ->toContain('CREATE INDEX vt_points_geom_idx ON vt_points USING gist (geom);');
});

it('suggests an expression index for layers in other srids', function () {
    $this->createPoints('vt_utm', [[9.0, 48.75]], 25832);
    VectorTiles::layer('utm')->from('vt_utm')->srid(25832);

    [, $output] = runDoctor(['layer' => 'utm']);

    expect($output)
        ->toContain('⚠ No usable spatial index (Seq Scan on vt_utm)')
        ->toContain('CREATE INDEX vt_utm_geom_3857_idx ON vt_utm USING gist (ST_Transform(geom, 3857));');
});

it('names the base table when a view is scanned sequentially', function () {
    $this->createPoints('vt_points', [[16.37, 48.21]], index: false);
    DB::statement('CREATE VIEW vt_points_view AS SELECT id, name, geom FROM vt_points');
    VectorTiles::layer('points')->from('vt_points_view');

    [, $output] = runDoctor(['layer' => 'points']);

    expect($output)
        ->toContain('✓ Source vt_points_view exists')
        ->toContain('⚠ No usable spatial index (Seq Scan on vt_points)')
        ->toContain('If vt_points holds the geometry column, index it there:')
        ->toContain('CREATE INDEX vt_points_geom_idx ON vt_points USING gist (geom);');
});

it('fails when the srid of the column differs from the layer', function () {
    $this->createPoints('vt_utm', [[9.0, 48.75]], 25832);
    VectorTiles::layer('utm')->from('vt_utm');

    [$exitCode, $output] = runDoctor(['layer' => 'utm']);

    expect($exitCode)->toBe(1)
        ->and($output)
        ->toContain('✗ Column geom has SRID 25832, layer is configured as 4326')
        ->not->toContain('Spatial index is used')
        ->not->toContain('No usable spatial index');
});

it('fails for missing properties and leaves the connection usable', function () {
    $this->createPoints('vt_points', [[16.37, 48.21]]);
    VectorTiles::layer('points')->from('vt_points')->properties(['name', 'nope']);

    [$exitCode, $output] = runDoctor(['layer' => 'points']);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('✗ Missing properties: nope')
        ->and((int) DB::selectOne('SELECT count(*) AS n FROM vt_points')->n)->toBe(1)
        ->and(DB::selectOne('SHOW enable_seqscan')->enable_seqscan)->toBe('on');
});

it('fails when the geometry column cannot be read', function () {
    $this->createPoints('vt_points', [[16.37, 48.21]]);
    VectorTiles::layer('points')->from('vt_points')->geometry('the_geom');

    [$exitCode, $output] = runDoctor(['layer' => 'points']);

    expect($exitCode)->toBe(1)
        ->and($output)
        ->toContain('✗ Geometry column the_geom cannot be read')
        ->toContain('column "the_geom" does not exist');
});

it('fails for a missing source and skips the remaining checks', function () {
    VectorTiles::layer('missing')->from('vt_missing');

    [$exitCode, $output] = runDoctor(['layer' => 'missing']);

    expect($exitCode)->toBe(1)
        ->and($output)
        ->toContain('✗ Source vt_missing does not exist')
        ->not->toContain('Geometry column');
});

it('warns for an empty layer and still checks the index', function () {
    $this->createPoints('vt_points', []);
    VectorTiles::layer('points')->from('vt_points');

    [$exitCode, $output] = runDoctor(['layer' => 'points']);
    [$strictExitCode] = runDoctor(['layer' => 'points', '--strict' => true]);

    expect($exitCode)->toBe(0)
        ->and($strictExitCode)->toBe(1)
        ->and($output)
        ->toContain('⚠ Layer has no rows, so the geometry type and SRID cannot be checked')
        ->toContain('✓ Spatial index is used');
});

it('checks eloquent sources on the model connection', function () {
    DB::connection('pgsql_gis')->statement('CREATE SCHEMA IF NOT EXISTS vt_gis');
    $this->createPoints('vt_remote', [[16.37, 48.21]], connection: 'pgsql_gis');

    $model = new class extends Model
    {
        protected $connection = 'pgsql_gis';

        protected $table = 'vt_remote';
    };
    VectorTiles::layer('remote')->from($model->newQuery());

    [$exitCode, $output] = runDoctor(['layer' => 'remote']);

    expect($exitCode)->toBe(0)
        ->and($output)
        ->toContain('on connection pgsql_gis')
        ->toContain('✓ Source vt_remote exists')
        ->toContain('✓ Spatial index is used');
});

it('runs inside a read-only transaction and leaves no session settings behind', function () {
    $this->createPoints('vt_points', [[16.37, 48.21]]);
    VectorTiles::layer('points')->from('vt_points')->properties(['name']);
    DB::statement('SET LOCAL transaction_read_only = on');

    [$exitCode, $output] = runDoctor(['layer' => 'points']);

    expect($exitCode)->toBe(0)
        ->and($output)->not->toContain('✗')
        ->and(DB::selectOne('SHOW enable_seqscan')->enable_seqscan)->toBe('on');
});

it('runs a scope that needs a user as the given user', function () {
    $this->createPoints('vt_points', [[16.37, 48.21]]);
    $this->createUsers();
    VectorTiles::layer('mine')
        ->from('vt_points')
        ->scope(fn ($query, $user) => $query->where('name', $user?->name ?? throw new RuntimeException('This scope needs a user.')));

    [$withoutUser, $output] = runDoctor(['layer' => 'mine']);
    [$withUser] = runDoctor(['layer' => 'mine', '--user' => '7']);
    [$unknownUser, $unknownOutput] = runDoctor(['layer' => 'mine', '--user' => '99']);

    expect($withoutUser)->toBe(1)
        ->and($output)
        ->toContain('✗ The layer scope failed without a user: This scope needs a user.')
        ->toContain('Pass --user=ID')
        ->and($withUser)->toBe(0)
        ->and($unknownUser)->toBe(1)
        ->and($unknownOutput)->toContain("User '99' not found.");
});

it('warns when only a non-spatial index keeps postgres from a sequential scan', function () {
    $this->createPoints('vt_points', [[16.37, 48.21]], index: false);
    // A column default instead of an UPDATE, so the new index is usable inside the test transaction.
    DB::statement('ALTER TABLE vt_points ADD COLUMN owner_id integer NOT NULL DEFAULT 1');
    DB::statement('CREATE INDEX vt_points_owner_id_idx ON vt_points (owner_id)');
    DB::statement('ANALYZE vt_points');
    VectorTiles::layer('points')->from('vt_points')->scope(fn ($query) => $query->where('owner_id', 1));

    [$exitCode, $output] = runDoctor(['layer' => 'points', '--strict' => true]);

    expect($exitCode)->toBe(1)
        ->and($output)
        ->toContain('⚠ No usable spatial index')
        ->toContain('CREATE INDEX vt_points_geom_idx ON vt_points USING gist (geom);')
        ->not->toContain('Spatial index is used');
});

it('warns for an ordered source without a spatial index', function () {
    $this->createPoints('vt_points', [[16.37, 48.21]], index: false);
    $model = new class extends Model
    {
        protected $table = 'vt_points';
    };
    VectorTiles::layer('points')->from($model->newQuery()->orderBy('id'));

    [$exitCode, $output] = runDoctor(['layer' => 'points', '--strict' => true]);

    expect($exitCode)->toBe(1)
        ->and($output)
        ->toContain('⚠ No usable spatial index')
        ->not->toContain('Spatial index is used');
});

it('acts as the given user for global scopes that read the authenticated user', function () {
    $this->createPoints('vt_points', [[16.37, 48.21]]);
    $this->createUsers();
    $model = new class extends Model
    {
        protected $table = 'vt_points';

        protected static function booted(): void
        {
            static::addGlobalScope('mine', fn ($query) => $query->where(
                'name',
                \Illuminate\Support\Facades\Auth::user()?->name ?? throw new RuntimeException('Needs an authenticated user.'),
            ));
        }
    };
    VectorTiles::layer('mine')->from($model->newQuery());

    [$exitCode, $output] = runDoctor(['layer' => 'mine', '--user' => '7']);

    expect($output)->not->toContain('✗')
        ->and($exitCode)->toBe(0);
});
