<?php

use Illuminate\Support\Facades\DB;
use ItsJustVita\VectorTiles\Tests\Support\MvtDecoder;

it('decodes a tile produced directly by postgis', function () {
    $row = DB::selectOne(<<<'SQL'
        SELECT ST_AsMVT(t, 'probe', 512, 'g') AS tile FROM (
            SELECT 7 AS id, 'a' AS name,
                   ST_AsMVTGeom(
                       ST_SetSRID(ST_MakePoint(100, 100), 3857),
                       ST_MakeEnvelope(0, 0, 1000, 1000, 3857)::box2d,
                       512
                   ) AS g
        ) AS t
    SQL);

    $layers = MvtDecoder::decode(stream_get_contents($row->tile));

    expect($layers)->toHaveKey('probe')
        ->and($layers['probe']['extent'])->toBe(512)
        ->and($layers['probe']['keys'])->toEqualCanonicalizing(['id', 'name'])
        ->and($layers['probe']['features'])->toHaveCount(1)
        ->and($layers['probe']['features'][0]['type'])->toBe(1);
});
