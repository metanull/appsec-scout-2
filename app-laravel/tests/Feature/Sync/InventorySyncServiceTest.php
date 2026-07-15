<?php

use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Sources\Dto\ContainerDto;
use App\Sources\Dto\SystemDto;
use App\Sync\InventorySyncService;
use Tests\Fakes\FakeInventorySourceControlProvider;
use Tests\Fakes\FakeSource;

it('syncs systems and containers from an enabled Source', function () {
    $source = (new FakeSource)
        ->withSystems(new SystemDto('sys-1', 'Payments API'))
        ->withContainers('sys-1', new ContainerDto('cont-1', 'Backend Repo', 'sys-1', 'repository'));

    $this->app->bind('appsec-scout.source.fake', fn () => $source);
    $this->app->tag(['appsec-scout.source.fake'], 'appsec-scout.source');
    config(['integration_settings.fake.enabled' => true]);

    $counts = app(InventorySyncService::class)->sync();

    expect($counts['systems_created'])->toBe(1)
        ->and($counts['containers_created'])->toBe(1)
        ->and(SoftwareSystem::query()->where('source_id', 'fake')->where('source_system_id', 'sys-1')->exists())->toBeTrue()
        ->and(SecurityContainer::query()->where('source_container_id', 'cont-1')->exists())->toBeTrue();
});

it('syncs projects and repositories from an enabled Source Control provider implementing EnumeratesInventory', function () {
    $provider = (new FakeInventorySourceControlProvider)
        ->withProjects(new SystemDto('proj-1', 'SecurityProject'))
        ->withRepositories('proj-1', new ContainerDto('repo-1', 'backend-api', 'proj-1', 'repository'));

    $this->app->bind('appsec-scout.source-control.fake-inventory', fn () => $provider);
    $this->app->tag(['appsec-scout.source-control.fake-inventory'], 'appsec-scout.source-control');
    config(['integration_settings.fake-inventory-repos.enabled' => true]);

    $counts = app(InventorySyncService::class)->sync();

    expect($counts['systems_created'])->toBe(1)
        ->and($counts['containers_created'])->toBe(1)
        ->and(SoftwareSystem::query()->where('source_id', 'fake-inventory-repos')->where('source_system_id', 'proj-1')->exists())->toBeTrue()
        ->and(SecurityContainer::query()->where('source_container_id', 'repo-1')->exists())->toBeTrue();
});

it('scopes sync to a single id, ignoring other enabled sources and providers', function () {
    $source = (new FakeSource)->withSystems(new SystemDto('sys-1', 'Payments API'));
    $provider = (new FakeInventorySourceControlProvider)->withProjects(new SystemDto('proj-1', 'SecurityProject'));

    $this->app->bind('appsec-scout.source.fake', fn () => $source);
    $this->app->tag(['appsec-scout.source.fake'], 'appsec-scout.source');
    $this->app->bind('appsec-scout.source-control.fake-inventory', fn () => $provider);
    $this->app->tag(['appsec-scout.source-control.fake-inventory'], 'appsec-scout.source-control');
    config([
        'integration_settings.fake.enabled' => true,
        'integration_settings.fake-inventory-repos.enabled' => true,
    ]);

    $counts = app(InventorySyncService::class)->sync('fake');

    expect($counts['systems_created'])->toBe(1)
        ->and(SoftwareSystem::query()->where('source_id', 'fake')->exists())->toBeTrue()
        ->and(SoftwareSystem::query()->where('source_id', 'fake-inventory-repos')->exists())->toBeFalse();
});

it('sweeps a system no longer returned by a full, unfiltered sync', function () {
    $source = (new FakeSource)->withSystems(
        new SystemDto('sys-1', 'Payments API'),
        new SystemDto('sys-2', 'Billing API'),
    );

    $this->app->bind('appsec-scout.source.fake', fn () => $source);
    $this->app->tag(['appsec-scout.source.fake'], 'appsec-scout.source');
    config(['integration_settings.fake.enabled' => true]);

    app(InventorySyncService::class)->sync();

    $sys2 = SoftwareSystem::query()->where('source_id', 'fake')->where('source_system_id', 'sys-2')->firstOrFail();

    $source->withSystems(new SystemDto('sys-1', 'Payments API'));

    app(InventorySyncService::class)->sync();

    expect($sys2->fresh()->removed_at)->not->toBeNull();
});

it('does not sweep when a project filter narrows the sync to less than everything', function () {
    $source = (new FakeSource)->withSystems(
        new SystemDto('sys-1', 'Payments API'),
        new SystemDto('sys-2', 'Billing API'),
    );

    $this->app->bind('appsec-scout.source.fake', fn () => $source);
    $this->app->tag(['appsec-scout.source.fake'], 'appsec-scout.source');
    config(['integration_settings.fake.enabled' => true]);

    app(InventorySyncService::class)->sync();

    $sys2 = SoftwareSystem::query()->where('source_id', 'fake')->where('source_system_id', 'sys-2')->firstOrFail();

    // Only "Payments API" matches this filter — sys-2 is legitimately out of scope, not gone.
    app(InventorySyncService::class)->sync(null, '^Payments API$');

    expect($sys2->fresh()->removed_at)->toBeNull();
});

it('ignores a Source Control provider that does not implement EnumeratesInventory', function () {
    // GitHubRepos implements only SourceControlProvider, not EnumeratesInventory (Story A left it
    // unimplemented) — enabling it must not attempt to call a repo-listing method it doesn't have.
    config(['integration_settings.github-repos.enabled' => true]);

    $counts = app(InventorySyncService::class)->sync();

    expect(SoftwareSystem::query()->where('source_id', 'github-repos')->exists())->toBeFalse()
        ->and($counts)->toBeArray();
});
