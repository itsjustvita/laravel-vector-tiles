<?php

use ItsJustVita\VectorTiles\Tests\Integration\IntegrationTestCase;
use ItsJustVita\VectorTiles\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature');
uses(IntegrationTestCase::class)->group('postgis')->in('Integration');

/**
 * Registers the package routes again, so they pick up config changes made
 * in a test. Routes with the same URI replace the ones registered at boot.
 */
function reloadTileRoutes(): void
{
    require __DIR__.'/../routes/tiles.php';
}
