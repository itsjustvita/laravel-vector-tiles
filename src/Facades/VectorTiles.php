<?php

namespace ItsJustVita\VectorTiles\Facades;

use Illuminate\Support\Facades\Facade;
use ItsJustVita\VectorTiles\VectorTileManager;

/**
 * @method static \ItsJustVita\VectorTiles\Layer layer(string $name)
 * @method static \ItsJustVita\VectorTiles\Layer|null getLayer(string $name)
 * @method static bool hasLayer(string $name)
 * @method static array getLayers()
 *
 * @see \ItsJustVita\VectorTiles\VectorTileManager
 */
class VectorTiles extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return VectorTileManager::class;
    }
}
