<?php

namespace ItsJustVita\VectorTiles\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use ItsJustVita\VectorTiles\Cache\TileCache;
use ItsJustVita\VectorTiles\Drivers\TileDriverInterface;
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

        $cached = $cache->get($layerConfig, $z, $x, $y, $user);
        if ($cached !== null) {
            return $this->tileResponse($cached);
        }

        $tile = $driver->getTile($layerConfig, $z, $x, $y, $user);

        if ($tile === null) {
            return new Response('', 204);
        }

        $cache->put($layerConfig, $z, $x, $y, $user, $tile);

        return $this->tileResponse($tile);
    }

    protected function tileResponse(string $tile): Response
    {
        $ttl = (int) config('vector-tiles.cache.ttl', 3600);

        $headers = [
            'Content-Type' => 'application/vnd.mapbox-vector-tile',
            'Content-Encoding' => 'identity',
            'Cache-Control' => "public, max-age={$ttl}",
        ];

        $corsOrigin = config('vector-tiles.cors.allowed_origins');
        if ($corsOrigin !== null && $corsOrigin !== '') {
            $headers['Access-Control-Allow-Origin'] = $corsOrigin;
        }

        return new Response($tile, 200, $headers);
    }
}
