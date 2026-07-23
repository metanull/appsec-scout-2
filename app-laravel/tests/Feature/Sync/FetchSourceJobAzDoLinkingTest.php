<?php

use App\Credentials\Vault;
use App\Models\RepositoryMapping;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use App\Sources\AzDo\AzDoSource;
use App\Sources\Dto\ContainerDto;
use App\Sources\Dto\SystemDto;
use App\Sync\FetchSourceJob;
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
