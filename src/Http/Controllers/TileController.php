<?php

namespace ItsJustVita\VectorTiles\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use ItsJustVita\VectorTiles\Cache\TileCache;
use ItsJustVita\VectorTiles\Drivers\TileDriverInterface;
use ItsJustVita\VectorTiles\Layer;
use ItsJustVita\VectorTiles\VectorTileManager;

class TileController extends Controller
{
    public function __invoke(
        Request $request,
        TileDriverInterface $driver,
        TileCache $cache,
        VectorTileManager $manager,
        string $layer,
        int $z,
        int $x,
        int $y,
    ): Response {
        $layerConfig = $manager->getLayer($layer);
        $user = $request->user();

        $tile = $cache->remember(
            $layerConfig, $z, $x, $y, $user,
            fn () => (string) $driver->getTile($layerConfig, $z, $x, $y, $user),
        );

        return $this->tileResponse($layerConfig, $tile);
    }

    protected function tileResponse(Layer $layer, string $tile): Response
    {
        $ttl = (int) config('vector-tiles.cache.ttl', 3600);
        $visibility = $this->isPrivate($layer) ? 'private' : 'public';

        $headers = [
            'Content-Type' => 'application/vnd.mapbox-vector-tile',
            'Cache-Control' => "{$visibility}, max-age={$ttl}",
        ];

        $corsOrigin = config('vector-tiles.cors.allowed_origins');
        if ($corsOrigin !== null && $corsOrigin !== '') {
            $headers['Access-Control-Allow-Origin'] = $corsOrigin;
        }

        if ($tile === '') {
            return new Response('', 204, $headers);
        }

        return new Response($tile, 200, $headers);
    }

    /**
     * Tiles that may depend on who asks must never be stored by shared caches.
     */
    protected function isPrivate(Layer $layer): bool
    {
        return ! empty($layer->middleware)
            || $layer->scope !== null
            || ! empty(config('vector-tiles.middleware', []));
    }
}
