<?php

namespace ItsJustVita\VectorTiles\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use InvalidArgumentException;
use ItsJustVita\VectorTiles\Benchmark\Summary;
use ItsJustVita\VectorTiles\Benchmark\TileListFile;
use ItsJustVita\VectorTiles\Benchmark\TileSampler;
use ItsJustVita\VectorTiles\Commands\Concerns\ResolvesUser;
use ItsJustVita\VectorTiles\Drivers\TileDriverInterface;
use ItsJustVita\VectorTiles\Layer;
use ItsJustVita\VectorTiles\Query\BboxFilter;
use ItsJustVita\VectorTiles\Query\LayerQueryFactory;
use ItsJustVita\VectorTiles\VectorTileManager;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

class BenchmarkCommand extends Command
{
    use ResolvesUser;

    protected const int DEFAULT_TILES = 20;

    protected const int MAX_SAMPLE = 5000;

    protected $signature = 'vector-tiles:benchmark
        {layer : The layer to benchmark}
        {--zoom= : Zoom levels as a range (8-16) or a list (8,12,16), defaults to the layer\'s range}
        {--tiles= : Tiles per zoom level, defaults to 20}
        {--bbox= : Only sample features inside west,south,east,north (WGS84)}
        {--user= : Run the layer scope as the user with this id}
        {--output= : Save the measured tiles as z/x/y lines to this file}
        {--tiles-from= : Measure exactly the tiles listed in this file}
        {--json : Print the results as JSON}';

    protected $description = 'Measure tile render time and size per zoom level';

    public function handle(
        VectorTileManager $manager,
        TileDriverInterface $driver,
        TileSampler $sampler,
        LayerQueryFactory $queries,
    ): int {
        $layer = $manager->getLayer($this->argument('layer'));

        if ($layer === null) {
            $this->error("Layer '{$this->argument('layer')}' not found.");

            return self::FAILURE;
        }

        try {
            $user = $this->resolveUser();

            [$tiles, $skipped] = $this->option('tiles-from') !== null
                ? $this->tilesFromFile($layer)
                : $this->sampleTiles($layer, $sampler, $user);

            if ($this->option('output') !== null) {
                TileListFile::write($this->option('output'), $tiles);
            }

            $measurements = $this->measure($layer, $driver, $queries, $tiles, $user);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } catch (Throwable $e) {
            $this->error('Rendering failed: '.Str::before($e->getMessage(), "\n").$this->userHint($user ?? null));

            return self::FAILURE;
        }

        $this->report($layer, $measurements, $skipped);

        return self::SUCCESS;
    }

    /**
     * @return array{0: list<array{0: int, 1: int, 2: int}>, 1: list<int>} tiles and skipped zoom levels
     */
    protected function sampleTiles(Layer $layer, TileSampler $sampler, ?Authenticatable $user): array
    {
        $perZoom = $this->tilesPerZoom();
        $bbox = $this->option('bbox') === null ? null : BboxFilter::parse($this->option('bbox'));
        [$zooms, $skipped] = $this->splitByRange($layer, $this->zooms($layer));

        try {
            $points = $sampler->points($layer, min(self::MAX_SAMPLE, $perZoom * 20), $bbox, $user);
        } catch (Throwable $e) {
            throw new InvalidArgumentException('Sampling failed: '.Str::before($e->getMessage(), "\n").$this->userHint($user));
        }

        if ($points === []) {
            throw new InvalidArgumentException($bbox === null ? 'No features found.' : 'No features found inside the bbox.');
        }

        return [TileSampler::tiles($points, $zooms, $perZoom), $skipped];
    }

    /**
     * @return array{0: list<array{0: int, 1: int, 2: int}>, 1: list<int>} tiles and skipped zoom levels
     */
    protected function tilesFromFile(Layer $layer): array
    {
        if ($this->option('zoom') !== null || $this->option('tiles') !== null || $this->option('bbox') !== null) {
            throw new InvalidArgumentException('--tiles-from cannot be combined with --zoom, --tiles or --bbox.');
        }

        $tiles = TileListFile::read($this->option('tiles-from'));
        $within = array_values(array_filter($tiles, fn (array $tile) => $this->inRange($layer, $tile[0])));

        if ($within === []) {
            throw new InvalidArgumentException("No tile within the layer's range {$layer->minZoom}-{$layer->maxZoom}.");
        }

        $skipped = array_values(array_unique(array_diff(array_column($tiles, 0), array_column($within, 0))));
        sort($skipped);

        return [$within, $skipped];
    }

    /**
     * @return list<int>
     */
    protected function zooms(Layer $layer): array
    {
        $option = $this->option('zoom');

        if ($option === null) {
            return range($layer->minZoom, $layer->maxZoom);
        }

        if (preg_match('/^(\d{1,2})-(\d{1,2})$/', $option, $match) && (int) $match[1] <= (int) $match[2]) {
            return range((int) $match[1], (int) $match[2]);
        }

        if (preg_match('/^\d{1,2}(,\d{1,2})*$/', $option)) {
            $zooms = array_values(array_unique(array_map('intval', explode(',', $option))));
            sort($zooms);

            return $zooms;
        }

        throw new InvalidArgumentException("Invalid --zoom value {$option}. Use a range like 8-16 or a list like 8,12,16.");
    }

    /**
     * @param  list<int>  $zooms
     * @return array{0: list<int>, 1: list<int>} zoom levels within and outside the layer's range
     */
    protected function splitByRange(Layer $layer, array $zooms): array
    {
        $within = array_values(array_filter($zooms, fn (int $z) => $this->inRange($layer, $z)));

        if ($within === []) {
            throw new InvalidArgumentException("No zoom level within the layer's range {$layer->minZoom}-{$layer->maxZoom}.");
        }

        return [$within, array_values(array_diff($zooms, $within))];
    }

    protected function inRange(Layer $layer, int $z): bool
    {
        return $z >= $layer->minZoom && $z <= $layer->maxZoom;
    }

    protected function tilesPerZoom(): int
    {
        $option = $this->option('tiles');

        if ($option === null) {
            return self::DEFAULT_TILES;
        }

        $tiles = filter_var($option, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if ($tiles === false) {
            throw new InvalidArgumentException('--tiles must be a positive integer.');
        }

        return $tiles;
    }

    /**
     * @param  list<array{0: int, 1: int, 2: int}>  $tiles
     * @return list<array{tile: string, zoom: int, ms: float, bytes: int, gzip_bytes: int}>
     */
    protected function measure(Layer $layer, TileDriverInterface $driver, LayerQueryFactory $queries, array $tiles, ?Authenticatable $user): array
    {
        // Connect first, so the first measurement does not include the connection setup.
        $queries->make($layer, $user)->getConnection()->getPdo();

        $measurements = [];

        foreach ($tiles as [$z, $x, $y]) {
            $start = hrtime(true);
            $tile = (string) $driver->getTile($layer, $z, $x, $y, $user);
            $ms = (hrtime(true) - $start) / 1e6;

            $measurements[] = [
                'tile' => "{$z}/{$x}/{$y}",
                'zoom' => $z,
                'ms' => round($ms, 1),
                'bytes' => strlen($tile),
                'gzip_bytes' => $tile === '' ? 0 : strlen(gzencode($tile, 6)),
            ];
        }

        return $measurements;
    }

    /**
     * @param  list<array{tile: string, zoom: int, ms: float, bytes: int, gzip_bytes: int}>  $measurements
     * @param  list<int>  $skipped
     */
    protected function report(Layer $layer, array $measurements, array $skipped): void
    {
        $zooms = Summary::byZoom($measurements);

        if ($this->option('json')) {
            $this->output->writeln(json_encode([
                'layer' => $layer->name,
                'zooms' => $zooms,
                'skipped_zooms' => $skipped,
                'tiles' => array_map(fn (array $measurement) => Arr::except($measurement, 'zoom'), $measurements),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);

            return;
        }

        $kb = fn (int $bytes) => number_format($bytes / 1024, 1);
        $ms = fn (float $value) => number_format($value, 1);

        $this->table(
            ['zoom', 'tiles', 'empty', 'p50 ms', 'p95 ms', 'max ms', 'avg KB', 'max KB', 'avg gz KB'],
            array_map(fn (array $row) => [
                $row['zoom'], $row['tiles'], $row['empty'],
                $ms($row['ms']['p50']), $ms($row['ms']['p95']), $ms($row['ms']['max']),
                $kb($row['bytes']['avg']), $kb($row['bytes']['max']), $kb($row['bytes']['gzip_avg']),
            ], $zooms),
        );

        $this->line('Slowest: '.implode(' · ', array_map(
            fn (array $measurement) => $measurement['tile'].' '.$ms($measurement['ms']).' ms',
            Summary::slowest($measurements),
        )));

        if ($skipped !== []) {
            $this->line("Skipped zoom levels outside the layer's range: ".implode(', ', $skipped));
        }
    }

    protected function userHint(?Authenticatable $user): string
    {
        return $user === null ? ' If the layer scope needs a user, pass --user=ID.' : '';
    }
}
