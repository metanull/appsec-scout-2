<?php

use App\Filament\Pages\IntegrationSettingsPage;
use App\Filament\Pages\OperationsPage;
use App\Filament\Pages\PendingSyncPage;
use App\Filament\Pages\ProfileIntegrationsPage;
use App\Filament\Pages\SystemCredentialsPage;
use App\Filament\Resources\AuditLogResource;
use App\Filament\Resources\ErrorLogResource;
use App\Filament\Resources\LocalFindingResource;
use App\Filament\Resources\RepositoryProviderResource;
use App\Filament\Resources\SecurityContainerResource;
use App\Filament\Resources\SecurityEventResource;
use App\Filament\Resources\SoftwareAssetResource;
use App\Filament\Resources\SoftwareComponentResource;
use App\Filament\Resources\SoftwareSystemResource;
use App\Filament\Resources\UserResource;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;

// Story 6.1 — navigation sort values

it('sets navigation sort 9 for the Alerts resource', function () {
    expect(SecurityEventResource::getNavigationSort())->toBe(9);
});

it('sets navigation sort 10 for the Local Findings resource', function () {
    expect(LocalFindingResource::getNavigationSort())->toBe(10);
});

it('sets navigation sort 11 for the Dependencies resource', function () {
    expect(SoftwareComponentResource::getNavigationSort())->toBe(11);
});

it('sets navigation sort 12 and label Software Assets for the Software assets resource', function () {
    expect(SoftwareAssetResource::getNavigationSort())->toBe(12)
        ->and(SoftwareAssetResource::getNavigationLabel())->toBe('Software Assets');
});

it('sets navigation sort 13 and label Software Systems for the Software systems resource', function () {
    expect(SoftwareSystemResource::getNavigationSort())->toBe(13)
        ->and(SoftwareSystemResource::getNavigationLabel())->toBe('Software Systems');
});

it('sets navigation sort 14 for the Containers resource', function () {
    expect(SecurityContainerResource::getNavigationSort())->toBe(14);
});

it('sets navigation sort 20 for the Operations page', function () {
    expect(OperationsPage::getNavigationSort())->toBe(20);
});

it('sets navigation sort 21 and label System Credentials for the System credentials page', function () {
    expect(SystemCredentialsPage::getNavigationSort())->toBe(21)
        ->and(SystemCredentialsPage::getNavigationLabel())->toBe('System Credentials');
});

it('sets navigation sort 22 for the Integrations page', function () {
    expect(IntegrationSettingsPage::getNavigationSort())->toBe(22);
});

it('sets navigation sort 23 and label Repository Providers for the Repository providers resource', function () {
    expect(RepositoryProviderResource::getNavigationSort())->toBe(23)
        ->and(RepositoryProviderResource::getNavigationLabel())->toBe('Repository Providers');
});

it('sets navigation sort 24 for the Errors resource', function () {
    expect(ErrorLogResource::getNavigationSort())->toBe(24);
});

it('sets navigation sort 25 for the Audit Log resource', function () {
    expect(AuditLogResource::getNavigationSort())->toBe(25);
});

it('sets navigation sort 26 for the Users resource', function () {
    expect(UserResource::getNavigationSort())->toBe(26);
});

it('sets navigation sort 30 for the Pending Sync page', function () {
    expect(PendingSyncPage::getNavigationSort())->toBe(30);
});

it('pins the Reader, Admin, Sync navigation group display order', function () {
    $labels = collect(Filament::getDefaultPanel()->getNavigationGroups())
        ->map(fn ($group): string => is_string($group) ? $group : $group->getLabel())
        ->values()
        ->all();

    expect($labels)->toBe(['Reader', 'Admin', 'Sync']);
});

it('orders the Reader group as Alerts, Local Findings, Dependencies, Software Assets, Software Systems, Containers', function () {
    $sorts = [
        SecurityEventResource::getNavigationSort(),
        LocalFindingResource::getNavigationSort(),
        SoftwareComponentResource::getNavigationSort(),
        SoftwareAssetResource::getNavigationSort(),
        SoftwareSystemResource::getNavigationSort(),
        SecurityContainerResource::getNavigationSort(),
    ];

    expect($sorts)->toBe(collect($sorts)->sort()->values()->all());
});

it('orders the Admin group as Operations, System Credentials, Integrations, Repository Providers, Errors, Audit Log, Users', function () {
    $sorts = [
        OperationsPage::getNavigationSort(),
        SystemCredentialsPage::getNavigationSort(),
        IntegrationSettingsPage::getNavigationSort(),
        RepositoryProviderResource::getNavigationSort(),
        ErrorLogResource::getNavigationSort(),
        AuditLogResource::getNavigationSort(),
        UserResource::getNavigationSort(),
    ];

    expect($sorts)->toBe(collect($sorts)->sort()->values()->all());
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
