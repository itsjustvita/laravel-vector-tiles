<?php

use ItsJustVita\VectorTiles\Tests\Integration\IntegrationTestCase;
use ItsJustVita\VectorTiles\Tests\TestCase;

uses(TestCase::class)->in('Unit', 'Feature');
uses(IntegrationTestCase::class)->group('postgis')->in('Integration');
