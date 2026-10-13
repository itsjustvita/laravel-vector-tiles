<?php

namespace ItsJustVita\VectorTiles\Benchmark;

use InvalidArgumentException;
use RuntimeException;

/**
 * Plain text tile lists with one z/x/y per line, so benchmarks can be
 * replayed and other tools can request the same tiles.
 */
final class TileListFile
{
    /**
     * @return list<array{0: int, 1: int, 2: int}>
     *
     * @throws InvalidArgumentException
     */
    public static function read(string $path): array
    {
        $contents = is_file($path) ? file_get_contents($path) : false;

        if ($contents === false) {
            throw new InvalidArgumentException("Tile list {$path} cannot be read.");
        }

        $tiles = [];

        foreach (preg_split('/\R/', $contents) as $index => $line) {
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            if (! preg_match('#^(\d{1,2})/(\d+)/(\d+)$#', $line, $match)) {
                throw new InvalidArgumentException('Invalid tile on line '.($index + 1).": {$line}");
            }

            [$z, $x, $y] = [(int) $match[1], (int) $match[2], (int) $match[3]];

            if ($z > 30 || $x >= 2 ** $z || $y >= 2 ** $z) {
                throw new InvalidArgumentException('Invalid tile on line '.($index + 1).": {$line}");
            }

            $tiles[] = [$z, $x, $y];
        }

        return $tiles;
    }

    /**
     * @param  list<array{0: int, 1: int, 2: int}>  $tiles
     */
    public static function write(string $path, array $tiles): void
    {
        $lines = array_map(fn (array $tile) => implode('/', $tile)."\n", $tiles);

        if (file_put_contents($path, implode('', $lines)) === false) {
            throw new RuntimeException("Tile list {$path} cannot be written.");
        }
    }
}
