<?php

namespace ItsJustVita\VectorTiles\Http;

class Throttle
{
    /**
     * Route middleware for a throttle setting: requests and minutes such as
     * "60,1", the name of a rate limiter, or null to turn throttling off.
     *
     * Requests and minutes get $prefix as their key prefix, so the routes keep
     * their own request count instead of sharing it with every other route of
     * the application that uses the same plain throttle middleware.
     *
     * @return list<string>
     */
    public static function middleware(mixed $value, string $prefix): array
    {
        if ($value === null || $value === false || $value === '') {
            return [];
        }

        if (preg_match('/^\s*(\d+)\s*(?:,\s*(\d+)\s*)?$/', (string) $value, $matches)) {
            $minutes = $matches[2] ?? 1;

            return ["throttle:{$matches[1]},{$minutes},{$prefix}"];
        }

        return ["throttle:{$value}"];
    }
}
