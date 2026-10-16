<?php

use Illuminate\Support\Facades\File;

afterEach(function () {
    // vendor:publish writes into the Testbench skeleton, which outlives the
    // test run. A stale copy there would replace the package config defaults.
    File::delete(config_path('vector-tiles.php'));
});

it('detects the real postgis version', function () {
    $this->artisan('vector-tiles:install')
        ->expectsOutputToContain('PostGIS detected: v')
        ->assertSuccessful();
});
