<?php

namespace ItsJustVita\VectorTiles\Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;

class RejectingMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        return response()->json(['error' => 'Unauthorized'], 401);
    }
}
