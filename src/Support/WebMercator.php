<?php

namespace ItsJustVita\VectorTiles\Support;

final class WebMercator
{
    /** Half the width of the world in EPSG:3857 meters. */
    public const float MAX_EXTENT = 20037508.342789244;

    /** Latitude at which EPSG:3857 reaches MAX_EXTENT. */
    public const float MAX_LATITUDE = 85.0511287798066;

    /**
     * Bounds of tile z/x/y in EPSG:3857, expanded by $margin tile widths on
     * every side and clamped to the world so they never wrap around.
     *
     * @return array{0: float, 1: float, 2: float, 3: float} minX, minY, maxX, maxY
     */
    public static function tileBounds(int $z, int $x, int $y, float $margin = 0.0): array
    {
        $size = 2 * self::MAX_EXTENT / (2 ** $z);
        $pad = $size * $margin;

        return [
            max(-self::MAX_EXTENT + $x * $size - $pad, -self::MAX_EXTENT),
            max(self::MAX_EXTENT - ($y + 1) * $size - $pad, -self::MAX_EXTENT),
            min(-self::MAX_EXTENT + ($x + 1) * $size + $pad, self::MAX_EXTENT),
            min(self::MAX_EXTENT - $y * $size + $pad, self::MAX_EXTENT),
        ];
    }
}
