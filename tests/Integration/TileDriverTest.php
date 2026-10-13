<?php

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use ItsJustVita\VectorTiles\Drivers\PostgisTileDriver;
use ItsJustVita\VectorTiles\Layer;
use ItsJustVita\VectorTiles\Tests\Support\MvtDecoder;

beforeEach(function () {
    $this->driver = app(PostgisTileDriver::class);
});

it('renders a decodable tile with features and properties', function () {
    [$z, $x, $y] = $this->sampleTile();
    $this->createPoints('vt_points', [
        $this->lonLatInTile($z, $x, $y, 0.25, 0.25),
        $this->lonLatInTile($z, $x, $y, 0.75, 0.75),
    ]);

    $tile = $this->driver->getTile((new Layer('points'))->from('vt_points')->properties(['id', 'name']), $z, $x, $y);

    expect($tile)->toBeString();

    $layer = MvtDecoder::decode($tile)['points'];
    expect($layer['features'])->toHaveCount(2)
        ->and($layer['keys'])->toEqualCanonicalizing(['id', 'name'])
        ->and($layer['extent'])->toBe(4096);
});

it('returns an empty string for a tile without features', function () {
    $this->createPoints('vt_points', [[16.37, 48.21]]);

    expect($this->driver->getTile((new Layer('points'))->from('vt_points'), 14, 0, 0))->toBe('');
});

it('encodes the configured extent', function () {
    [$z, $x, $y] = $this->sampleTile();
    $this->createPoints('vt_points', [$this->lonLatInTile($z, $x, $y)]);

    $tile = $this->driver->getTile((new Layer('points'))->from('vt_points')->extent(512)->buffer(32), $z, $x, $y);

    expect(MvtDecoder::decode($tile)['points']['extent'])->toBe(512);
});

it('includes features inside the tile buffer but not beyond it', function () {
    [$z, $x, $y] = $this->sampleTile();
    $this->createPoints('vt_points', [
        $this->lonLatInTile($z, $x, $y, 1.02, 0.5),
        $this->lonLatInTile($z, $x, $y, 1.20, 0.5),
    ]);

    $tile = $this->driver->getTile((new Layer('points'))->from('vt_points'), $z, $x, $y);

    expect(MvtDecoder::decode($tile)['points']['features'])->toHaveCount(1);
});

it('selects features from the correct side of the antimeridian for edge tiles', function () {
    // Tile 1/0/0 covers lon -180..0, lat 0..85.
    $this->createPoints('vt_points', [[-170.0, 40.0], [-10.0, 40.0], [100.0, 40.0]]);

    $tile = $this->driver->getTile((new Layer('points'))->from('vt_points'), 1, 0, 0);

    expect(MvtDecoder::decode($tile)['points']['features'])->toHaveCount(2);
});

it('renders all features worldwide at zoom 0', function () {
    $this->createPoints('vt_points', [[-179.5, 0.0], [179.5, 0.0], [0.0, 80.0], [0.0, -80.0]]);

    $tile = $this->driver->getTile((new Layer('points'))->from('vt_points'), 0, 0, 0);

    expect(MvtDecoder::decode($tile)['points']['features'])->toHaveCount(4);
});

it('renders layers stored in a regional srid at every zoom level', function () {
    $this->createPoints('vt_utm', [[9.0, 48.75]], 25832);
    $layer = (new Layer('utm'))->from('vt_utm')->srid(25832);

    [$x10, $y10] = $this->tileAt(9.0, 48.75, 10);
    [$xAsia, $yAsia] = $this->tileAt(105.0, 35.0, 4);

    expect(MvtDecoder::decode($this->driver->getTile($layer, 0, 0, 0))['utm']['features'])->toHaveCount(1)
        ->and(MvtDecoder::decode($this->driver->getTile($layer, 10, $x10, $y10))['utm']['features'])->toHaveCount(1)
        ->and($this->driver->getTile($layer, 4, $xAsia, $yAsia))->toBe('');
});

it('lets postgres use the spatial index for 4326 layers', function () {
    [$z, $x, $y] = $this->sampleTile();
    $this->createPoints('vt_points', [$this->lonLatInTile($z, $x, $y)]);
    DB::statement('SET LOCAL enable_seqscan = off');

    $plan = $this->explainQueryOf(
        fn () => $this->driver->getTile((new Layer('points'))->from('vt_points'), $z, $x, $y),
    );

    expect($plan)->toMatch('/Index Scan|Bitmap Index Scan/')
        ->not->toContain('Seq Scan');
});

it('lets postgres use an expression index for layers in other srids', function () {
    $this->createPoints('vt_utm', [[9.0, 48.75]], 25832);
    DB::statement('CREATE INDEX vt_utm_3857_idx ON vt_utm USING gist (ST_Transform(geom, 3857))');
    DB::statement('SET LOCAL enable_seqscan = off');
    [$x, $y] = $this->tileAt(9.0, 48.75, 12);

    $plan = $this->explainQueryOf(
        fn () => $this->driver->getTile((new Layer('utm'))->from('vt_utm')->srid(25832), 12, $x, $y),
    );

    expect($plan)->toContain('vt_utm_3857_idx');
});

it('queries eloquent sources on the model connection', function () {
    DB::connection('pgsql_gis')->statement('CREATE SCHEMA IF NOT EXISTS vt_gis');
    [$z, $x, $y] = $this->sampleTile();
    $this->createPoints('vt_remote', [$this->lonLatInTile($z, $x, $y)], connection: 'pgsql_gis');

    $model = new class extends Model
    {
        protected $connection = 'pgsql_gis';

        protected $table = 'vt_remote';
    };

    $tile = $this->driver->getTile((new Layer('remote'))->from($model->newQuery()), $z, $x, $y);

    expect(MvtDecoder::decode($tile)['remote']['features'])->toHaveCount(1);
});

it('supports schema-qualified table sources', function () {
    DB::statement('CREATE SCHEMA IF NOT EXISTS vt_other');
    [$z, $x, $y] = $this->sampleTile();
    $this->createPoints('vt_other.vt_qualified', [$this->lonLatInTile($z, $x, $y)]);

    $tile = $this->driver->getTile((new Layer('qualified'))->from('vt_other.vt_qualified'), $z, $x, $y);

    expect(MvtDecoder::decode($tile)['qualified']['features'])->toHaveCount(1);
});

it('never lets or-constraints of the source bypass the scope', function () {
    [$z, $x, $y] = $this->sampleTile();
    $this->createPoints('vt_points', [
        $this->lonLatInTile($z, $x, $y, 0.25, 0.25),
        $this->lonLatInTile($z, $x, $y, 0.75, 0.75),
    ]);

    $model = new class extends Model
    {
        protected $table = 'vt_points';
    };
    $layer = (new Layer('points'))
        ->from($model->newQuery()->where('name', 'p0')->orWhere('name', 'p1'))
        ->scope(fn ($query) => $query->where('id', 2));

    $tile = $this->driver->getTile($layer, $z, $x, $y);

    expect(MvtDecoder::decode($tile)['points']['features'])->toHaveCount(1);
});

it('renders 4326 layers whose geometries reach the poles', function () {
    DB::statement('CREATE TABLE vt_polar (id serial PRIMARY KEY, geom geometry(Polygon, 4326))');
    DB::statement('INSERT INTO vt_polar (geom) VALUES (ST_MakeEnvelope(-180, -90, 180, -60, 4326))');
    $layer = (new Layer('polar'))->from('vt_polar');

    expect(MvtDecoder::decode($this->driver->getTile($layer, 0, 0, 0))['polar']['features'])->toHaveCount(1)
        ->and(MvtDecoder::decode($this->driver->getTile($layer, 1, 0, 1))['polar']['features'])->toHaveCount(1);
});

it('renders layers with a property named like the internal subquery alias', function () {
    [$z, $x, $y] = $this->sampleTile();
    $this->createPoints('vt_points', [$this->lonLatInTile($z, $x, $y)]);
    DB::statement('ALTER TABLE vt_points ADD COLUMN tile text');
    DB::statement("UPDATE vt_points SET tile = 'a'");

    $tile = $this->driver->getTile((new Layer('points'))->from('vt_points')->properties(['id', 'tile']), $z, $x, $y);

    expect(MvtDecoder::decode($tile)['points']['keys'])->toEqualCanonicalizing(['id', 'tile']);
});

function createZigzagLine(): void
{
    DB::statement('CREATE TABLE vt_lines (id serial PRIMARY KEY, geom geometry(LineString, 4326))');
    DB::statement(<<<'SQL'
        INSERT INTO vt_lines (geom)
        SELECT ST_MakeLine(array_agg(
            ST_SetSRID(ST_MakePoint(16.0 + i * 0.001, 48.2 + CASE WHEN i % 2 = 0 THEN 0.001 ELSE -0.001 END), 4326)
            ORDER BY i
        ))
        FROM generate_series(0, 600) AS i
    SQL);
}

it('simplifies lines below the max zoom of the layer', function () {
    createZigzagLine();
    [$x, $y] = $this->tileAt(16.3, 48.2, 8);

    $plain = $this->driver->getTile((new Layer('lines'))->from('vt_lines')->maxZoom(16), 8, $x, $y);
    $simplified = $this->driver->getTile((new Layer('lines'))->from('vt_lines')->maxZoom(16)->simplify(1.5), 8, $x, $y);

    expect(MvtDecoder::decode($simplified)['lines']['features'])->toHaveCount(1)
        ->and(strlen($simplified))->toBeLessThan(strlen($plain) / 4);
});

it('keeps full detail at the max zoom of the layer', function () {
    createZigzagLine();
    [$x, $y] = $this->tileAt(16.3005, 48.2, 16);

    $plain = $this->driver->getTile((new Layer('lines'))->from('vt_lines')->maxZoom(16), 16, $x, $y);
    $simplified = $this->driver->getTile((new Layer('lines'))->from('vt_lines')->maxZoom(16)->simplify(1.5), 16, $x, $y);

    expect($plain)->not->toBe('')
        ->and($simplified)->toBe($plain);
});

it('drops polygons smaller than the simplification tolerance', function () {
    DB::statement('CREATE TABLE vt_areas (id serial PRIMARY KEY, geom geometry(Polygon, 4326))');
    DB::statement('INSERT INTO vt_areas (geom) SELECT ST_Transform(ST_Buffer(ST_Transform(ST_SetSRID(ST_MakePoint(16.3, 48.1), 4326), 3857), 100, 2), 4326)');
    [$x, $y] = $this->tileAt(16.3, 48.1, 8);

    $plain = $this->driver->getTile((new Layer('areas'))->from('vt_areas')->maxZoom(16), 8, $x, $y);
    $simplified = $this->driver->getTile((new Layer('areas'))->from('vt_areas')->maxZoom(16)->simplify(1.5), 8, $x, $y);

    expect(MvtDecoder::decode($plain)['areas']['features'])->toHaveCount(1)
        ->and($simplified)->toBe('');
});

it('applies the zoom filter to tiles', function () {
    $this->createPoints('vt_points', [[16.37, 48.21], [16.38, 48.21]]);
    $layer = (new Layer('points'))
        ->from('vt_points')
        ->filterByZoom(function ($query, int $z) {
            if ($z < 11) {
                $query->where('name', 'p0');
            }
        });
    [$x10, $y10] = $this->tileAt(16.37, 48.21, 10);
    [$x11, $y11] = $this->tileAt(16.37, 48.21, 11);

    expect(MvtDecoder::decode($this->driver->getTile($layer, 10, $x10, $y10))['points']['features'])->toHaveCount(1)
        ->and(MvtDecoder::decode($this->driver->getTile($layer, 11, $x11, $y11))['points']['features'])->toHaveCount(2);
});

it('renders correctly with a scope, a zoom filter and simplification together', function () {
    $this->createPoints('vt_points', [[16.37, 48.21], [16.38, 48.21], [16.39, 48.21]]);
    $layer = (new Layer('points'))
        ->from('vt_points')
        ->properties(['name'])
        ->maxZoom(16)
        ->scope(fn ($query) => $query->where('name', '!=', 'p2'))
        ->filterByZoom(fn ($query, int $z) => $query->where('id', '<=', $z - 7))
        ->simplify(1.5);
    [$x8, $y8] = $this->tileAt(16.37, 48.21, 8);
    [$x9, $y9] = $this->tileAt(16.37, 48.21, 9);

    expect(MvtDecoder::decode($this->driver->getTile($layer, 8, $x8, $y8))['points']['features'])->toHaveCount(1)
        ->and(MvtDecoder::decode($this->driver->getTile($layer, 9, $x9, $y9))['points']['features'])->toHaveCount(2);
});
