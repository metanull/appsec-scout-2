<?php

use App\Credentials\Vault;
use App\Models\RepositoryMapping;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use App\Sources\AzDo\AzDoSource;
use App\Sources\Dto\ContainerDto;
use App\Sources\Dto\SystemDto;
use Tests\Fakes\FakeSource;

function azdoFakeSource(): FakeSource
{
    return new class extends FakeSource
    {
        public function id(): string
        {
            return 'azdo';
        }
    };
}

beforeEach(function () {
    app(Vault::class)->set('azdo.organization', null, 'testorg');
    // Repository auto-linking resolves the organization from the Source Control
    // credential (azdo-repos.*), so it must be seeded for mappings to be created.
    app(Vault::class)->set('azdo-repos.organization', null, 'testorg');
});

it('syncs every azdo project and repo into assets, systems, containers, and mappings', function () {
    $source = azdoFakeSource()
        ->withSystems(
            new SystemDto('proj-1', 'TelCodes'),
            new SystemDto('proj-2', 'dotNet-Common'),
        )
        ->withContainers('proj-1', new ContainerDto('repo-1', 'TelCodes', 'proj-1', 'repository'))
        ->withContainers('proj-2', new ContainerDto('repo-2', 'helpers', 'proj-2', 'repository'));

    config(['integration_settings.azdo.enabled' => true]);
    $this->app->bind(AzDoSource::class, fn () => $source);

    $this->artisan('assets:sync-azdo-projects')
        ->assertSuccessful();

    expect(SoftwareSystem::query()->where('source_id', 'azdo')->count())->toBe(2)
        ->and(SoftwareAsset::query()->count())->toBe(2)
        ->and(SecurityContainer::query()->count())->toBe(2)
        ->and(RepositoryMapping::query()->count())->toBe(2);

    $telcodes = SoftwareSystem::query()->where('source_system_id', 'proj-1')->firstOrFail();
    expect($telcodes->softwareAsset?->name)->toBe('TelCodes');
});

it('applies project and repository filters', function () {
    $source = azdoFakeSource()
        ->withSystems(
            new SystemDto('proj-1', 'TelCodes'),
            new SystemDto('proj-2', 'dotNet-Common'),
        )
        ->withContainers('proj-1', new ContainerDto('repo-1', 'TelCodes', 'proj-1', 'repository'))
        ->withContainers('proj-2', new ContainerDto('repo-2', 'helpers', 'proj-2', 'repository'));

    config(['integration_settings.azdo.enabled' => true]);
    $this->app->bind(AzDoSource::class, fn () => $source);

    $this->artisan('assets:sync-azdo-projects', ['--project-filter' => '^TelCodes$'])
        ->assertSuccessful();

    expect(SoftwareSystem::query()->where('source_id', 'azdo')->count())->toBe(1)
        ->and(SoftwareSystem::query()->where('source_system_id', 'proj-1')->exists())->toBeTrue();
});

it('accepts an explicit --pat override instead of the stored system credential', function () {
    $source = azdoFakeSource()->withSystems(new SystemDto('proj-1', 'TelCodes'));

    config(['integration_settings.azdo.enabled' => true]);
    $this->app->bind(AzDoSource::class, fn () => $source);

    $this->artisan('assets:sync-azdo-projects', ['--pat' => 'explicit-pat'])
        ->assertSuccessful();

    expect(SoftwareSystem::query()->where('source_id', 'azdo')->count())->toBe(1);
});

it('leaves an already-linked software system alone and reports zero new assets', function () {
    $asset = SoftwareAsset::factory()->create(['name' => 'Pre-existing']);
    SoftwareSystem::factory()->create([
        'source_id' => 'azdo',
        'source_system_id' => 'proj-1',
        'software_asset_id' => $asset->id,
    ]);

    $source = azdoFakeSource()->withSystems(new SystemDto('proj-1', 'TelCodes'));

    config(['integration_settings.azdo.enabled' => true]);
    $this->app->bind(AzDoSource::class, fn () => $source);

    $this->artisan('assets:sync-azdo-projects')
        ->expectsOutputToContain('Software assets created')
        ->assertSuccessful();

    expect(SoftwareAsset::query()->count())->toBe(1);
});
