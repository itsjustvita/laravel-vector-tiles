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
     * Build a fresh base query for the layer with its scope applied, and with
     * its zoom filter when a zoom level is given.
     *
     * Eloquent sources run on the model's connection, table sources on the
     * layer's connection. The layer's source is never mutated.
     */
    public function make(Layer $layer, ?Authenticatable $user = null, ?int $zoom = null): QueryBuilder
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

        if ($zoom !== null && $layer->zoomFilter !== null) {
            $scopeWheres = count($query->wheres);
            ($layer->zoomFilter)($query, $zoom);
            $this->groupOrWheres($query, $scopeWheres);
        }

        return $query;
    }

    /**
     * Whether building the layer query runs a scope, either the layer's own
     * or a global scope of its Eloquent model. Only a scope can depend on the
     * user, so only then can a missing user explain a failure.
     */
    public function appliesScopes(Layer $layer): bool
    {
        if ($layer->scope !== null) {
            return true;
        }

        if (! $layer->source instanceof EloquentBuilder) {
            return false;
        }

        // Removing all global scopes from a copy lists the ones still active,
        // including scopes added on the builder with withGlobalScope().
        $removed = (clone $layer->source)->withoutGlobalScopes()->removedScopes();

        return array_diff($removed, $layer->source->removedScopes()) !== [];
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
