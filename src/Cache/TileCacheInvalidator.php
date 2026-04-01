<?php

namespace ItsJustVita\VectorTiles\Cache;

use Illuminate\Database\Eloquent\Model;
use ItsJustVita\VectorTiles\VectorTileManager;

class TileCacheInvalidator
{
    public function __construct(
        protected TileCache $cache,
        protected VectorTileManager $manager,
    ) {}

    public function created(Model $model): void
    {
        $this->flushLayersFor($model);
    }

    public function updated(Model $model): void
    {
        $this->flushLayersFor($model);
    }

    public function deleted(Model $model): void
    {
        $this->flushLayersFor($model);
    }

    protected function flushLayersFor(Model $model): void
    {
        foreach ($this->manager->getLayers() as $layer) {
            if (in_array($model::class, $layer->invalidateOn)) {
                $this->cache->flushLayer($layer->name);
            }
        }
    }
}
