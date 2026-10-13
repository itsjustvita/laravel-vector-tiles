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
            $this->groupOrWheres($query, 0);
        } else {
            $query = $this->db->connection($layer->connection)->table($layer->getTable());
        }

        if ($layer->scope !== null) {
            $sourceWheres = count($query->wheres);
            ($layer->scope)($query, $user);
            $this->groupOrWheres($query, $sourceWheres);
        }

        return $query;
    }

    /**
     * Wrap the wheres from $offset on in a nested group when they contain an OR,
     * so constraints added afterwards cannot be bypassed. The where order stays
     * the same, so the flat binding list remains valid.
     */
    protected function groupOrWheres(QueryBuilder $query, int $offset): void
    {
        $slice = array_slice($query->wheres, $offset);

        $hasOr = collect($slice)->contains(fn (array $where) => str_contains($where['boolean'], 'or'));

        if (! $hasOr) {
            return;
        }

        $group = $query->forNestedWhere();
        $group->wheres = $slice;

        $query->wheres = [
            ...array_slice($query->wheres, 0, $offset),
            ['type' => 'Nested', 'query' => $group, 'boolean' => 'and'],
        ];
    }
}
