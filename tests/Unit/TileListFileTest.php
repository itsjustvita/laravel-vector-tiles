<?php

use ItsJustVita\VectorTiles\Benchmark\TileListFile;

beforeEach(function () {
    $this->file = tempnam(sys_get_temp_dir(), 'vt-tiles-');
});

afterEach(function () {
    if (is_file($this->file)) {
        unlink($this->file);
    }
});

it('reads tiles and skips blank lines and comments', function () {
    file_put_contents($this->file, "# sampled tiles\n8/139/88\n\n 12/2234/1420 \n");

    expect(TileListFile::read($this->file))->toBe([[8, 139, 88], [12, 2234, 1420]]);
});

it('names the line of an invalid tile', function () {
    file_put_contents($this->file, "8/139/88\n8/139\n");

    expect(fn () => TileListFile::read($this->file))
        ->toThrow(InvalidArgumentException::class, 'Invalid tile on line 2: 8/139');
});

it('rejects tiles outside their zoom level', function () {
    file_put_contents($this->file, "8/256/0\n");

    expect(fn () => TileListFile::read($this->file))
        ->toThrow(InvalidArgumentException::class, 'Invalid tile on line 1: 8/256/0');
});

it('fails for a missing file', function () {
    expect(fn () => TileListFile::read('/nonexistent/vt-tiles.txt'))
        ->toThrow(InvalidArgumentException::class, 'Tile list /nonexistent/vt-tiles.txt cannot be read.');
});

it('writes tiles that read back unchanged', function () {
    TileListFile::write($this->file, [[8, 139, 88], [12, 2234, 1420]]);

    expect(file_get_contents($this->file))->toBe("8/139/88\n12/2234/1420\n")
        ->and(TileListFile::read($this->file))->toBe([[8, 139, 88], [12, 2234, 1420]]);
});
