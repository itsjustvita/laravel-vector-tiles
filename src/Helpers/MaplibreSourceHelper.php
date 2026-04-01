<?php

namespace ItsJustVita\VectorTiles\Helpers;

use ItsJustVita\VectorTiles\Layer;

class MaplibreSourceHelper
{
    public static function sourceForLayer(Layer $layer): array
    {
        $prefix = config('vector-tiles.prefix', 'tiles');

        return [
            'type' => 'vector',
            'tiles' => [url("{$prefix}/{$layer->name}/{z}/{x}/{y}.mvt")],
            'minzoom' => $layer->minZoom,
            'maxzoom' => $layer->maxZoom,
        ];
    }
}
