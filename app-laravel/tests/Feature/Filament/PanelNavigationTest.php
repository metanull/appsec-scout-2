<?php

use App\Filament\Pages\Dashboard;
use App\Filament\Pages\ProfileIntegrationsPage;
use Filament\Facades\Filament;

it('collapses the sidebar to icon-only on desktop', function () {
    expect(Filament::getPanel('appsec-scout')->isSidebarCollapsibleOnDesktop())->toBeTrue();
});

it('exposes Profile and Profile integrations as top-level navigation items visible to every user', function () {
    $items = Filament::getPanel('appsec-scout')->getNavigationItems();

    $profile = collect($items)->first(fn ($item) => $item->getLabel() === 'Profile');
    $integrations = collect($items)->first(fn ($item) => $item->getLabel() === 'Profile integrations');

    expect($profile)->not->toBeNull()
        ->and($integrations)->not->toBeNull()
        ->and($profile->getGroup())->toBeNull()
        ->and($integrations->getGroup())->toBeNull()
        ->and($profile->getSort())->toBeLessThan($integrations->getSort())
        ->and($profile->getSort())->toBeGreaterThan(Dashboard::getNavigationSort())
        ->and($integrations->getUrl())->toBe(ProfileIntegrationsPage::getUrl());
});
