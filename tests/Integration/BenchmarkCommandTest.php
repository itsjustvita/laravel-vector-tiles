<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use ItsJustVita\VectorTiles\Facades\VectorTiles;

/**
 * @return array{0: int, 1: string} exit code and output
 */
function runBenchmark(array $parameters): array
{
    $exitCode = Artisan::call('vector-tiles:benchmark', $parameters);

    return [$exitCode, Artisan::output()];
}

beforeEach(function () {
    // Two points in Paris (p0, p1) and a 6×5 grid around Vienna.
    $points = [[2.35, 48.86], [2.36, 48.87]];

    for ($i = 0; $i < 30; $i++) {
        $points[] = [16.2 + ($i % 6) * 0.05, 48.1 + intdiv($i, 6) * 0.05];
    }

    $this->createPoints('vt_points', $points);
    VectorTiles::layer('points')->from('vt_points')->properties(['name'])->maxZoom(14);

    $this->tileList = tempnam(sys_get_temp_dir(), 'vt-tiles-');
});

afterEach(function () {
    // Skipped tests never run beforeEach, so there is no tile list to remove.
    if (isset($this->tileList) && is_file($this->tileList)) {
        unlink($this->tileList);
    }
});

it('reports render time and tile size per zoom level as json', function () {
    [$exitCode, $output] = runBenchmark(['layer' => 'points', '--zoom' => '10-12', '--tiles' => '3', '--json' => true]);
    $result = json_decode($output, true);

    expect($exitCode)->toBe(0)
        ->and($result['layer'])->toBe('points')
        ->and(array_column($result['zooms'], 'zoom'))->toBe([10, 11, 12])
        ->and($result['skipped_zooms'])->toBe([])
        ->and($result['tiles'])->toHaveCount(array_sum(array_column($result['zooms'], 'tiles')));

    foreach ($result['zooms'] as $zoom) {
        expect($zoom['tiles'])->toBeGreaterThanOrEqual(1)->toBeLessThanOrEqual(3)
            ->and($zoom['empty'])->toBe(0)
            ->and($zoom['ms'])->toHaveKeys(['p50', 'p95', 'max'])
            ->and($zoom['bytes']['avg'])->toBeGreaterThan(0)
            ->and($zoom['bytes']['gzip_avg'])->toBeGreaterThan(0);
    }

    foreach ($result['tiles'] as $tile) {
        expect($tile)->toHaveKeys(['tile', 'ms', 'bytes', 'gzip_bytes'])
            ->and($tile['bytes'])->toBeGreaterThan(0);
    }
});

it('prints a table with the slowest tiles', function () {
    [$exitCode, $output] = runBenchmark(['layer' => 'points', '--zoom' => '8,10', '--tiles' => '2']);

    expect($exitCode)->toBe(0)
        ->and($output)->toContain('p50 ms')->toContain('avg gz KB')->toContain('Slowest: ');
});

it('measures exactly the saved tiles again', function () {
    [, $first] = runBenchmark(['layer' => 'points', '--zoom' => '10,12', '--tiles' => '5', '--output' => $this->tileList, '--json' => true]);
    [$exitCode, $second] = runBenchmark(['layer' => 'points', '--tiles-from' => $this->tileList, '--json' => true]);
    $saved = array_values(array_filter(explode("\n", file_get_contents($this->tileList))));

    expect($exitCode)->toBe(0)
        ->and($saved)->not->toBeEmpty()
        ->and(array_column(json_decode($first, true)['tiles'], 'tile'))->toBe($saved)
        ->and(array_column(json_decode($second, true)['tiles'], 'tile'))->toBe($saved);
});

it('samples only features inside the bbox', function () {
    [$exitCode, $output] = runBenchmark(['layer' => 'points', '--zoom' => '10', '--tiles' => '10', '--bbox' => '16,48,17,49', '--json' => true]);
    $tiles = array_column(json_decode($output, true)['tiles'], 'tile');

    expect($exitCode)->toBe(0)
        ->and($tiles)->not->toBeEmpty()
        ->and($tiles)->each->toStartWith('10/558/');
});

it('skips zoom levels outside the layer range', function () {
    [$exitCode, $output] = runBenchmark(['layer' => 'points', '--zoom' => '13-16', '--tiles' => '1', '--json' => true]);
    $result = json_decode($output, true);

    expect($exitCode)->toBe(0)
        ->and(array_column($result['zooms'], 'zoom'))->toBe([13, 14])
        ->and($result['skipped_zooms'])->toBe([15, 16]);
});

it('skips listed tiles outside the layer range', function () {
    file_put_contents($this->tileList, "8/139/88\n15/0/0\n");

    [$exitCode, $output] = runBenchmark(['layer' => 'points', '--tiles-from' => $this->tileList, '--json' => true]);
    $result = json_decode($output, true);

    expect($exitCode)->toBe(0)
        ->and(array_column($result['tiles'], 'tile'))->toBe(['8/139/88'])
        ->and($result['skipped_zooms'])->toBe([15]);
});

it('fails when no features lie inside the bbox', function () {
    [$exitCode, $output] = runBenchmark(['layer' => 'points', '--bbox' => '100,30,110,40']);

    expect($exitCode)->toBe(1)
        ->and($output)->toContain('No features found inside the bbox.');
});

it('runs inside a read-only transaction', function () {
    DB::statement('SET LOCAL transaction_read_only = on');

    [$exitCode] = runBenchmark(['layer' => 'points', '--zoom' => '10', '--tiles' => '2']);

    expect($exitCode)->toBe(0);
});

it('runs a scope that needs a user as the given user', function () {
    $this->createUsers();
    VectorTiles::layer('mine')
        ->from('vt_points')
        ->maxZoom(14)
        ->scope(fn ($query, $user) => $query->where('name', $user?->name ?? throw new RuntimeException('This scope needs a user.')));

    [$withoutUser, $output] = runBenchmark(['layer' => 'mine', '--zoom' => '10', '--tiles' => '1']);
    [$withUser] = runBenchmark(['layer' => 'mine', '--zoom' => '10', '--tiles' => '1', '--user' => '7']);
    [$unknownUser, $unknownOutput] = runBenchmark(['layer' => 'mine', '--zoom' => '10', '--tiles' => '1', '--user' => '99']);

    expect($withoutUser)->toBe(1)
        ->and($output)->toContain('This scope needs a user.')->toContain('--user=ID')
        ->and($withUser)->toBe(0)
        ->and($unknownUser)->toBe(1)
        ->and($unknownOutput)->toContain("User '99' not found.");
});

it('reports why rendering failed without the sql statement', function () {
    VectorTiles::layer('broken')->from('vt_points')->maxZoom(14)->properties(['nope']);

    [$exitCode, $output] = runBenchmark(['layer' => 'broken', '--zoom' => '10', '--tiles' => '1']);

    expect($exitCode)->toBe(1)
        ->and($output)
        ->toContain('Rendering failed: column "nope" does not exist')
        ->not->toContain('SQLSTATE')
        ->not->toContain('(Connection:');
});

it('reports why sampling failed without the sql statement', function () {
    VectorTiles::layer('broken')->from('vt_points')->maxZoom(14)->scope(fn ($query) => $query->whereRaw('1 / (id - id) = 1'));

    [$exitCode, $output] = runBenchmark(['layer' => 'broken', '--zoom' => '10', '--tiles' => '1']);

    expect($exitCode)->toBe(1)
        ->and($output)
        ->toContain('Sampling failed: division by zero')
        ->not->toContain('SQLSTATE')
        ->not->toContain('(Connection:');
});

it('measures on the read connection without connecting the write connection', function () {
    $config = $this->connectionConfig();
    // Committed outside the test transaction, so the second connection sees it.
    $pdo = new PDO("pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}", $config['username'], $config['password']);
    $pdo->exec('CREATE TABLE vt_replica_points (id serial PRIMARY KEY, geom geometry(Point, 4326))');
    $pdo->exec('INSERT INTO vt_replica_points (geom) VALUES (ST_SetSRID(ST_MakePoint(16.37, 48.21), 4326))');

    try {
        config()->set('database.connections.pgsql_replica', array_merge($config, [
            'read' => ['host' => $config['host']],
            'write' => ['host' => '127.0.0.1', 'port' => '1'],
        ]));
        VectorTiles::layer('replica')->from('vt_replica_points')->connection('pgsql_replica')->maxZoom(14);

        [$exitCode, $output] = runBenchmark(['layer' => 'replica', '--zoom' => '10', '--tiles' => '1']);
    } finally {
        $pdo->exec('DROP TABLE vt_replica_points');
    }

    expect($output)->not->toContain('failed')
        ->and($exitCode)->toBe(0);
});

it('samples a position inside the bbox on features that extend beyond it', function () {
    // The point on the whole line is 15,48.5, outside the bbox.
    DB::statement('CREATE TABLE vt_lines (id serial PRIMARY KEY, geom geometry(LineString, 4326))');
    DB::insert("INSERT INTO vt_lines (geom) VALUES (ST_GeomFromText('LINESTRING(10 48.5, 11 48.5, 12 48.5, 13 48.5, 14 48.5, 15 48.5, 16 48.5, 17 48.5, 18 48.5, 19 48.5, 20 48.5)', 4326))");
    VectorTiles::layer('lines')->from('vt_lines')->maxZoom(14);

    [$exitCode, $output] = runBenchmark(['layer' => 'lines', '--zoom' => '10', '--tiles' => '1', '--bbox' => '16,48,17,49', '--json' => true]);
    $tiles = array_column(json_decode($output, true)['tiles'] ?? [], 'tile');

    expect($exitCode)->toBe(0)
        ->and($tiles)->toHaveCount(1)
        ->and($tiles[0])->toMatch('#^10/55[678]/#');
});
