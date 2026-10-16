<?php

namespace ItsJustVita\VectorTiles\Tests\Fixtures;

use Closure;
use Illuminate\Http\Request;

class SetsOriginMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        $response = $next($request);
        $response->headers->set('Access-Control-Allow-Origin', 'https://app.example.com');

        return $response;
    }
}
