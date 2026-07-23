<?php

use App\Context\Quality\ContextQualityService;
use App\Models\RepositoryProvider;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('reports complete context when mappings and source links exist', function () {
    $system = SoftwareSystem::factory()->create(['url' => 'https://example.test/systems/payments']);
    $container = SecurityContainer::factory()->forSystem($system)->create(['url' => 'https://example.test/containers/payments']);
    SecurityEvent::factory()->forContainer($container)->create(['file_path' => 'src/Payments.php']);

    $provider = RepositoryProvider::factory()->azureRepos()->create(['base_url' => 'https://dev.azure.com/acme']);

    $system->repositoryMappings()->create([
        'repository_provider_id' => $provider->id,
        'repository_name' => 'payments-api',
        'repository_url' => 'https://dev.azure.com/acme/_git/payments-api',
        'default_branch' => 'main',
        'path_prefix' => 'src',
        'created_by_user_id' => null,
        'metadata' => null,
    ]);

    $system->trackerProjectLinks()->create([
        'tracker_id' => 'jira',
        'project_key' => 'PAY',
        'project_name' => 'Payments',
        'is_default' => true,
        'created_by_user_id' => null,
        'metadata' => null,
    ]);

    $indicators = app(ContextQualityService::class)->forSoftwareSystem($system);

    expect(indicatorMessage($indicators, 'Code location'))->toBe('Code location ready')
        ->and(indicatorMessage($indicators, 'Tracker mapping'))->toBe('Tracker mapping ready')
        ->and(indicatorMessage($indicators, 'Source URL'))->toBe('Source URL available');
});

it('reports a ready code location from the container’s own identity, with no mapping', function () {
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create([
        'url' => 'https://dev.azure.com/EESC-CoR/PW-API/_git/consultation-api',
        'metadata' => [
            'source' => ['provider' => 'azure-repos'],
            'code' => ['default_branch' => 'main'],
        ],
    ]);
    SecurityEvent::factory()->forContainer($container)->create(['file_path' => 'src/App.cs']);

    $indicators = app(ContextQualityService::class)->forSecurityContainer($container);

    expect(indicatorMessage($indicators, 'Code location'))->toBe('Code location ready');
});

it('reports missing code location when file paths exist but no identity or mapping is available', function () {
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create(['url' => null, 'metadata' => null]);

    SecurityEvent::factory()->forContainer($container)->create(['file_path' => 'src/app.php']);

    $indicators = app(ContextQualityService::class)->forSoftwareSystem($system);

    expect(indicatorMessage($indicators, 'Code location'))->toBe('Code location missing');
});

it('reports missing tracker project mapping when none exists', function () {
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create();

    $indicators = app(ContextQualityService::class)->forSecurityContainer($container);

    expect(indicatorMessage($indicators, 'Tracker mapping'))->toBe('Missing tracker mapping');
});

/**
 * @param  list<array{label: string, message: string, state: string, color: string, url: ?string}>  $indicators
 */
function indicatorMessage(array $indicators, string $label): string
{
    foreach ($indicators as $indicator) {
        if ($indicator['label'] === $label) {
            return $indicator['message'];
        }
    }

    return '';
}
