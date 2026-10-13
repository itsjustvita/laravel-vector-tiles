<?php

namespace ItsJustVita\VectorTiles\Http\Concerns;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Router;
use ItsJustVita\VectorTiles\Layer;

trait RunsLayerMiddleware
{
    /**
     * Send the request through the layer's middleware, resolving aliases and
     * parameters like route middleware, and finish with $then.
     *
     * @param  Closure(Request): mixed  $then
     */
    protected function throughLayerMiddleware(Request $request, Layer $layer, Closure $then): mixed
    {
        if (empty($layer->middleware)) {
            return $then($request);
        }

        return app(Pipeline::class)
            ->send($request)
            ->through(app(Router::class)->resolveMiddleware($layer->middleware))
            ->then($then);
    }
}
