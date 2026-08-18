<?php

use App\Filament\Pages\OperationsPage;
use App\Filament\Pages\PendingJobsPage;
use App\Filament\Pages\ProfileIntegrationsPage;
use App\Filament\Pages\SystemCredentialsPage;
use App\Filament\Resources\AuditLogResource;
use App\Filament\Resources\ErrorLogResource;
use App\Filament\Resources\FailedJobResource;
use App\Filament\Resources\LocalFindingResource;
use App\Filament\Resources\PendingSyncResource;
use App\Filament\Resources\RepositoryCollectionRunResource;
use App\Filament\Resources\RepositoryProviderResource;
use App\Filament\Resources\SecurityContainerResource;
use App\Filament\Resources\SecurityEventResource;
use App\Filament\Resources\SoftwareAssetResource;
use App\Filament\Resources\SoftwareComponentResource;
use App\Filament\Resources\SoftwareSystemResource;
use App\Filament\Resources\StaticAnalysisRunResource;
use App\Filament\Resources\SyncRunResource;
use App\Filament\Resources\UserResource;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Illuminate\Support\Facades\DB;

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

it('sets navigation sort 21 and label System Credentials for the System credentials page', function () {
    expect(SystemCredentialsPage::getNavigationSort())->toBe(21)
        ->and(SystemCredentialsPage::getNavigationLabel())->toBe('System Credentials');
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

it('sets navigation sort 30 for the Pending Sync resource', function () {
    expect(PendingSyncResource::getNavigationSort())->toBe(30);
});

it('pins the Reader, Admin, Operations, Sync navigation group display order', function () {
    $labels = collect(Filament::getDefaultPanel()->getNavigationGroups())
        ->map(fn ($group): string => is_string($group) ? $group : $group->getLabel())
        ->values()
        ->all();

    expect($labels)->toBe(['Reader', 'Admin', 'Operations', 'Sync']);
});

it('sets navigation sort and labels for every Operations group page', function () {
    expect(OperationsPage::getNavigationSort())->toBe(1)
        ->and(OperationsPage::getNavigationLabel())->toBe('Operations')
        ->and(SyncRunResource::getNavigationSort())->toBe(2)
        ->and(SyncRunResource::getNavigationLabel())->toBe('Sync Runs')
        ->and(RepositoryCollectionRunResource::getNavigationSort())->toBe(3)
        ->and(RepositoryCollectionRunResource::getNavigationLabel())->toBe('Collection Runs')
        ->and(StaticAnalysisRunResource::getNavigationSort())->toBe(4)
        ->and(StaticAnalysisRunResource::getNavigationLabel())->toBe('Analysis Runs')
        ->and(PendingJobsPage::getNavigationSort())->toBe(5)
        ->and(PendingJobsPage::getNavigationLabel())->toBe('Queues')
        ->and(FailedJobResource::getNavigationSort())->toBe(6)
        ->and(FailedJobResource::getNavigationLabel())->toBe('Failed Jobs');
});

it('orders the Operations group as Operations, Sync Runs, Collection Runs, Analysis Runs, Queues, Failed Jobs', function () {
    $sorts = [
        OperationsPage::getNavigationSort(),
        SyncRunResource::getNavigationSort(),
        RepositoryCollectionRunResource::getNavigationSort(),
        StaticAnalysisRunResource::getNavigationSort(),
        PendingJobsPage::getNavigationSort(),
        FailedJobResource::getNavigationSort(),
    ];

    expect($sorts)->toBe(collect($sorts)->sort()->values()->all());
});

it('registers every Operations group page/resource in navigation, gated on admin.queue', function () {
    (new RolePermissionSeeder)->run();

    $admin = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $admin->syncRoles(['Admin']);
    $this->actingAs($admin);

    expect(OperationsPage::shouldRegisterNavigation())->toBeTrue()
        ->and(OperationsPage::canAccess())->toBeTrue()
        ->and(SyncRunResource::shouldRegisterNavigation())->toBeTrue()
        ->and(SyncRunResource::canAccess())->toBeTrue()
        ->and(RepositoryCollectionRunResource::shouldRegisterNavigation())->toBeTrue()
        ->and(RepositoryCollectionRunResource::canAccess())->toBeTrue()
        ->and(StaticAnalysisRunResource::shouldRegisterNavigation())->toBeTrue()
        ->and(StaticAnalysisRunResource::canAccess())->toBeTrue()
        ->and(PendingJobsPage::shouldRegisterNavigation())->toBeTrue()
        ->and(PendingJobsPage::canAccess())->toBeTrue()
        ->and(FailedJobResource::shouldRegisterNavigation())->toBeTrue()
        ->and(FailedJobResource::canAccess())->toBeTrue();

    $reader = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $reader->syncRoles(['Reader']);
    $this->actingAs($reader);

    expect(OperationsPage::canAccess())->toBeFalse()
        ->and(SyncRunResource::canAccess())->toBeFalse()
        ->and(RepositoryCollectionRunResource::canAccess())->toBeFalse()
        ->and(StaticAnalysisRunResource::canAccess())->toBeFalse()
        ->and(PendingJobsPage::canAccess())->toBeFalse()
        ->and(FailedJobResource::canAccess())->toBeFalse();
});

it('shows a danger-colored failed-jobs count on the navigation badge, and no badge when there are none', function () {
    expect(FailedJobResource::getNavigationBadge())->toBeNull();

    DB::table('failed_jobs')->insert([
        'uuid' => (string) str()->uuid(),
        'connection' => 'database',
        'queue' => 'default',
        'payload' => '{}',
        'exception' => 'boom',
        'failed_at' => now(),
    ]);

    expect(FailedJobResource::getNavigationBadge())->toBe('1')
        ->and(FailedJobResource::getNavigationBadgeColor())->toBe('danger');
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

it('orders the Admin group as System Credentials, Repository Providers, Errors, Audit Log, Users', function () {
    $sorts = [
        SystemCredentialsPage::getNavigationSort(),
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
