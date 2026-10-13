<?php

namespace ItsJustVita\VectorTiles\Query;

use Illuminate\Database\ConnectionInterface;

/**
 * A complete SQL statement with its bindings and the connection it runs on.
 */
final readonly class CompiledQuery
{
    /**
     * @param  array<int, mixed>  $bindings
     */
    public function __construct(
        public ConnectionInterface $connection,
        public string $sql,
        public array $bindings,
    ) {}
}
