<?php

namespace ItsJustVita\VectorTiles\Tests\Integration;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use ItsJustVita\VectorTiles\Tests\TestCase;
use PDO;

abstract class IntegrationTestCase extends TestCase
{
    use DatabaseTransactions;

    /** @var list<string> */
    protected $connectionsToTransact = ['pgsql', 'pgsql_gis'];

    protected static bool $postgisEnabled = false;

    protected function setUp(): void
    {
        if (! getenv('VT_PGSQL_HOST')) {
            $this->markTestSkipped('Set VT_PGSQL_HOST and the other VT_PGSQL_* variables to run the PostGIS integration tests.');
        }

        static::enablePostgis();

        parent::setUp();
    }

    protected function defineEnvironment($app): void
    {
        $connection = static::connectionConfig();

        $app['config']->set('database.default', 'pgsql');
        $app['config']->set('database.connections.pgsql', $connection);
        $app['config']->set('database.connections.pgsql_gis', array_merge($connection, [
            'search_path' => 'vt_gis,public',
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    protected static function connectionConfig(): array
    {
        return [
            'driver' => 'pgsql',
            'host' => getenv('VT_PGSQL_HOST'),
            'port' => getenv('VT_PGSQL_PORT') ?: '5432',
            'database' => getenv('VT_PGSQL_DATABASE') ?: 'postgres',
            'username' => getenv('VT_PGSQL_USERNAME') ?: 'postgres',
            'password' => getenv('VT_PGSQL_PASSWORD') ?: '',
            'charset' => 'utf8',
            'prefix' => '',
            'prefix_indexes' => true,
            'search_path' => 'public',
            'sslmode' => 'prefer',
        ];
    }

    /**
     * Runs once, outside the per-test transaction, so the extension survives rollbacks.
     */
    protected static function enablePostgis(): void
    {
        if (static::$postgisEnabled) {
            return;
        }

        $config = static::connectionConfig();
        $pdo = new PDO(
            "pgsql:host={$config['host']};port={$config['port']};dbname={$config['database']}",
            $config['username'],
            $config['password'],
        );
        $pdo->exec('CREATE EXTENSION IF NOT EXISTS postgis');

        static::$postgisEnabled = true;
    }

    /**
     * @param  list<array{0: float, 1: float}>  $lonLats  WGS84 coordinates, stored as points named p0, p1, …
     */
    protected function createPoints(string $table, array $lonLats, int $srid = 4326, string $connection = 'pgsql', bool $index = true): void
    {
        $db = DB::connection($connection);

        $db->statement("CREATE TABLE {$table} (id serial PRIMARY KEY, name text, geom geometry(Point, {$srid}))");

        if ($index) {
            $db->statement("CREATE INDEX ON {$table} USING gist (geom)");
        }

        foreach (array_values($lonLats) as $i => [$lon, $lat]) {
            $db->insert(
                "INSERT INTO {$table} (name, geom) VALUES (?, ST_Transform(ST_SetSRID(ST_MakePoint(?, ?), 4326), {$srid}))",
                ["p{$i}", $lon, $lat],
            );
        }

        $db->statement("ANALYZE {$table}");
    }

    /**
     * Creates vt_users with user 7 named p0 and makes it the default auth provider.
     */
    protected function createUsers(): void
    {
        DB::statement('CREATE TABLE vt_users (id integer PRIMARY KEY, name text)');
        DB::insert("INSERT INTO vt_users (id, name) VALUES (7, 'p0')");

        config()->set('auth.providers.users', ['driver' => 'database', 'table' => 'vt_users']);
    }

    /**
     * @return array{0: int, 1: int} x and y of the tile containing lon/lat at zoom z
     */
    protected function tileAt(float $lon, float $lat, int $z): array
    {
        $n = 2 ** $z;
        $latRad = deg2rad($lat);

        return [
            (int) floor(($lon + 180) / 360 * $n),
            (int) floor((1 - log(tan($latRad) + 1 / cos($latRad)) / M_PI) / 2 * $n),
        ];
    }

    /**
     * @return array{0: float, 1: float} lon/lat at a fractional position in tile z/x/y; fractions outside 0..1 lie outside the tile
     */
    protected function lonLatInTile(int $z, int $x, int $y, float $fx = 0.5, float $fy = 0.5): array
    {
        $n = 2 ** $z;

        return [
            ($x + $fx) / $n * 360 - 180,
            rad2deg(atan(sinh(M_PI * (1 - 2 * ($y + $fy) / $n)))),
        ];
    }

    /**
     * @return array{0: int, 1: int, 2: int} the zoom-14 tile over central Vienna used as default fixture tile
     */
    protected function sampleTile(): array
    {
        return [14, 8937, 5679];
    }

    /**
     * Returns the EXPLAIN plan of the first query the callback executes.
     */
    protected function explainQueryOf(callable $callback): string
    {
        $captured = null;

        DB::listen(function (QueryExecuted $query) use (&$captured) {
            $captured ??= $query;
        });

        $callback();

        return collect(DB::select('EXPLAIN '.$captured->sql, $captured->bindings))
            ->pluck('QUERY PLAN')
            ->implode("\n");
    }
}
