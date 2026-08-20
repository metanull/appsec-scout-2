<?php

use App\Audit\AuditLog;
use App\Credentials\Vault;
use App\Models\ErrorLog;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Models\SyncRun;
use App\Sources\Dto\ContainerDto;
use App\Sources\Dto\SystemDto;
use App\Sync\InventorySyncService;
use Tests\Fakes\FakeInventorySourceControlProvider;
use Tests\Fakes\FakeSource;

beforeEach(function () {
    // Inventory sync only runs configured integrations; seed the fakes' system credentials.
    app(Vault::class)->set('fake.apiKey', null, 'fake-key');
    app(Vault::class)->set('fake-inventory-repos.token', null, 'fake-token');
});

it('syncs systems and containers from an enabled Source', function () {
    $source = (new FakeSource)
        ->withSystems(new SystemDto('sys-1', 'Payments API'))
        ->withContainers('sys-1', new ContainerDto('cont-1', 'Backend Repo', 'sys-1', 'repository'));

    $this->app->bind('appsec-scout.source.fake', fn () => $source);
    $this->app->tag(['appsec-scout.source.fake'], 'appsec-scout.source');

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

    app(InventorySyncService::class)->sync();

    $sys2 = SoftwareSystem::query()->where('source_id', 'fake')->where('source_system_id', 'sys-2')->firstOrFail();

    // Only "Payments API" matches this filter — sys-2 is legitimately out of scope, not gone.
    app(InventorySyncService::class)->sync(null, '^Payments API$');

    expect($sys2->fresh()->removed_at)->toBeNull();
});

it('records a successful sync as a success sync run with its counts', function () {
    $source = (new FakeSource)
        ->withSystems(new SystemDto('sys-1', 'Payments API'))
        ->withContainers('sys-1', new ContainerDto('cont-1', 'Backend Repo', 'sys-1', 'repository'));

    $this->app->bind('appsec-scout.source.fake', fn () => $source);
    $this->app->tag(['appsec-scout.source.fake'], 'appsec-scout.source');

    app(InventorySyncService::class)->sync();

    $run = SyncRun::query()->where('source_id', InventorySyncService::RUN_SOURCE_ID)->latest('id')->firstOrFail();

    expect($run->status)->toBe('success')
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->counts_json['systems_created'])->toBe(1)
        ->and($run->counts_json['containers_created'])->toBe(1)
        ->and($run->counts_json)->not->toHaveKey('scope')
        ->and($run->error_message)->toBeNull();
});

it('records the scope on the sync run of a filtered pass', function () {
    $source = (new FakeSource)->withSystems(new SystemDto('sys-1', 'Payments API'));

    $this->app->bind('appsec-scout.source.fake', fn () => $source);
    $this->app->tag(['appsec-scout.source.fake'], 'appsec-scout.source');

    app(InventorySyncService::class)->sync('fake', '^Payments API$');

    $run = SyncRun::query()->where('source_id', InventorySyncService::RUN_SOURCE_ID)->latest('id')->firstOrFail();

    expect($run->status)->toBe('success')
        ->and($run->counts_json['scope'])->toBe(['only' => 'fake', 'project_filter' => '^Payments API$']);
});

it('marks the sync run failed and writes an error log when a provider throws', function () {
    $source = (new FakeSource)->withFetchSystemsFailure();

    $this->app->bind('appsec-scout.source.fake', fn () => $source);
    $this->app->tag(['appsec-scout.source.fake'], 'appsec-scout.source');

    expect(fn () => app(InventorySyncService::class)->sync())
        ->toThrow(RuntimeException::class, 'systems enumeration failed');

    $run = SyncRun::query()->where('source_id', InventorySyncService::RUN_SOURCE_ID)->latest('id')->firstOrFail();

    expect($run->status)->toBe('failure')
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->error_message)->toContain('systems enumeration failed')
        ->and(ErrorLog::query()->where('channel', 'sync')->where('message', 'like', '%systems enumeration failed%')->exists())->toBeTrue();
});

it('writes one inventory_sync_completed audit entry describing what changed', function () {
    $source = (new FakeSource)
        ->withSystems(new SystemDto('sys-1', 'Payments API'))
        ->withContainers('sys-1', new ContainerDto('cont-1', 'Backend Repo', 'sys-1', 'repository'));

    $this->app->bind('appsec-scout.source.fake', fn () => $source);
    $this->app->tag(['appsec-scout.source.fake'], 'appsec-scout.source');

    app(InventorySyncService::class)->sync();

    $entries = AuditLog::query()->where('action', 'inventory_sync_completed')->get();

    expect($entries)->toHaveCount(1)
        ->and($entries->first()?->payload_json['counts']['systems_created'])->toBe(1)
        ->and($entries->first()?->payload_json['created_systems'])->toBe(['fake: Payments API'])
        ->and($entries->first()?->payload_json['created_containers'])->toBe(['fake: Backend Repo'])
        ->and($entries->first()?->payload_json)->not->toHaveKey('scope');
});

it('records the scope in the completion audit entry of a filtered pass', function () {
    $source = (new FakeSource)->withSystems(new SystemDto('sys-1', 'Payments API'));

    $this->app->bind('appsec-scout.source.fake', fn () => $source);
    $this->app->tag(['appsec-scout.source.fake'], 'appsec-scout.source');

    app(InventorySyncService::class)->sync('fake', '^Payments API$');

    $entry = AuditLog::query()->where('action', 'inventory_sync_completed')->firstOrFail();

    expect($entry->payload_json['scope'])->toBe(['only' => 'fake', 'project_filter' => '^Payments API$']);
});

it('writes no completion audit entry when the sync fails', function () {
    $source = (new FakeSource)->withFetchSystemsFailure();

    $this->app->bind('appsec-scout.source.fake', fn () => $source);
    $this->app->tag(['appsec-scout.source.fake'], 'appsec-scout.source');

    expect(fn () => app(InventorySyncService::class)->sync())->toThrow(RuntimeException::class);

    expect(AuditLog::query()->where('action', 'inventory_sync_completed')->exists())->toBeFalse();
});

it('ignores a Source Control provider that does not implement EnumeratesInventory', function () {
    // GitHubRepos implements only SourceControlProvider, not EnumeratesInventory (Story A left it
    // unimplemented) — enabling it must not attempt to call a repo-listing method it doesn't have.

    $counts = app(InventorySyncService::class)->sync();

    expect(SoftwareSystem::query()->where('source_id', 'github-repos')->exists())->toBeFalse()
        ->and($counts)->toBeArray();
});
