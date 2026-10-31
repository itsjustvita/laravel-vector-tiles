<?php

namespace ItsJustVita\VectorTiles\Cache;

use Illuminate\Database\Eloquent\Model;
use ItsJustVita\VectorTiles\VectorTileManager;

/**
 * Flushes the tile cache of layers registered with invalidateOn() when one of
 * their models changes.
 *
 * Inside a transaction the cache is flushed again after the commit: a tile
 * rendered before the commit still sees the old rows and would otherwise be
 * cached as the current version. The first flush stays, because Laravel ties
 * after-commit callbacks to the latest transaction on any connection, and a
 * rollback there would drop the only flush.
 */
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
        $layers = [];

        foreach ($this->manager->getLayers() as $layer) {
            if (in_array($model::class, $layer->invalidateOn)) {
                $layers[] = $layer->name;
            }
        }

        if ($layers === []) {
            return;
        }

        $flush = function () use ($layers) {
            foreach ($layers as $name) {
                $this->cache->flushLayer($name);
            }
        };

        $flush();

        $connection = $model->getConnection();

        if ($connection->transactionLevel() > 0) {
            $connection->afterCommit($flush);
        }
    }
}
