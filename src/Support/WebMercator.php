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
        $size = self::tileWidth($z);
        $pad = $size * $margin;

        return [
            max(-self::MAX_EXTENT + $x * $size - $pad, -self::MAX_EXTENT),
            max(self::MAX_EXTENT - ($y + 1) * $size - $pad, -self::MAX_EXTENT),
            min(-self::MAX_EXTENT + ($x + 1) * $size + $pad, self::MAX_EXTENT),
            min(self::MAX_EXTENT - $y * $size + $pad, self::MAX_EXTENT),
        ];
    }

    /**
     * Width of a tile at zoom $z in EPSG:3857 meters.
     */
    public static function tileWidth(int $z): float
    {
        return 2 * self::MAX_EXTENT / (2 ** $z);
    }

    /**
     * The tile containing a WGS84 position. Positions beyond the Web Mercator
     * latitude limit or on the antimeridian map to the nearest edge tile.
     *
     * @return array{0: int, 1: int} x, y
     */
    public static function tileAt(float $lon, float $lat, int $z): array
    {
        $n = 2 ** $z;
        $latRad = deg2rad(max(-self::MAX_LATITUDE, min(self::MAX_LATITUDE, $lat)));

        $x = (int) floor(($lon + 180) / 360 * $n);
        $y = (int) floor((1 - log(tan($latRad) + 1 / cos($latRad)) / M_PI) / 2 * $n);

        return [max(0, min($n - 1, $x)), max(0, min($n - 1, $y))];
    }
}
