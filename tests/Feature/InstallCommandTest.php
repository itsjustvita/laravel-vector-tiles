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
