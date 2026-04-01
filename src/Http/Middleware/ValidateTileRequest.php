<?php

namespace ItsJustVita\VectorTiles\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use ItsJustVita\VectorTiles\VectorTileManager;
use Symfony\Component\HttpFoundation\Response;

class ValidateTileRequest
{
    public function __construct(
        protected VectorTileManager $manager,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $layerName = $request->route('layer');
        $z = (int) $request->route('z');
        $x = (int) $request->route('x');
        $y = (int) $request->route('y');

        $layer = $this->manager->getLayer($layerName);
        if ($layer === null) {
            abort(404, "Layer '{$layerName}' not found.");
        }

        if ($z < $layer->minZoom || $z > $layer->maxZoom) {
            abort(422, "Zoom level {$z} is outside bounds [{$layer->minZoom}, {$layer->maxZoom}].");
        }

        $maxTile = (1 << $z) - 1;
        if ($x < 0 || $x > $maxTile) {
            abort(422, "X coordinate {$x} is outside bounds [0, {$maxTile}] for zoom {$z}.");
        }
        if ($y < 0 || $y > $maxTile) {
            abort(422, "Y coordinate {$y} is outside bounds [0, {$maxTile}] for zoom {$z}.");
        }

        if (! empty($layer->middleware)) {
            return app(Pipeline::class)
                ->send($request)
                ->through($layer->middleware)
                ->then(fn ($req) => $next($req));
        }

        return $next($request);
    }
}
