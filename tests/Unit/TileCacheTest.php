<?php

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use ItsJustVita\VectorTiles\Cache\TileCache;
use ItsJustVita\VectorTiles\Layer;

beforeEach(function () {
    config()->set('vector-tiles.cache.enabled', true);
    config()->set('vector-tiles.cache.store', null);
    config()->set('vector-tiles.cache.ttl', 3600);
});

function cacheUser(int $id): Authenticatable
{
    $user = Mockery::mock(Authenticatable::class);
    $user->shouldReceive('getAuthIdentifier')->andReturn($id);

    return $user;
}

it('builds versioned cache keys for public layers', function () {
    expect(app(TileCache::class)->buildKey(new Layer('trassen'), 14, 8532, 5765))
        ->toBe('vtiles:e0:trassen:v0:14:8532:5765');
});

it('appends a user hash for scoped layers', function () {
    $layer = (new Layer('trassen'))->scope(fn ($q, $u) => $q);

    expect(app(TileCache::class)->buildKey($layer, 14, 8532, 5765, cacheUser(42)))
        ->toBe('vtiles:e0:trassen:v0:14:8532:5765:u'.md5('42'));
});

it('appends a user hash for every authenticated request', function () {
    expect(app(TileCache::class)->buildKey(new Layer('trassen'), 14, 8532, 5765, cacheUser(42)))
        ->toBe('vtiles:e0:trassen:v0:14:8532:5765:u'.md5('42'));
});

it('puts and gets a tile from cache', function () {
    $layer = new Layer('trassen');
    $cache = app(TileCache::class);

    $cache->put($layer, 14, 8532, 5765, null, 'binary_data');

    expect($cache->get($layer, 14, 8532, 5765))->toBe('binary_data');
});

it('returns null for cache miss', function () {
    expect(app(TileCache::class)->get(new Layer('trassen'), 14, 8532, 5765))->toBeNull();
});

it('returns null when caching is disabled', function () {
    config()->set('vector-tiles.cache.enabled', false);

    $layer = new Layer('trassen');
    $cache = app(TileCache::class);

    $cache->put($layer, 14, 8532, 5765, null, 'binary_data');

    expect($cache->get($layer, 14, 8532, 5765))->toBeNull();
});

it('caches empty tiles as empty strings', function () {
    $layer = new Layer('trassen');
    $cache = app(TileCache::class);

    $cache->put($layer, 14, 8532, 5765, null, '');

    expect($cache->get($layer, 14, 8532, 5765))->toBe('');
});

it('flushes a layer by bumping its version', function () {
    $layer = new Layer('trassen');
    $other = new Layer('hausanschluesse');
    $cache = app(TileCache::class);

    $cache->put($layer, 14, 8532, 5765, null, 'tile_a');
    $cache->put($layer, 15, 17064, 11530, null, 'tile_b');
    $cache->put($other, 14, 8532, 5765, null, 'tile_other');

    $cache->flushLayer('trassen');

    expect($cache->get($layer, 14, 8532, 5765))->toBeNull()
        ->and($cache->get($layer, 15, 17064, 11530))->toBeNull()
        ->and($cache->get($other, 14, 8532, 5765))->toBe('tile_other')
        ->and($cache->buildKey($layer, 14, 8532, 5765))->toBe('vtiles:e0:trassen:v1:14:8532:5765');
});

it('flushes all layers by bumping the epoch', function () {
    $layerA = new Layer('trassen');
    $layerB = new Layer('hausanschluesse');
    $cache = app(TileCache::class);

    $cache->put($layerA, 14, 8532, 5765, null, 'tile_a');
    $cache->put($layerB, 14, 8532, 5765, null, 'tile_b');

    $cache->flushAll();

    expect($cache->get($layerA, 14, 8532, 5765))->toBeNull()
        ->and($cache->get($layerB, 14, 8532, 5765))->toBeNull()
        ->and($cache->buildKey($layerA, 14, 8532, 5765))->toBe('vtiles:e1:trassen:v0:14:8532:5765');
});

it('keeps no per-layer key registry', function () {
    app(TileCache::class)->put(new Layer('trassen'), 14, 8532, 5765, null, 'tile');

    expect(Cache::has('vtiles:keys:trassen'))->toBeFalse()
        ->and(Cache::has('vtiles:layers'))->toBeFalse();
});

it('separates cache entries by user', function () {
    $layer = (new Layer('trassen'))->scope(fn ($q, $u) => $q);
    $cache = app(TileCache::class);

    $cache->put($layer, 14, 8532, 5765, cacheUser(1), 'tile_for_a');
    $cache->put($layer, 14, 8532, 5765, cacheUser(2), 'tile_for_b');

    expect($cache->get($layer, 14, 8532, 5765, cacheUser(1)))->toBe('tile_for_a')
        ->and($cache->get($layer, 14, 8532, 5765, cacheUser(2)))->toBe('tile_for_b');
});

it('never shares scoped entries between guests and users', function () {
    $layer = (new Layer('trassen'))->scope(fn ($q, $u) => $q);
    $cache = app(TileCache::class);

    $cache->put($layer, 14, 8532, 5765, null, 'tile_for_guests');

    expect($cache->get($layer, 14, 8532, 5765, cacheUser(1)))->toBeNull();

    $cache->put($layer, 14, 8532, 5765, cacheUser(1), 'tile_for_user');

    expect($cache->get($layer, 14, 8532, 5765))->toBe('tile_for_guests');
});

it('invalidates with the database cache store, whose increment fails on missing keys', function () {
    Schema::create('cache', function (Blueprint $table) {
        $table->string('key')->primary();
        $table->mediumText('value');
        $table->integer('expiration');
    });

    config()->set('cache.stores.vt_database', [
        'driver' => 'database',
        'connection' => null,
        'table' => 'cache',
        'lock_connection' => null,
        'lock_table' => null,
    ]);
    config()->set('vector-tiles.cache.store', 'vt_database');

    $layer = new Layer('trassen');
    $cache = app(TileCache::class);

    $cache->put($layer, 14, 8532, 5765, null, 'tile_v0');
    $cache->flushLayer('trassen');

    expect($cache->get($layer, 14, 8532, 5765))->toBeNull();

    $cache->put($layer, 14, 8532, 5765, null, 'tile_v1');
    $cache->flushLayer('trassen');

    expect($cache->get($layer, 14, 8532, 5765))->toBeNull()
        ->and($cache->buildKey($layer, 14, 8532, 5765))->toBe('vtiles:e0:trassen:v2:14:8532:5765');
});
