<?php

namespace ItsJustVita\VectorTiles\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends cors.allowed_origins as Access-Control-Allow-Origin on every response
 * of the package routes, including errors, so browsers on other origins can
 * read status codes such as 404 or 429. An origin set by other middleware is
 * kept.
 */
class AddCorsHeader
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $origin = config('vector-tiles.cors.allowed_origins');

        if (! in_array($origin, [null, '', false], true)
            && ! $response->headers->has('Access-Control-Allow-Origin')) {
            $response->headers->set('Access-Control-Allow-Origin', $origin);
        }

        return $response;
    }
}
