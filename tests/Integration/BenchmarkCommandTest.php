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
    if (is_file($this->tileList)) {
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
