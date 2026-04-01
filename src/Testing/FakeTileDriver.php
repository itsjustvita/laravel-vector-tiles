<?php

namespace ItsJustVita\VectorTiles\Testing;

use Illuminate\Contracts\Auth\Authenticatable;
use ItsJustVita\VectorTiles\Drivers\TileDriverInterface;
use ItsJustVita\VectorTiles\Layer;
use PHPUnit\Framework\Assert;

class FakeTileDriver implements TileDriverInterface
{
    /** @var array<int, array{layer: string, z: int, x: int, y: int}> */
    protected array $requests = [];

    public function getTile(Layer $layer, int $z, int $x, int $y, ?Authenticatable $user = null): ?string
    {
        $this->requests[] = [
            'layer' => $layer->name,
            'z' => $z,
            'x' => $x,
            'y' => $y,
        ];

        return 'fake_mvt_tile';
    }

    public function assertTileRequested(string $layer, int $z, int $x, int $y): void
    {
        $match = collect($this->requests)->first(
            fn (array $r) => $r['layer'] === $layer
                && $r['z'] === $z
                && $r['x'] === $x
                && $r['y'] === $y,
        );

        Assert::assertNotNull(
            $match,
            "Expected tile request for {$layer}/{$z}/{$x}/{$y} was not made.",
        );
    }

    public function assertTileNotRequested(string $layer, int $z, int $x, int $y): void
    {
        $match = collect($this->requests)->first(
            fn (array $r) => $r['layer'] === $layer
                && $r['z'] === $z
                && $r['x'] === $x
                && $r['y'] === $y,
        );

        Assert::assertNull(
            $match,
            "Unexpected tile request for {$layer}/{$z}/{$x}/{$y} was made.",
        );
    }
}
