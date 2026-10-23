<?php

use Filament\Facades\Filament;
use Filament\Support\Colors\Color;

it('uses the cyan primary and slate gray palettes', function () {
    $colors = Filament::getPanel('appsec-scout')->getColors();

    expect($colors['primary'])->toBe(Color::hex('#16adc7'))
        ->and($colors['gray'])->toBe(Color::Slate);
});

it('points the brand logos and favicon at committed assets', function () {
    $panel = Filament::getPanel('appsec-scout');

    $assets = [
        $panel->getBrandLogo(),
        $panel->getDarkModeBrandLogo(),
        $panel->getFavicon(),
    ];

    expect($assets)->toBe([
        asset('images/brand/logo-light.svg'),
        asset('images/brand/logo-dark.svg'),
        asset('favicon.ico'),
    ]);

    foreach (['images/brand/logo-light.svg', 'images/brand/logo-dark.svg', 'images/brand/icon.svg', 'favicon.ico'] as $path) {
        expect(public_path($path))->toBeFile();
    }
});

it('shows the brand logo and favicon on the login page', function () {
    $this->get(Filament::getPanel('appsec-scout')->getLoginUrl())
        ->assertOk()
        ->assertSee(asset('images/brand/logo-light.svg'), false)
        ->assertSee(asset('favicon.ico'), false);
});
