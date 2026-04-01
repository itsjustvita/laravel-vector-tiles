<?php

namespace ItsJustVita\VectorTiles;

use Illuminate\Support\ServiceProvider;
use ItsJustVita\VectorTiles\Cache\TileCache;
use ItsJustVita\VectorTiles\Cache\TileCacheInvalidator;
use ItsJustVita\VectorTiles\Drivers\PostgisTileDriver;
use ItsJustVita\VectorTiles\Drivers\TileDriverInterface;

class VectorTilesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/vector-tiles.php', 'vector-tiles');

        $this->app->singleton(VectorTileManager::class, function ($app) {
            return new VectorTileManager($app);
        });

        $this->app->singleton(TileDriverInterface::class, PostgisTileDriver::class);
        $this->app->singleton(TileCache::class);
        $this->app->singleton(TileCacheInvalidator::class);
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/vector-tiles.php' => config_path('vector-tiles.php'),
            ], 'vector-tiles-config');

            $this->commands([
                Commands\InstallCommand::class,
            ]);
        }

        $this->loadRoutesFrom(__DIR__ . '/../routes/tiles.php');

        $this->app->make(VectorTileManager::class)->loadConfigLayers();
    }
}
