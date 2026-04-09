<?php

namespace ItsJustVita\VectorTiles\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use ItsJustVita\VectorTiles\Layer;
use ItsJustVita\VectorTiles\VectorTileManager;

class TileJsonController extends Controller
{
    public function __invoke(VectorTileManager $manager, string $layer): JsonResponse
    {
        $layerConfig = $manager->getLayer($layer);

        if ($layerConfig === null) {
            abort(404, "Layer '{$layer}' not found.");
        }

        return response()->json($this->buildTileJson($layerConfig));
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildTileJson(Layer $layer): array
    {
        $prefix = config('vector-tiles.prefix', 'tiles');
        $tileUrl = url("{$prefix}/{$layer->name}/{z}/{x}/{y}.mvt");

        $fields = [];
        foreach ($layer->properties as $property) {
            $fields[$property] = 'String';
        }

        return [
            'tilejson' => '3.0.0',
            'name' => $layer->name,
            'scheme' => 'xyz',
            'tiles' => [$tileUrl],
            'minzoom' => $layer->minZoom,
            'maxzoom' => $layer->maxZoom,
            'bounds' => [-180.0, -85.05112877980659, 180.0, 85.05112877980659],
            'center' => [0.0, 0.0, $layer->minZoom],
            'vector_layers' => [
                [
                    'id' => $layer->name,
                    'minzoom' => $layer->minZoom,
                    'maxzoom' => $layer->maxZoom,
                    'fields' => (object) $fields,
                ],
            ],
        ];
    }
}
