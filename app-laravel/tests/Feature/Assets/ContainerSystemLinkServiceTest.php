<?php

use App\Assets\ContainerSystemLinkService;
use App\Audit\AuditLog;
use App\Filament\Resources\SecurityContainerResource\Pages\ViewSecurityContainer;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareAsset;
use App\Models\SoftwareComponent;
use App\Models\SoftwareSystem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

/** @return array{finding: LocalFinding, component: SoftwareComponent} */
function ownedScanRows(SecurityContainer $container, string $suffix): array
{
    $system = $container->softwareSystem;

    return [
        'finding' => LocalFinding::query()->create([
            'owner_type' => SecurityContainer::class,
            'owner_id' => $container->id,
            'software_system_id' => $system?->id,
            'software_asset_id' => $system?->software_asset_id,
            'kind' => LocalFinding::KIND_VULNERABILITY,
            'rule_id' => 'CVE-2024-' . $suffix,
            'title' => 'Finding ' . $suffix,
            'file_path' => 'requirements.txt',
        ]),
        'component' => SoftwareComponent::query()->create([
            'owner_type' => SecurityContainer::class,
            'owner_id' => $container->id,
            'software_system_id' => $system?->id,
            'software_asset_id' => $system?->software_asset_id,
            'name' => 'component-' . $suffix,
            'purl' => 'pkg:pypi/component-' . $suffix . '@1.0.0',
        ]),
    ];
}

function curator(string $role = 'Plan'): User
{
    $user = User::factory()->create();
    $user->syncRoles([$role]);

    return $user;
}

it('moves a container\'s alerts and re-stamps the rows it owns, leaving other containers alone', function () {
    $source = SoftwareSystem::factory()->create(['software_asset_id' => SoftwareAsset::factory()]);
    $target = SoftwareSystem::factory()->create(['software_asset_id' => SoftwareAsset::factory()]);
    $container = SecurityContainer::factory()->forSystem($source)->create();
    $sibling = SecurityContainer::factory()->forSystem($source)->create();
    $events = SecurityEvent::factory()->forContainer($container)->count(2)->create();
    $siblingEvent = SecurityEvent::factory()->forContainer($sibling)->create();
    $owned = ownedScanRows($container, '0001');
    $siblingOwned = ownedScanRows($sibling, '0002');
    $author = User::factory()->create();

    $moved = app(ContainerSystemLinkService::class)->relink($container, $target, $author)->container;

    expect($moved->software_system_id)->toBe($target->id)
        ->and($moved->source_software_system_id)->toBe($source->id)
        ->and($moved->isSystemOverridden())->toBeTrue()
        ->and(SecurityEvent::query()->whereKey($events->modelKeys())->pluck('software_system_id')->unique()->all())->toBe([$target->id])
        ->and($siblingEvent->fresh()?->software_system_id)->toBe($source->id)
        ->and($owned['finding']->fresh()?->software_system_id)->toBe($target->id)
        ->and($owned['finding']->fresh()?->software_asset_id)->toBe($target->software_asset_id)
        ->and($owned['component']->fresh()?->software_system_id)->toBe($target->id)
        ->and($owned['component']->fresh()?->software_asset_id)->toBe($target->software_asset_id)
        ->and($siblingOwned['finding']->fresh()?->software_system_id)->toBe($source->id)
        ->and($siblingOwned['component']->fresh()?->software_asset_id)->toBe($source->software_asset_id);

    $audit = AuditLog::query()->where('action', 'security_container_relinked')->sole();

    expect($audit->payload_json)->toMatchArray([
        'security_container_id' => $container->id,
        'previous_software_system_id' => $source->id,
        'software_system_id' => $target->id,
        'source_software_system_id' => $source->id,
        'overridden' => true,
        'author_id' => $author->id,
        'events' => 2,
        'local_findings' => 1,
        'software_components' => 1,
    ]);
});

it('clears the manual flag when a container is reset to its source system', function () {
    $source = SoftwareSystem::factory()->create();
    $target = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->relinked($source, $target)->create();
    $event = SecurityEvent::factory()->forContainer($container)->create();

    $reset = app(ContainerSystemLinkService::class)->reset($container)->container;

    expect($reset->software_system_id)->toBe($source->id)
        ->and($reset->isSystemOverridden())->toBeFalse()
        ->and($event->fresh()?->software_system_id)->toBe($source->id)
        ->and(AuditLog::query()->where('action', 'security_container_link_reset')->sole()->payload_json['overridden'])->toBeFalse();
});

it('clears the manual flag when a container is moved back to its source system', function () {
    $source = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->relinked($source, SoftwareSystem::factory()->create())->create();

    expect(app(ContainerSystemLinkService::class)->relink($container, $source)->container->isSystemOverridden())->toBeFalse();
});

it('refuses a move that cannot apply', function (Closure $arrange, string $message) {
    [$container, $target] = $arrange();

    expect(fn () => app(ContainerSystemLinkService::class)->relink($container, $target))
        ->toThrow(InvalidArgumentException::class, $message);

    expect(AuditLog::query()->where('action', 'security_container_relinked')->exists())->toBeFalse();
})->with([
    'removed target' => [fn () => [SecurityContainer::factory()->create(), SoftwareSystem::factory()->create(['removed_at' => now()])], 'was removed from its source'],
    'current system' => [function () {
        $container = SecurityContainer::factory()->create();

        return [$container, $container->softwareSystem];
    }, 'is already linked to'],
    'no source system' => [function () {
        $container = SecurityContainer::factory()->create();
        $container->forceFill(['source_software_system_id' => null])->save();

        return [$container, SoftwareSystem::factory()->create()];
    }, 'has no source system'],
]);

it('refuses to reset a container that follows its source', function () {
    expect(fn () => app(ContainerSystemLinkService::class)->reset(SecurityContainer::factory()->create()))
        ->toThrow(InvalidArgumentException::class, 'already follows its source system');
});

it('moves a container and restores its source link from the container page', function () {
    (new RolePermissionSeeder)->run();
    $source = SoftwareSystem::factory()->create();
    $target = SoftwareSystem::factory()->create(['name' => 'Target system']);
    $container = SecurityContainer::factory()->forSystem($source)->create();

    $page = Livewire::actingAs(curator())
        ->test(ViewSecurityContainer::class, ['record' => $container->getRouteKey()])
        ->assertActionVisible('moveToSystem')
        ->assertActionHidden('restoreSourceLink')
        ->callAction('moveToSystem', ['software_system_id' => $target->id])
        ->assertHasNoActionErrors()
        ->assertNotified('Moved to Target system');

    expect($container->fresh()?->software_system_id)->toBe($target->id);

    $page->assertActionVisible('restoreSourceLink')
        ->callAction('restoreSourceLink')
        ->assertNotified('Source link restored');

    expect($container->fresh()?->software_system_id)->toBe($source->id)
        ->and($container->fresh()?->isSystemOverridden())->toBeFalse();
});

it('hides the link actions from users who cannot curate', function () {
    (new RolePermissionSeeder)->run();
    $container = SecurityContainer::factory()->relinked(SoftwareSystem::factory()->create(), SoftwareSystem::factory()->create())->create();

    Livewire::actingAs(curator('Triage'))
        ->test(ViewSecurityContainer::class, ['record' => $container->getRouteKey()])
        ->assertActionHidden('moveToSystem')
        ->assertActionHidden('restoreSourceLink');
});
