<?php

use App\Filament\Pages\IntegrationSettingsPage;
use App\Filament\Pages\OperationsPage;
use App\Filament\Pages\PendingSyncPage;
use App\Filament\Pages\ProfileIntegrationsPage;
use App\Filament\Pages\SystemCredentialsPage;
use App\Filament\Resources\AuditLogResource;
use App\Filament\Resources\ErrorLogResource;
use App\Filament\Resources\SecurityContainerResource;
use App\Filament\Resources\SecurityEventResource;
use App\Filament\Resources\SoftwareSystemResource;
use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;

// Story 6.1 — navigation sort values

it('sets navigation sort 20 for the Users resource', function () {
    expect(UserResource::getNavigationSort())->toBe(20);
});

it('sets navigation sort 21 for the Operations page', function () {
    expect(OperationsPage::getNavigationSort())->toBe(21);
});

it('sets navigation sort 22 for the Integrations page', function () {
    expect(IntegrationSettingsPage::getNavigationSort())->toBe(22);
});

it('sets navigation sort 23 for the System credentials page', function () {
    expect(SystemCredentialsPage::getNavigationSort())->toBe(23);
});

it('sets navigation sort 25 for the Errors resource', function () {
    expect(ErrorLogResource::getNavigationSort())->toBe(25);
});

it('sets navigation sort 26 for the Audit Log resource', function () {
    expect(AuditLogResource::getNavigationSort())->toBe(26);
});

it('sets navigation sort 10 for the Containers resource', function () {
    expect(SecurityContainerResource::getNavigationSort())->toBe(10);
});

it('sets navigation sort 11 for the Alerts resource', function () {
    expect(SecurityEventResource::getNavigationSort())->toBe(11);
});

it('sets navigation sort 12 for the Software systems resource', function () {
    expect(SoftwareSystemResource::getNavigationSort())->toBe(12);
});

it('sets navigation sort 30 for the Pending Sync page', function () {
    expect(PendingSyncPage::getNavigationSort())->toBe(30);
});

// Story 6.2 — full-width panel content

it('configures the panel with full-width content', function () {
    expect(Filament::getDefaultPanel()->getMaxContentWidth())->toBe(Width::Full);
});

it('adds profile integrations to the user menu', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $menuItems = Filament::getDefaultPanel()->getUserMenuItems();
    $profileIntegrations = collect($menuItems)
        ->first(fn ($item): bool => $item->getLabel() === 'Profile integrations');

    expect($profileIntegrations)->not->toBeNull()
        ->and($profileIntegrations->getUrl())->toBe(ProfileIntegrationsPage::getUrl());
});
