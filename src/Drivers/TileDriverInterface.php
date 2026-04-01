<?php

namespace ItsJustVita\VectorTiles\Drivers;

use Illuminate\Contracts\Auth\Authenticatable;
use ItsJustVita\VectorTiles\Layer;

interface TileDriverInterface
{
    public function getTile(Layer $layer, int $z, int $x, int $y, ?Authenticatable $user = null): ?string;
}
