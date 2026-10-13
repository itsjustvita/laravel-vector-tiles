<?php

namespace ItsJustVita\VectorTiles\Query;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use ItsJustVita\VectorTiles\Layer;

class LayerQueryFactory
{
    public function __construct(
        protected DatabaseManager $db,
    ) {}

    /**
     * Build a fresh base query for the layer with its scope applied.
     *
     * Eloquent sources run on the model's connection, table sources on the
     * layer's connection. The layer's source is never mutated.
     */
    public function make(Layer $layer, ?Authenticatable $user = null): QueryBuilder
    {
        if ($layer->source instanceof EloquentBuilder) {
            $query = clone $layer->source->toBase();
        } else {
            $query = $this->db->connection($layer->connection)->table($layer->getTable());
        }

        if ($layer->scope !== null) {
            ($layer->scope)($query, $user);
        }

        return $query;
    }
}
