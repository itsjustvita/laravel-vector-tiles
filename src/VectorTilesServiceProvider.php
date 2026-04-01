<?php

namespace ItsJustVita\VectorTiles;

use Illuminate\Support\ServiceProvider;

class VectorTilesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/vector-tiles.php', 'vector-tiles');
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/vector-tiles.php' => config_path('vector-tiles.php'),
            ], 'vector-tiles-config');
        }
    }
}
