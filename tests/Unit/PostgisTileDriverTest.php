<?php

use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use ItsJustVita\VectorTiles\Drivers\PostgisTileDriver;
use ItsJustVita\VectorTiles\Layer;

beforeEach(function () {
    $this->db = Mockery::mock(DatabaseManager::class);
    $this->driver = new PostgisTileDriver($this->db);
});

it('builds correct SQL for simple table layer', function () {
    $layer = (new Layer('trassen'))
        ->from('trassen_table')
        ->geometry('geom')
        ->properties(['id', 'typ', 'status']);

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('selectOne')
        ->once()
        ->withArgs(function (string $sql, array $bindings) {
            expect($sql)
                ->toContain('ST_AsMVT')
                ->toContain('ST_AsMVTGeom')
                ->toContain('ST_Transform("geom", 3857)')
                ->toContain('ST_TileEnvelope(?, ?, ?)')
                ->toContain('"trassen_table"')
                ->toContain('ST_Intersects')
                ->toContain('"id", "typ", "status"');

            expect($bindings)->toContain(14, 8532, 5765);

            return true;
        })
        ->andReturn((object) ['tile' => 'binary_mvt_data']);

    $this->db->shouldReceive('connection')
        ->with(null)
        ->andReturn($connection);

    $result = $this->driver->getTile($layer, 14, 8532, 5765);

    expect($result)->toBe('binary_mvt_data');
});

it('returns null for empty tile', function () {
    $layer = (new Layer('trassen'))->from('trassen_table');

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('selectOne')->andReturn(null);

    $this->db->shouldReceive('connection')
        ->with(null)
        ->andReturn($connection);

    $result = $this->driver->getTile($layer, 14, 8532, 5765);

    expect($result)->toBeNull();
});

it('uses configured connection', function () {
    $layer = (new Layer('trassen'))
        ->from('trassen_table')
        ->connection('pgsql_gis');

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('selectOne')->andReturn(null);

    $this->db->shouldReceive('connection')
        ->with('pgsql_gis')
        ->once()
        ->andReturn($connection);

    $this->driver->getTile($layer, 14, 8532, 5765);
});

it('uses custom extent and buffer', function () {
    $layer = (new Layer('trassen'))
        ->from('trassen_table')
        ->extent(8192)
        ->buffer(512);

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('selectOne')
        ->withArgs(function (string $sql) {
            expect($sql)->toContain('8192')->toContain('512');
            return true;
        })
        ->andReturn(null);

    $this->db->shouldReceive('connection')->with(null)->andReturn($connection);

    $this->driver->getTile($layer, 14, 8532, 5765);
});

it('handles eloquent builder source', function () {
    $baseBuilder = Mockery::mock(\Illuminate\Database\Query\Builder::class);
    $baseBuilder->from = 'trassen_table';
    $baseBuilder->wheres = [
        ['type' => 'Basic', 'column' => 'status', 'operator' => '=', 'value' => 'aktiv', 'boolean' => 'and'],
    ];
    $baseBuilder->shouldReceive('getRawBindings')->andReturn(['where' => ['aktiv']]);

    $eloquentBuilder = Mockery::mock(\Illuminate\Database\Eloquent\Builder::class);
    $eloquentBuilder->shouldReceive('toBase')->andReturn($baseBuilder);

    $layer = (new Layer('trassen'))
        ->from($eloquentBuilder)
        ->properties(['id']);

    $connection = Mockery::mock(Connection::class);
    $connection->shouldReceive('selectOne')
        ->withArgs(function (string $sql, array $bindings) {
            expect($sql)->toContain('"trassen_table"');
            expect($bindings)->toContain('aktiv');
            return true;
        })
        ->andReturn(null);

    $this->db->shouldReceive('connection')->with(null)->andReturn($connection);

    $this->driver->getTile($layer, 14, 8532, 5765);
});
