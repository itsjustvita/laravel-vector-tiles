<?php

namespace ItsJustVita\VectorTiles\Cache;

use Illuminate\Contracts\Events\ShouldHandleEventsAfterCommit;
use Illuminate\Database\Eloquent\Model;
use ItsJustVita\VectorTiles\VectorTileManager;

/**
 * Flushes the tile cache of layers registered with invalidateOn() when one of
 * their models changes. Inside a transaction the flush waits for the commit:
 * a tile rendered before the commit still sees the old rows and would be
 * cached as the current version, and a rollback leaves the cache intact.
 */
class TileCacheInvalidator implements ShouldHandleEventsAfterCommit
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
