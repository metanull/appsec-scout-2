<?php

use App\Filament\Resources\SecurityContainerResource;
use App\Filament\Resources\SecurityContainerResource\RelationManagers\EventsRelationManager as ContainerEventsRelationManager;
use App\Filament\Resources\SoftwareSystemLinkResource;
use App\Filament\Resources\SoftwareSystemResource;
use App\Filament\Resources\SoftwareSystemResource\RelationManagers\ContainersRelationManager;
use App\Filament\Resources\SoftwareSystemResource\RelationManagers\EventsRelationManager;
use App\Filament\Resources\SoftwareSystemResource\RelationManagers\LinksRelationManager;
use App\Models\CuratedLink;
use App\Models\RepositoryMapping;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\SoftwareSystemLink;
use App\Models\TrackerProjectLink;
use App\Models\User;
use App\Trackers\Dto\ProjectDto;
use Database\Seeders\RolePermissionSeeder;
use Tests\Fakes\FakeTracker;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('registers relation managers for software systems', function () {
    expect(SoftwareSystemResource::getRelations())
        ->toContain(EventsRelationManager::class, ContainersRelationManager::class, LinksRelationManager::class);
});

it('registers relation managers for security containers', function () {
    expect(SecurityContainerResource::getRelations())
        ->toContain(ContainerEventsRelationManager::class);
});

it('loads related events containers and links for software system view', function () {
    $system = SoftwareSystem::factory()->create(['url' => null, 'metadata' => null]);
    $container = SecurityContainer::factory()->forSystem($system)->create(['url' => null, 'metadata' => null]);
    SecurityEvent::factory()->forSystem($system)->forContainer($container)->create();

    $link = SoftwareSystemLink::factory()->create();
    $system->links()->attach($link->id, ['sort_order' => 1]);

    expect($system->events()->count())->toBe(1)
        ->and($system->containers()->count())->toBe(1)
        ->and($system->links()->count())->toBe(1);
});

it('loads related events for container view', function () {
    $system = SoftwareSystem::factory()->create(['url' => null, 'metadata' => null]);
    $container = SecurityContainer::factory()->forSystem($system)->create(['url' => null, 'metadata' => null]);
    SecurityEvent::factory()->forSystem($system)->forContainer($container)->count(2)->create();

    expect($container->events()->count())->toBe(2);
});

it('renders navigation catalogs on system and container views when links exist', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Reader']);

    bindFakeWorkItemTracker((new FakeTracker)->withProjects(
        new ProjectDto(
            key: 'APP',
            name: 'App Project',
            url: 'https://tracker.test/projects/APP',
        ),
        new ProjectDto(
            key: 'OPS',
            name: 'Ops Project',
            url: 'https://tracker.test/projects/OPS',
        ),
    ));

    $system = SoftwareSystem::factory()->create([
        'name' => 'Acme App',
        'url' => 'https://example.com/system',
        'metadata' => [
            'links' => [
                ['label' => 'System docs', 'url' => 'https://docs.example.com/system'],
            ],
        ],
    ]);

    CuratedLink::query()->create([
        'owner_type' => SoftwareSystem::class,
        'owner_id' => $system->id,
        'label' => 'System curated',
        'url' => 'https://curated.example.com/system',
        'kind' => 'source',
        'created_by_user_id' => $user->id,
    ]);

    TrackerProjectLink::query()->create([
        'owner_type' => SoftwareSystem::class,
        'owner_id' => $system->id,
        'tracker_id' => 'fake-tracker',
        'project_key' => 'APP',
        'project_name' => 'App Project',
        'is_default' => false,
        'created_by_user_id' => $user->id,
    ]);

    RepositoryMapping::factory()
        ->forSystem($system)
        ->github()
        ->create([
            'repository_name' => 'acme-app',
            'repository_url' => 'https://github.com/acme/acme-app',
            'created_by_user_id' => $user->id,
        ]);

    $container = SecurityContainer::factory()->forSystem($system)->create([
        'name' => 'Acme Repo',
        'url' => 'https://example.com/container',
        'metadata' => [
            'links' => [
                ['label' => 'Container docs', 'url' => 'https://docs.example.com/container'],
            ],
        ],
    ]);

    CuratedLink::query()->create([
        'owner_type' => SecurityContainer::class,
        'owner_id' => $container->id,
        'label' => 'Container curated',
        'url' => 'https://curated.example.com/container',
        'kind' => 'remediation',
        'created_by_user_id' => $user->id,
    ]);

    TrackerProjectLink::query()->create([
        'owner_type' => SecurityContainer::class,
        'owner_id' => $container->id,
        'tracker_id' => 'fake-tracker',
        'project_key' => 'OPS',
        'project_name' => 'Ops Project',
        'is_default' => false,
        'created_by_user_id' => $user->id,
    ]);

    RepositoryMapping::factory()
        ->forContainer($container)
        ->github()
        ->create([
            'repository_name' => 'acme-container',
            'repository_url' => 'https://github.com/acme/acme-container',
            'created_by_user_id' => $user->id,
        ]);

    $this->actingAs($user)
        ->get(SoftwareSystemResource::getUrl('view', ['record' => $system]))
        ->assertOk()
        ->assertSee('Navigation')
        ->assertSee('System')
        ->assertSee('System docs')
        ->assertSee('System curated')
        ->assertSee('App Project')
        ->assertSee('Repository: acme-app');

    $this->actingAs($user)
        ->get(SecurityContainerResource::getUrl('view', ['record' => $container]))
        ->assertOk()
        ->assertSee('Navigation')
        ->assertSee('Container')
        ->assertSee('Container docs')
        ->assertSee('Container curated')
        ->assertSee('Ops Project')
        ->assertSee('Repository: acme-container');
});

it('hides navigation catalogs when links are missing', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Reader']);

    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create();

    $this->actingAs($user)
        ->get(SoftwareSystemResource::getUrl('view', ['record' => $system]))
        ->assertOk()
        ->assertDontSee('System curated')
        ->assertDontSee('App Project')
        ->assertDontSee('Repository: acme-app');

    $this->actingAs($user)
        ->get(SecurityContainerResource::getUrl('view', ['record' => $container]))
        ->assertOk()
        ->assertDontSee('Container curated')
        ->assertDontSee('Ops Project')
        ->assertDontSee('Repository: acme-container');
});

it('system links expose create and edit pages', function () {
    expect(SoftwareSystemLinkResource::getPages())
        ->toHaveKeys(['index', 'create', 'view', 'edit']);
});
