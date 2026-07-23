<?php

use App\Models\Enums\EventState;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\SecurityEvents\LocalFindingLinkCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function azdoFinding(array $overrides = []): LocalFinding
{
    $system = SoftwareSystem::factory()->create([
        'url' => 'https://dev.azure.com/EESC-CoR/PW-API',
    ]);
    $container = SecurityContainer::factory()->forSystem($system)->create([
        'url' => 'https://dev.azure.com/EESC-CoR/PW-API/_git/consultation-api',
        'metadata' => [
            'source' => ['provider' => 'azure-repos'],
            'code' => ['default_branch' => 'main'],
        ],
    ]);

    return $container->localFindings()->create(array_merge([
        'kind' => LocalFinding::KIND_VULNERABILITY,
        'rule_id' => 'AB123',
        'title' => 'Hard-coded secret in config',
        'file_path' => 'src/App/appsettings.json',
        'start_line' => 55,
        'end_line' => 60,
        'status' => EventState::Open,
        'software_system_id' => $system->id,
        'metadata' => ['helpUri' => 'https://rules.example/AB123'],
    ], $overrides));
}

it('builds Source system, Code repository and Code source-file links from the container’s own identity', function () {
    $finding = azdoFinding();

    $urls = array_column(app(LocalFindingLinkCatalog::class)->build($finding), 'url');

    expect($urls)->toContain('https://dev.azure.com/EESC-CoR/PW-API')
        ->and($urls)->toContain('https://dev.azure.com/EESC-CoR/PW-API/_git/consultation-api')
        ->and($urls)->toContain('https://dev.azure.com/EESC-CoR/PW-API/_git/consultation-api?path=/src/App/appsettings.json&version=GBmain&line=55&lineEnd=60&lineStartColumn=1&lineEndColumn=1')
        ->and($urls)->toContain('https://rules.example/AB123');
});

it('does not duplicate the repository URL shared by the source and code links', function () {
    $finding = azdoFinding();

    $urls = array_column(app(LocalFindingLinkCatalog::class)->build($finding), 'url');
    $repoUrl = 'https://dev.azure.com/EESC-CoR/PW-API/_git/consultation-api';

    expect(count(array_filter($urls, fn (string $u): bool => $u === $repoUrl)))->toBe(1);
});

it('includes linked work items as tracker links', function () {
    $finding = azdoFinding();
    $finding->workItemLinks()->create([
        'tracker_id' => 'jira',
        'work_item_id' => 'SEC-42',
        'work_item_url' => 'https://jira.example.com/browse/SEC-42',
        'work_item_title' => 'Rotate the secret',
    ]);

    $catalog = app(LocalFindingLinkCatalog::class)->build($finding->fresh());

    $trackerLinks = array_filter($catalog, fn (array $l): bool => $l['kind'] === 'tracker');

    expect($trackerLinks)->not->toBeEmpty()
        ->and(array_column($catalog, 'url'))->toContain('https://jira.example.com/browse/SEC-42');
});

it('returns an empty catalog when the finding has no linkable identity', function () {
    $container = SecurityContainer::factory()->create(['url' => null, 'metadata' => null]);
    $finding = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'r1',
        'title' => 'x',
        'file_path' => 'a',
        'status' => EventState::Open,
    ]);

    expect(app(LocalFindingLinkCatalog::class)->build($finding))->toBeEmpty();
});
