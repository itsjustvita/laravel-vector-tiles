<?php

namespace ItsJustVita\VectorTiles\Cache;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use ItsJustVita\VectorTiles\Layer;

class TileCache
{
    /**
     * Lifetime of the version counters. A counter that expires restarts at 0,
     * which is safe as long as cached tiles expire sooner (cache.ttl).
     */
    protected const int COUNTER_TTL = 31536000;

    /**
     * Returns null on a cache miss and an empty string for a cached empty tile.
     */
    public function get(Layer $layer, int $z, int $x, int $y, ?Authenticatable $user = null): ?string
    {
        if (! $this->isEnabled()) {
            return null;
        }

        return $this->store()->get($this->buildKey($layer, $z, $x, $y, $user));
    }

    public function put(Layer $layer, int $z, int $x, int $y, ?Authenticatable $user, string $tile): void
    {
        if (! $this->isEnabled()) {
            return;
        }

        $ttl = (int) config('vector-tiles.cache.ttl', 3600);

        $this->store()->put($this->buildKey($layer, $z, $x, $y, $user), $tile, $ttl);
    }

    /**
     * Return the cached tile, or render and cache it. The key is computed once,
     * so a flush during rendering stores the tile under the old version, where
     * it is never read again.
     *
     * @param  Closure(): string  $render
     */
    public function remember(Layer $layer, int $z, int $x, int $y, ?Authenticatable $user, Closure $render): string
    {
        if (! $this->isEnabled()) {
            return $render();
        }

        $ttl = (int) config('vector-tiles.cache.ttl', 3600);

        return $this->store()->remember($this->buildKey($layer, $z, $x, $y, $user), $ttl, $render);
    }

    public function flushLayer(string $layerName): void
    {
        $this->bump("vtiles:version:{$layerName}");
    }

    public function flushAll(): void
    {
        $this->bump('vtiles:epoch');
    }

    public function buildKey(Layer $layer, int $z, int $x, int $y, ?Authenticatable $user = null): string
    {
        $versionKey = "vtiles:version:{$layer->name}";
        $counters = $this->store()->many(['vtiles:epoch', $versionKey]);

        $epoch = (int) ($counters['vtiles:epoch'] ?? 0);
        $version = (int) ($counters[$versionKey] ?? 0);

        $key = "vtiles:e{$epoch}:{$layer->name}:v{$version}:{$z}:{$x}:{$y}";

        // Tiles may depend on the user through scopes, global scopes or policies.
        if ($user !== null) {
            $key .= ':u'.md5((string) $user->getAuthIdentifier());
        }

        return $key;
    }

    /**
     * Atomically increments a counter. The add() with a TTL is atomic in every
     * store and initializes the counter, because some stores (e.g. database)
     * cannot increment a missing key.
     */
    protected function bump(string $key): void
    {
        $store = $this->store();

        $store->add($key, 0, self::COUNTER_TTL);
        $store->increment($key);
    }

    protected function isEnabled(): bool
    {
        return (bool) config('vector-tiles.cache.enabled', true);
    }

    protected function store(): Repository
    {
        return Cache::store(config('vector-tiles.cache.store'));
    }
}
