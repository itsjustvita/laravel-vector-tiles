<?php

namespace ItsJustVita\VectorTiles\Tests\Support;

use Generator;
use RuntimeException;

/**
 * Minimal Mapbox Vector Tile decoder for test assertions.
 *
 * Decodes layer names, extents, property keys and feature types.
 * Geometry coordinates and property values are not decoded.
 */
final class MvtDecoder
{
    /**
     * @return array<string, array{name: string, extent: int, keys: list<string>, features: list<array{id: ?int, type: int}>}>
     */
    public static function decode(string $bytes): array
    {
        $layers = [];

        foreach (self::fields($bytes) as [$field, $value]) {
            if ($field === 3) {
                $layer = self::decodeLayer($value);
                $layers[$layer['name']] = $layer;
            }
        }

        return $layers;
    }

    /**
     * @return array{name: string, extent: int, keys: list<string>, features: list<array{id: ?int, type: int}>}
     */
    private static function decodeLayer(string $bytes): array
    {
        $layer = ['name' => '', 'extent' => 4096, 'keys' => [], 'features' => []];

        foreach (self::fields($bytes) as [$field, $value]) {
            match ($field) {
                1 => $layer['name'] = $value,
                2 => $layer['features'][] = self::decodeFeature($value),
                3 => $layer['keys'][] = $value,
                5 => $layer['extent'] = $value,
                default => null,
            };
        }

        return $layer;
    }

    /**
     * @return array{id: ?int, type: int}
     */
    private static function decodeFeature(string $bytes): array
    {
        $feature = ['id' => null, 'type' => 0];

        foreach (self::fields($bytes) as [$field, $value]) {
            match ($field) {
                1 => $feature['id'] = $value,
                3 => $feature['type'] = $value,
                default => null,
            };
        }

        return $feature;
    }

    /**
     * @return Generator<int, array{0: int, 1: int|string}>
     */
    private static function fields(string $bytes): Generator
    {
        $offset = 0;
        $length = strlen($bytes);

        while ($offset < $length) {
            $tag = self::varint($bytes, $offset);
            $field = $tag >> 3;
            $wireType = $tag & 0x7;

            yield match ($wireType) {
                0 => [$field, self::varint($bytes, $offset)],
                1 => [$field, self::take($bytes, $offset, 8)],
                2 => [$field, self::take($bytes, $offset, self::varint($bytes, $offset))],
                5 => [$field, self::take($bytes, $offset, 4)],
                default => throw new RuntimeException("Unsupported protobuf wire type {$wireType}."),
            };
        }
    }

    private static function varint(string $bytes, int &$offset): int
    {
        $result = 0;
        $shift = 0;

        do {
            $byte = ord($bytes[$offset++]);
            $result |= ($byte & 0x7F) << $shift;
            $shift += 7;
        } while ($byte & 0x80);

        return $result;
    }

    private static function take(string $bytes, int &$offset, int $length): string
    {
        $value = substr($bytes, $offset, $length);
        $offset += $length;

        return $value;
    }
}
