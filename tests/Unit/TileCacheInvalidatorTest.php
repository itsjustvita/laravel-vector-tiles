<?php

use ItsJustVita\VectorTiles\Cache\TileCache;
use ItsJustVita\VectorTiles\Cache\TileCacheInvalidator;
use ItsJustVita\VectorTiles\Layer;
use ItsJustVita\VectorTiles\VectorTileManager;

it('flushes layer cache on model created event', function () {
    $cache = Mockery::mock(TileCache::class);
    $cache->shouldReceive('flushLayer')->with('trassen')->once();

    $manager = Mockery::mock(VectorTileManager::class);
    $manager->shouldReceive('getLayers')->andReturn([
        'trassen' => (new Layer('trassen'))->invalidateOn(FakeModel::class),
    ]);

    $invalidator = new TileCacheInvalidator($cache, $manager);
    $invalidator->created(new FakeModel());
});

it('flushes layer cache on model updated event', function () {
    $cache = Mockery::mock(TileCache::class);
    $cache->shouldReceive('flushLayer')->with('trassen')->once();

    $manager = Mockery::mock(VectorTileManager::class);
    $manager->shouldReceive('getLayers')->andReturn([
        'trassen' => (new Layer('trassen'))->invalidateOn(FakeModel::class),
    ]);

    $invalidator = new TileCacheInvalidator($cache, $manager);
    $invalidator->updated(new FakeModel());
});

it('flushes layer cache on model deleted event', function () {
    $cache = Mockery::mock(TileCache::class);
    $cache->shouldReceive('flushLayer')->with('trassen')->once();

    $manager = Mockery::mock(VectorTileManager::class);
    $manager->shouldReceive('getLayers')->andReturn([
        'trassen' => (new Layer('trassen'))->invalidateOn(FakeModel::class),
    ]);

    $invalidator = new TileCacheInvalidator($cache, $manager);
    $invalidator->deleted(new FakeModel());
});

it('does not flush unrelated layers', function () {
    $cache = Mockery::mock(TileCache::class);
    $cache->shouldNotReceive('flushLayer');

    $manager = Mockery::mock(VectorTileManager::class);
    $manager->shouldReceive('getLayers')->andReturn([
        'trassen' => (new Layer('trassen'))->invalidateOn(AnotherFakeModel::class),
    ]);

    $invalidator = new TileCacheInvalidator($cache, $manager);
    $invalidator->updated(new FakeModel());
});

it('flushes multiple layers for same model', function () {
    $cache = Mockery::mock(TileCache::class);
    $cache->shouldReceive('flushLayer')->with('trassen')->once();
    $cache->shouldReceive('flushLayer')->with('trassen_overview')->once();

    $manager = Mockery::mock(VectorTileManager::class);
    $manager->shouldReceive('getLayers')->andReturn([
        'trassen' => (new Layer('trassen'))->invalidateOn(FakeModel::class),
        'trassen_overview' => (new Layer('trassen_overview'))->invalidateOn(FakeModel::class),
    ]);

    $invalidator = new TileCacheInvalidator($cache, $manager);
    $invalidator->updated(new FakeModel());
});

it('flushes the layer cache when a model registered with invalidateOn changes', function () {
    \Illuminate\Support\Facades\Schema::create('fake_models', function (\Illuminate\Database\Schema\Blueprint $table) {
        $table->id();
        $table->timestamps();
    });

    $layer = \ItsJustVita\VectorTiles\Facades\VectorTiles::layer('trassen')
        ->from('trassen_table')
        ->invalidateOn(FakeModel::class);

    $cache = app(TileCache::class);
    $cache->put($layer, 14, 8532, 5765, null, 'stale_tile');

    (new FakeModel)->save();

    expect($cache->get($layer, 14, 8532, 5765))->toBeNull();
});

class FakeModel extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'fake_models';
}

class AnotherFakeModel extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'another_fake_models';
}
