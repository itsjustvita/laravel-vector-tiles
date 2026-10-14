<?php

namespace ItsJustVita\VectorTiles;

use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;
use ItsJustVita\VectorTiles\Cache\TileCacheInvalidator;

class Layer
{
    public string|Builder|null $source = null;
    public string $geometry = 'geom';
    public int $srid = 4326;
    public array $properties = [];
    public int $minZoom = 0;
    public int $maxZoom = 22;
    public array $middleware = [];
    public ?Closure $scope = null;
    public array $invalidateOn = [];
    public int $extent = 4096;
    public int $buffer = 256;
    public ?string $connection = null;
    public ?float $simplify = null;
    public ?Closure $zoomFilter = null;

    public function __construct(
        public readonly string $name,
    ) {}

    public static function fromConfig(string $name, array $config): static
    {
        $layer = new static($name);
        $layer->source = $config['table'] ?? null;
        $layer->geometry = $config['geometry'] ?? 'geom';
        $layer->srid = $config['srid'] ?? 4326;
        $layer->properties = $config['properties'] ?? [];
        $layer->minZoom = $config['min_zoom'] ?? 0;
        $layer->maxZoom = $config['max_zoom'] ?? 22;
        $layer->middleware = $config['middleware'] ?? [];
        $layer->extent = $config['extent'] ?? 4096;
        $layer->buffer = $config['buffer'] ?? 256;
        $layer->connection = $config['connection'] ?? null;

        if (! empty($config['simplify'])) {
            $layer->simplify((float) $config['simplify']);
        }

        return $layer;
    }

    public function from(string|Builder $source): static
    {
        $this->source = $source;
        return $this;
    }

    public function geometry(string $column): static
    {
        $this->geometry = $column;
        return $this;
    }

    public function srid(int $srid): static
    {
        $this->srid = $srid;
        return $this;
    }

    public function properties(array $properties): static
    {
        $this->properties = $properties;
        return $this;
    }

    public function minZoom(int $zoom): static
    {
        $this->minZoom = $zoom;
        return $this;
    }

    public function maxZoom(int $zoom): static
    {
        $this->maxZoom = $zoom;
        return $this;
    }

    public function middleware(array $middleware): static
    {
        $this->middleware = $middleware;
        return $this;
    }

    public function scope(Closure $scope): static
    {
        $this->scope = $scope;
        return $this;
    }

    /**
     * Simplify geometries below the layer's maxZoom. The tolerance is given in
     * screen pixels of a 512 px tile, so it scales with the zoom level.
     */
    public function simplify(float $pixels): static
    {
        if ($pixels <= 0) {
            throw new InvalidArgumentException("The simplify tolerance must be greater than 0, {$pixels} given.");
        }

        $this->simplify = $pixels;
        return $this;
    }

    /**
     * Constrain the tile query per zoom level. The callback receives the query
     * builder and the zoom level. GeoJSON requests have no zoom level, so the
     * filter does not apply there.
     */
    public function filterByZoom(Closure $callback): static
    {
        $this->zoomFilter = $callback;
        return $this;
    }

    public function invalidateOn(string $modelClass): static
    {
        if (in_array($modelClass, $this->invalidateOn, true)) {
            return $this;
        }

        $this->invalidateOn[] = $modelClass;

        if (is_subclass_of($modelClass, Model::class)) {
            $modelClass::observe(TileCacheInvalidator::class);
        }

        return $this;
    }

    public function extent(int $extent): static
    {
        $this->extent = $extent;
        return $this;
    }

    public function buffer(int $buffer): static
    {
        $this->buffer = $buffer;
        return $this;
    }

    public function connection(string $connection): static
    {
        $this->connection = $connection;
        return $this;
    }

    public function getTable(): string
    {
        if (is_string($this->source)) {
            return $this->source;
        }

        if ($this->source instanceof Builder) {
            return $this->source->toBase()->from;
        }

        return $this->name;
    }
}
