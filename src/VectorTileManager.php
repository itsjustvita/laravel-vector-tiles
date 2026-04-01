<?php

namespace ItsJustVita\VectorTiles;

use Illuminate\Contracts\Foundation\Application;
use ItsJustVita\VectorTiles\Drivers\TileDriverInterface;
use ItsJustVita\VectorTiles\Helpers\MaplibreSourceHelper;
use ItsJustVita\VectorTiles\Testing\FakeTileDriver;

class VectorTileManager
{
    /** @var array<string, Layer> */
    protected array $layers = [];

    protected ?FakeTileDriver $fakeDriver = null;

    public function __construct(
        protected Application $app,
    ) {}

    public function layer(string $name): Layer
    {
        if (! isset($this->layers[$name])) {
            $this->layers[$name] = new Layer($name);
        }

        return $this->layers[$name];
    }

    public function getLayer(string $name): ?Layer
    {
        return $this->layers[$name] ?? null;
    }

    public function hasLayer(string $name): bool
    {
        return isset($this->layers[$name]);
    }

    /** @return array<string, Layer> */
    public function getLayers(): array
    {
        return $this->layers;
    }

    public function maplibreSource(string $name): ?array
    {
        $layer = $this->getLayer($name);

        if ($layer === null) {
            return null;
        }

        return MaplibreSourceHelper::sourceForLayer($layer);
    }

    /** @return array<string, array> */
    public function maplibreSources(): array
    {
        $sources = [];

        foreach ($this->layers as $name => $layer) {
            $sources[$name] = MaplibreSourceHelper::sourceForLayer($layer);
        }

        return $sources;
    }

    public function loadConfigLayers(): void
    {
        $layers = $this->app['config']->get('vector-tiles.layers', []);

        foreach ($layers as $name => $config) {
            if (! isset($this->layers[$name])) {
                $this->layers[$name] = Layer::fromConfig($name, $config);
            }
        }
    }

    public function fake(): void
    {
        $this->fakeDriver = new FakeTileDriver();
        $this->app->instance(TileDriverInterface::class, $this->fakeDriver);
    }

    public function assertTileRequested(string $layer, int $z, int $x, int $y): void
    {
        if ($this->fakeDriver === null) {
            throw new \RuntimeException('VectorTiles::fake() must be called before asserting.');
        }

        $this->fakeDriver->assertTileRequested($layer, $z, $x, $y);
    }

    public function assertTileNotRequested(string $layer, int $z, int $x, int $y): void
    {
        if ($this->fakeDriver === null) {
            throw new \RuntimeException('VectorTiles::fake() must be called before asserting.');
        }

        $this->fakeDriver->assertTileNotRequested($layer, $z, $x, $y);
    }
}
