<?php

namespace ItsJustVita\VectorTiles\Support;

use Illuminate\Support\Str;
use Throwable;

final class ErrorMessage
{
    /**
     * The first line of an error message, without the SQLSTATE prefix and
     * the connection details and SQL statement Laravel appends.
     */
    public static function reason(Throwable $e): string
    {
        $line = Str::before(Str::before($e->getMessage(), "\n"), ' (Connection: ');

        return preg_replace('/^SQLSTATE\[\w+\]: .+?: \d+ ERROR:\s+/', '', $line) ?? $line;
    }
}
