<?php

namespace ItsJustVita\VectorTiles;

use Illuminate\Contracts\Foundation\Application;

class VectorTileManager
{
    /** @var array<string, Layer> */
    protected array $layers = [];

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

    public function loadConfigLayers(): void
    {
        $layers = $this->app['config']->get('vector-tiles.layers', []);

        foreach ($layers as $name => $config) {
            if (! isset($this->layers[$name])) {
                $this->layers[$name] = Layer::fromConfig($name, $config);
            }
        }
    }
}
