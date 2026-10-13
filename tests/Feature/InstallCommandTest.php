<?php

it('runs the install command', function () {
    $this->artisan('vector-tiles:install')
        ->assertSuccessful();
});

it('outputs install info', function () {
    $this->artisan('vector-tiles:install')
        ->expectsOutputToContain('Vector Tiles')
        ->assertSuccessful();
});

it('warns when postgis is older than 3.0', function () {
    \Illuminate\Support\Facades\DB::shouldReceive('selectOne')
        ->once()
        ->andReturn((object) ['version' => '2.5.5']);

    $this->artisan('vector-tiles:install')
        ->expectsOutputToContain('requires PostGIS 3.0 or newer')
        ->assertSuccessful();
});

it('reports a supported postgis version', function () {
    \Illuminate\Support\Facades\DB::shouldReceive('selectOne')
        ->once()
        ->andReturn((object) ['version' => '3.4.2']);

    $this->artisan('vector-tiles:install')
        ->expectsOutputToContain('PostGIS detected: v3.4.2')
        ->assertSuccessful();
});
