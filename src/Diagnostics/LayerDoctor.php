<?php

namespace ItsJustVita\VectorTiles\Diagnostics;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Str;
use ItsJustVita\VectorTiles\Drivers\PostgisTileDriver;
use ItsJustVita\VectorTiles\Layer;
use ItsJustVita\VectorTiles\Query\LayerQueryFactory;
use ItsJustVita\VectorTiles\Support\WebMercator;
use Throwable;

/**
 * Checks whether a layer can be served: PostGIS, source, geometry column,
 * SRID, properties and spatial index usage.
 *
 * Every statement runs in a transaction that is rolled back, so the doctor
 * never changes the database and works with read-only users.
 */
class LayerDoctor
{
    public function __construct(
        protected LayerQueryFactory $queries,
        protected PostgisTileDriver $driver,
    ) {}

    /**
     * @return list<Check>
     */
    public function diagnose(Layer $layer, ?Authenticatable $user = null): array
    {
        try {
            $query = $this->queries->make($layer, $user);
        } catch (Throwable $e) {
            return [$user === null
                ? Check::fail('The layer scope failed without a user: '.$this->reason($e), 'Pass --user=ID to run the scope as that user.')
                : Check::fail('The layer scope failed: '.$this->reason($e))];
        }

        $connection = $query->getConnection();
        $connectionName = $connection->getName();

        try {
            $version = (string) $this->probe($connection, fn () => $connection->selectOne('SELECT postgis_lib_version() AS version')->version);
        } catch (Throwable) {
            return [Check::fail("PostGIS is not available on connection {$connectionName}", 'CREATE EXTENSION postgis;')];
        }

        if (version_compare($version, '3.0.0', '<')) {
            return [Check::fail("PostGIS {$version} on connection {$connectionName} is too old, 3.0 or newer is required")];
        }

        $checks = [Check::pass("PostGIS {$version} on connection {$connectionName}")];
        $table = $this->sourceTable($query);

        if ($table !== null) {
            if (! $this->relationExists($query, $table)) {
                $checks[] = Check::fail("Source {$table} does not exist");

                return $checks;
            }

            $checks[] = Check::pass("Source {$table} exists");
        }

        $geometry = $this->checkGeometry($query, $layer);
        $checks[] = $geometry;

        if (! empty($layer->properties)) {
            $checks[] = $this->checkProperties($query, $layer);
        }

        if ($geometry->status !== CheckStatus::Fail) {
            array_push($checks, ...$this->checkIndex($query, $layer, $table, $user));
        }

        return $checks;
    }

    /**
     * The relation the layer reads from, without alias, or null when the
     * source is an expression such as a subquery.
     */
    protected function sourceTable(QueryBuilder $query): ?string
    {
        if (! is_string($query->from)) {
            return null;
        }

        return preg_split('/\s+as\s+/i', trim($query->from))[0];
    }

    protected function relationExists(QueryBuilder $query, string $table): bool
    {
        $connection = $query->getConnection();

        try {
            return $this->probe($connection, fn () => $connection->selectOne(
                'SELECT to_regclass(?) AS relation',
                [$query->getGrammar()->wrapTable($table)],
            )->relation) !== null;
        } catch (Throwable) {
            return false;
        }
    }

    protected function checkGeometry(QueryBuilder $query, Layer $layer): Check
    {
        $connection = $query->getConnection();
        $geometry = $query->getGrammar()->wrap($layer->geometry);

        try {
            $type = $this->probe($connection, fn () => (clone $query)
                ->select([])
                ->selectRaw("pg_typeof({$geometry})::text AS type")
                ->limit(1)
                ->first()?->type);

            $srid = $type !== 'geometry' ? null : $this->probe($connection, fn () => (clone $query)
                ->select([])
                ->selectRaw("ST_SRID({$geometry}) AS srid")
                ->whereNotNull($layer->geometry)
                ->limit(1)
                ->first()?->srid);
        } catch (Throwable $e) {
            return Check::fail("Geometry column {$layer->geometry} cannot be read", $this->reason($e));
        }

        if ($type === null) {
            return Check::warn('Layer has no rows, so the geometry type and SRID cannot be checked');
        }

        if ($type !== 'geometry') {
            return Check::fail("Column {$layer->geometry} has type {$type}, expected geometry");
        }

        if ($srid === null) {
            return Check::warn("Column {$layer->geometry} only holds NULL values, so the SRID cannot be checked");
        }

        if ((int) $srid !== (int) $layer->srid) {
            return Check::fail(
                "Column {$layer->geometry} has SRID {$srid}, layer is configured as {$layer->srid}",
                "Set ->srid({$srid}) on the layer, or the 'srid' key in config/vector-tiles.php.",
            );
        }

        return Check::pass("Geometry column {$layer->geometry} (geometry, SRID {$srid})");
    }

    protected function checkProperties(QueryBuilder $query, Layer $layer): Check
    {
        $connection = $query->getConnection();
        $missing = [];

        foreach ($layer->properties as $property) {
            try {
                $this->probe($connection, fn () => (clone $query)->select([$property])->limit(0)->get());
            } catch (Throwable) {
                $missing[] = $property;
            }
        }

        return $missing === []
            ? Check::pass('Properties: '.implode(', ', $layer->properties))
            : Check::fail('Missing properties: '.implode(', ', $missing));
    }

    /**
     * Lets PostgreSQL plan a real tile query with sequential scans disabled.
     * A Seq Scan that remains means no index can serve the tile filter.
     *
     * @return list<Check>
     */
    protected function checkIndex(QueryBuilder $query, Layer $layer, ?string $table, ?Authenticatable $user): array
    {
        $zoom = min(max($layer->minZoom, 12), $layer->maxZoom);
        [$lon, $lat] = $this->samplePoint($query, $layer) ?? [0.0, 0.0];
        [$x, $y] = WebMercator::tileAt($lon, $lat, $zoom);

        try {
            $tile = $this->driver->tileQuery($layer, $zoom, $x, $y, $user);

            $row = $this->probe($tile->connection, function () use ($tile) {
                $tile->connection->statement('SET LOCAL enable_seqscan = off');

                return $tile->connection->selectOne('EXPLAIN (FORMAT JSON) '.$tile->sql, $tile->bindings);
            });
        } catch (Throwable $e) {
            return [Check::fail("The tile query for {$zoom}/{$x}/{$y} failed", $this->reason($e))];
        }

        $plan = json_decode($row->{'QUERY PLAN'}, true);
        $relations = array_values(array_unique($this->seqScannedRelations($plan[0]['Plan'])));

        if ($relations === []) {
            return [Check::pass('Spatial index is used')];
        }

        $sourceName = $table === null ? null : Str::afterLast($table, '.');

        return array_map(fn (string $relation) => Check::warn(
            "No usable spatial index (Seq Scan on {$relation})",
            $relation === $sourceName
                ? $this->indexStatement($table, $layer)
                : "If {$relation} holds the geometry column, index it there:\n".$this->indexStatement($relation, $layer),
        ), $relations);
    }

    /**
     * @return array{0: float, 1: float}|null WGS84 position on the first feature
     */
    protected function samplePoint(QueryBuilder $query, Layer $layer): ?array
    {
        $connection = $query->getConnection();
        $point = 'ST_Transform(ST_PointOnSurface('.$query->getGrammar()->wrap($layer->geometry).'), 4326)';

        try {
            $row = $this->probe($connection, fn () => (clone $query)
                ->select([])
                ->selectRaw("ST_X({$point}) AS lon, ST_Y({$point}) AS lat")
                ->whereNotNull($layer->geometry)
                ->limit(1)
                ->first());
        } catch (Throwable) {
            return null;
        }

        return $row?->lon === null ? null : [(float) $row->lon, (float) $row->lat];
    }

    /**
     * @param  array<string, mixed>  $node
     * @return list<string>
     */
    protected function seqScannedRelations(array $node): array
    {
        $relations = $node['Node Type'] === 'Seq Scan' ? [$node['Relation Name'] ?? '?'] : [];

        foreach ($node['Plans'] ?? [] as $child) {
            array_push($relations, ...$this->seqScannedRelations($child));
        }

        return $relations;
    }

    protected function indexStatement(string $table, Layer $layer): string
    {
        $name = str_replace(['.', '"'], ['_', ''], $table).'_'.$layer->geometry;

        return in_array((int) $layer->srid, [3857, 4326], true)
            ? "CREATE INDEX {$name}_idx ON {$table} USING gist ({$layer->geometry});"
            : "CREATE INDEX {$name}_3857_idx ON {$table} USING gist (ST_Transform({$layer->geometry}, 3857));";
    }

    /**
     * Runs $callback in a transaction that is always rolled back: nothing the
     * doctor runs can persist, and a failing statement does not leave an
     * enclosing transaction aborted.
     */
    protected function probe(ConnectionInterface $connection, Closure $callback): mixed
    {
        $connection->beginTransaction();

        try {
            return $callback();
        } finally {
            $connection->rollBack();
        }
    }

    /**
     * The first line of an error message, without the SQLSTATE prefix and
     * the connection details Laravel appends.
     */
    protected function reason(Throwable $e): string
    {
        $line = Str::before(Str::before($e->getMessage(), "\n"), ' (Connection: ');

        return preg_replace('/^SQLSTATE\[\w+\]: .+?: \d+ ERROR:\s+/', '', $line) ?? $line;
    }
}
