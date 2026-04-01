<?php

namespace ItsJustVita\VectorTiles\Facades;

use Illuminate\Support\Facades\Facade;
use ItsJustVita\VectorTiles\VectorTileManager;

/**
 * @method static \ItsJustVita\VectorTiles\Layer layer(string $name)
 * @method static \ItsJustVita\VectorTiles\Layer|null getLayer(string $name)
 * @method static bool hasLayer(string $name)
 * @method static array getLayers()
 * @method static array|null maplibreSource(string $name)
 * @method static array maplibreSources()
 * @method static void fake()
 * @method static void assertTileRequested(string $layer, int $z, int $x, int $y)
 * @method static void assertTileNotRequested(string $layer, int $z, int $x, int $y)
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
