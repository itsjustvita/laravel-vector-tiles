<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;
use ItsJustVita\VectorTiles\Cache\TileCache;
use ItsJustVita\VectorTiles\Layer;

beforeEach(function () {
    config()->set('vector-tiles.cache.enabled', true);
    config()->set('vector-tiles.cache.store', null);
    config()->set('vector-tiles.cache.ttl', 3600);
});

it('generates cache key without scope hash for public layer', function () {
    $layer = new Layer('trassen');

    $cache = app(TileCache::class);
    $key = $cache->buildKey($layer, 14, 8532, 5765);

    expect($key)->toBe('vtiles:trassen:14:8532:5765');
});

it('generates cache key with scope hash for scoped layer', function () {
    $layer = (new Layer('trassen'))
        ->scope(fn ($q, $u) => $q);

    $user = Mockery::mock(Authenticatable::class);
    $user->shouldReceive('getAuthIdentifier')->andReturn(42);

    $cache = app(TileCache::class);
    $key = $cache->buildKey($layer, 14, 8532, 5765, $user);

    expect($key)->toBe('vtiles:trassen:14:8532:5765:' . md5('42'));
});

it('puts and gets a tile from cache', function () {
    $layer = new Layer('trassen');
    $cache = app(TileCache::class);

    $cache->put($layer, 14, 8532, 5765, null, 'binary_data');
    $result = $cache->get($layer, 14, 8532, 5765);

    expect($result)->toBe('binary_data');
});

it('returns null for cache miss', function () {
    $layer = new Layer('trassen');
    $cache = app(TileCache::class);

    $result = $cache->get($layer, 14, 8532, 5765);

    expect($result)->toBeNull();
});

it('returns null when caching is disabled', function () {
    config()->set('vector-tiles.cache.enabled', false);

    $layer = new Layer('trassen');
    $cache = app(TileCache::class);

    $cache->put($layer, 14, 8532, 5765, null, 'binary_data');
    $result = $cache->get($layer, 14, 8532, 5765);

    expect($result)->toBeNull();
});

it('flushes all tiles for a layer', function () {
    $layer = new Layer('trassen');
    $cache = app(TileCache::class);

    $cache->put($layer, 14, 8532, 5765, null, 'tile_a');
    $cache->put($layer, 15, 17064, 11530, null, 'tile_b');

    $cache->flushLayer('trassen');

    expect($cache->get($layer, 14, 8532, 5765))->toBeNull()
        ->and($cache->get($layer, 15, 17064, 11530))->toBeNull();
});

it('flushes all tiles', function () {
    $layerA = new Layer('trassen');
    $layerB = new Layer('hausanschluesse');
    $cache = app(TileCache::class);

    $cache->put($layerA, 14, 8532, 5765, null, 'tile_a');
    $cache->put($layerB, 14, 8532, 5765, null, 'tile_b');

    $cache->flushAll();

    expect($cache->get($layerA, 14, 8532, 5765))->toBeNull()
        ->and($cache->get($layerB, 14, 8532, 5765))->toBeNull();
});

it('separates cache entries by user', function () {
    $layer = (new Layer('trassen'))
        ->scope(fn ($q, $u) => $q);

    $userA = Mockery::mock(Authenticatable::class);
    $userA->shouldReceive('getAuthIdentifier')->andReturn(1);

    $userB = Mockery::mock(Authenticatable::class);
    $userB->shouldReceive('getAuthIdentifier')->andReturn(2);

    $cache = app(TileCache::class);

    $cache->put($layer, 14, 8532, 5765, $userA, 'tile_for_a');
    $cache->put($layer, 14, 8532, 5765, $userB, 'tile_for_b');

    expect($cache->get($layer, 14, 8532, 5765, $userA))->toBe('tile_for_a')
        ->and($cache->get($layer, 14, 8532, 5765, $userB))->toBe('tile_for_b');
});
