<?php

namespace ItsJustVita\VectorTiles\Tests;

use ItsJustVita\VectorTiles\VectorTilesServiceProvider;
use Orchestra\Testbench\TestCase as OrchestraTestCase;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            VectorTilesServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'VectorTiles' => \ItsJustVita\VectorTiles\Facades\VectorTiles::class,
        ];
    }
}
