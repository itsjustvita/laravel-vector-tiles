<?php

namespace ItsJustVita\VectorTiles\Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;

class PassThroughMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }
}
