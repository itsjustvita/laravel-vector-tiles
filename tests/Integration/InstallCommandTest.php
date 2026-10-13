<?php

it('detects the real postgis version', function () {
    $this->artisan('vector-tiles:install')
        ->expectsOutputToContain('PostGIS detected: v')
        ->assertSuccessful();
});
