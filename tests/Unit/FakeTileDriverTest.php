<?php

use ItsJustVita\VectorTiles\Facades\VectorTiles;
use ItsJustVita\VectorTiles\Testing\FakeTileDriver;

beforeEach(function () {
    VectorTiles::layer('trassen')
        ->from('trassen_table')
        ->properties(['id']);
});

it('fakes the tile driver', function () {
    VectorTiles::fake();

    $driver = app(\ItsJustVita\VectorTiles\Drivers\TileDriverInterface::class);

    expect($driver)->toBeInstanceOf(FakeTileDriver::class);
});

it('returns predefined tile data from fake', function () {
    VectorTiles::fake();

    $response = $this->get('/tiles/trassen/14/8532/5765.mvt');

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.mapbox-vector-tile');
});

it('asserts tile was requested', function () {
    VectorTiles::fake();

    $this->get('/tiles/trassen/14/8532/5765.mvt');

    VectorTiles::assertTileRequested('trassen', 14, 8532, 5765);
});

it('asserts tile was not requested', function () {
    VectorTiles::fake();

    VectorTiles::assertTileNotRequested('trassen', 14, 8532, 5765);
});

it('fails assertion when tile was not requested', function () {
    VectorTiles::fake();

    try {
        VectorTiles::assertTileRequested('trassen', 14, 8532, 5765);
        $this->fail('Expected assertion to fail');
    } catch (\PHPUnit\Framework\ExpectationFailedException) {
        expect(true)->toBeTrue();
    }
});
