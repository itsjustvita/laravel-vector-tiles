<?php

namespace ItsJustVita\VectorTiles;

use Closure;
use Illuminate\Database\Eloquent\Builder;

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

    public function invalidateOn(string $modelClass): static
    {
        $this->invalidateOn[] = $modelClass;
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
