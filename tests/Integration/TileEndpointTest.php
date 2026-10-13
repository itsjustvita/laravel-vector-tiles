<?php

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use ItsJustVita\VectorTiles\Cache\TileCache;
use ItsJustVita\VectorTiles\Facades\VectorTiles;
use ItsJustVita\VectorTiles\Tests\Support\MvtDecoder;

beforeEach(function () {
    [$z, $x, $y] = $this->sampleTile();
    $this->createPoints('vt_points', [$this->lonLatInTile($z, $x, $y)]);
    VectorTiles::layer('points')->from('vt_points')->properties(['id', 'name']);

    $this->tileUrl = "/tiles/points/{$z}/{$x}/{$y}.mvt";
});

it('serves a decodable tile over http', function () {
    $response = $this->get($this->tileUrl)
        ->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.mapbox-vector-tile');

    expect(MvtDecoder::decode($response->getContent())['points']['features'])->toHaveCount(1);
});

it('returns 204 for an area without features', function () {
    $this->get('/tiles/points/14/0/0.mvt')->assertNoContent();
});

it('serves repeated requests from the cache until the layer is flushed', function () {
    $tileQueries = 0;
    DB::listen(function (QueryExecuted $query) use (&$tileQueries) {
        if (str_contains($query->sql, 'ST_AsMVT(')) {
            $tileQueries++;
        }
    });

    $this->get($this->tileUrl)->assertOk();
    $this->get($this->tileUrl)->assertOk();

    expect($tileQueries)->toBe(1);

    app(TileCache::class)->flushLayer('points');
    $this->get($this->tileUrl)->assertOk();

    expect($tileQueries)->toBe(2);
});
