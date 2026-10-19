<?php

use App\Credentials\Vault;
use App\Models\ErrorLog;
use App\Models\RepositoryMapping;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use App\Models\SyncRun;
use App\Sources\AzDo\AzDoSource;
use App\Sources\Dto\ContainerDto;
use App\Sources\Dto\SystemDto;
use App\Sync\FetchSourceJob;
use App\Sync\InventorySyncService;
use App\Sync\RepositoryLinkSkips;
use App\Sync\SystemIntegrationRuntime;
use App\Sync\Upserter;
use Tests\Fakes\FakeSource;

it('automatically links azdo systems to a software asset and creates repository mappings during a regular sync', function () {
    app(Vault::class)->set('azdo-repos.organization', null, 'testorg');

    $source = new class extends FakeSource
    {
        public function id(): string
        {
            return 'azdo';
        }
    };

    $source
        ->withSystems(new SystemDto('proj-1', 'TelCodes'))
        ->withContainers('proj-1', new ContainerDto('repo-1', 'TelCodes', 'proj-1', 'repository'));

    $this->app->bind(AzDoSource::class, fn () => $source);

    (new FetchSourceJob('azdo'))->handle(app(SystemIntegrationRuntime::class), app(Upserter::class));

    $system = SoftwareSystem::query()->where('source_id', 'azdo')->where('source_system_id', 'proj-1')->firstOrFail();

    expect($system->software_asset_id)->not()->toBeNull()
        ->and(SoftwareAsset::query()->findOrFail($system->software_asset_id)->name)->toBe('TelCodes')
        ->and(RepositoryMapping::query()->count())->toBe(1);
});

it('reports repository mappings skipped for a missing AzDO Repos organization once per fetch run', function () {
    $source = new class extends FakeSource
    {
        public function id(): string
        {
            return 'azdo';
        }
    };

    $source
        ->withSystems(new SystemDto('proj-1', 'TelCodes'))
        ->withContainers(
            'proj-1',
            new ContainerDto('repo-1', 'tinc-front', 'proj-1', 'repository'),
            new ContainerDto('repo-2', 'tinc-back', 'proj-1', 'repository'),
        );

    $this->app->bind(AzDoSource::class, fn () => $source);

    (new FetchSourceJob('azdo'))->handle(app(SystemIntegrationRuntime::class), app(Upserter::class));

    $run = SyncRun::query()->where('source_id', 'azdo')->latest('id')->firstOrFail();
    $warnings = ErrorLog::query()->where('level', 'warning')->where('message', RepositoryLinkSkips::MESSAGE);

    expect($run->status)->toBe('success')
        ->and($run->counts_json[RepositoryLinkSkips::COUNT_KEY])->toBe(2)
        ->and($run->counts_json['containers_created'])->toBe(2)
        ->and(RepositoryMapping::query()->count())->toBe(0)
        ->and($warnings->count())->toBe(1)
        ->and($warnings->first()?->channel)->toBe('sync')
        ->and($warnings->first()?->context_json)->toMatchArray(['source_id' => 'azdo']);

    (new FetchSourceJob('azdo'))->handle(app(SystemIntegrationRuntime::class), app(Upserter::class));

    expect(ErrorLog::query()->where('level', 'warning')->where('message', RepositoryLinkSkips::MESSAGE)->count())->toBe(2);
});

it('reports repository mappings skipped for a missing AzDO Repos organization once per inventory sync run', function () {
    // Inventory sync only runs configured integrations: the fake source requires fake.apiKey.
    app(Vault::class)->set('fake.apiKey', null, 'fake-key');

    $source = new class extends FakeSource
    {
        public function id(): string
        {
            return 'azdo';
        }
    };

    $source
        ->withSystems(new SystemDto('proj-1', 'TelCodes'))
        ->withContainers(
            'proj-1',
            new ContainerDto('repo-1', 'tinc-front', 'proj-1', 'repository'),
            new ContainerDto('repo-2', 'tinc-back', 'proj-1', 'repository'),
        );

    $this->app->bind(AzDoSource::class, fn () => $source);

    $counts = app(InventorySyncService::class)->sync('azdo');

    expect($counts[RepositoryLinkSkips::COUNT_KEY])->toBe(2)
        ->and($counts['repository_mappings_created'])->toBe(0)
        ->and(SyncRun::query()->where('source_id', InventorySyncService::RUN_SOURCE_ID)->latest('id')->firstOrFail()->counts_json[RepositoryLinkSkips::COUNT_KEY])->toBe(2)
        ->and(ErrorLog::query()->where('level', 'warning')->where('message', RepositoryLinkSkips::MESSAGE)->count())->toBe(1);
});
