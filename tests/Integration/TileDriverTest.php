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
