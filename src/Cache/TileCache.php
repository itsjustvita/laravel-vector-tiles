<?php

namespace ItsJustVita\VectorTiles\Cache;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Cache\Repository;
use Illuminate\Support\Facades\Cache;
use ItsJustVita\VectorTiles\Layer;

class TileCache
{
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

        $key = $this->buildKey($layer, $z, $x, $y, $user);
        $ttl = config('vector-tiles.cache.ttl', 3600);

        $this->store()->put($key, $tile, $ttl);
        $this->trackKey($layer->name, $key);
    }

    public function flushLayer(string $layerName): void
    {
        $store = $this->store();
        $registryKey = "vtiles:keys:{$layerName}";
        $keys = $store->get($registryKey, []);

        foreach ($keys as $key) {
            $store->forget($key);
        }

        $store->forget($registryKey);
    }

    public function flushAll(): void
    {
        $store = $this->store();
        $layersKey = 'vtiles:layers';
        $layerNames = $store->get($layersKey, []);

        foreach ($layerNames as $layerName) {
            $this->flushLayer($layerName);
        }

        $store->forget($layersKey);
    }

    public function buildKey(Layer $layer, int $z, int $x, int $y, ?Authenticatable $user = null): string
    {
        $key = "vtiles:{$layer->name}:{$z}:{$x}:{$y}";

        if ($layer->scope !== null && $user !== null) {
            $key .= ':' . md5((string) $user->getAuthIdentifier());
        }

        return $key;
    }

    protected function trackKey(string $layerName, string $key): void
    {
        $store = $this->store();

        // Track key for this layer
        $registryKey = "vtiles:keys:{$layerName}";
        $keys = $store->get($registryKey, []);
        if (! in_array($key, $keys)) {
            $keys[] = $key;
            $store->put($registryKey, $keys, 86400 * 7);
        }

        // Track layer name globally
        $layersKey = 'vtiles:layers';
        $layerNames = $store->get($layersKey, []);
        if (! in_array($layerName, $layerNames)) {
            $layerNames[] = $layerName;
            $store->put($layersKey, $layerNames, 86400 * 7);
        }
    }

    protected function isEnabled(): bool
    {
        return (bool) config('vector-tiles.cache.enabled', true);
    }

    protected function store(): Repository
    {
        $storeName = config('vector-tiles.cache.store');

        return Cache::store($storeName);
    }
}
