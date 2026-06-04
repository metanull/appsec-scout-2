<?php

use App\Context\Inference\FuzzyMappingSuggestionGenerator;
use App\Models\Enums\InferenceSuggestionStatus;
use App\Models\InferenceSuggestion;
use App\Models\RepositoryProvider;
use App\Models\SecurityContainer;
use App\Models\SecurityContainerLink;
use App\Models\SoftwareSystem;
use App\Models\SoftwareSystemLink;
use App\Sources\Context\SourceContextFacts;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('generates all supported suggestion types from deterministic context facts', function () {
    $systemOne = SoftwareSystem::factory()->create([
        'metadata' => metadataWith(SourceContextFacts::AZDO_PROJECT_NAME, 'Payments Project'),
    ]);
    $systemTwo = SoftwareSystem::factory()->create([
        'metadata' => metadataWith(SourceContextFacts::AZDO_PROJECT_NAME, 'Payments Project'),
    ]);

    $virtualSystem = SoftwareSystemLink::factory()->create();
    $virtualSystem->members()->attach($systemOne->id, ['sort_order' => 1]);

    $containerOne = SecurityContainer::factory()->forSystem($systemOne)->create([
        'metadata' => metadataWith(SourceContextFacts::AZDO_REPOSITORY_REMOTE_URL, 'https://dev.azure.com/acme/security/_git/payments-api'),
    ]);
    $containerTwo = SecurityContainer::factory()->forSystem($systemTwo)->create([
        'metadata' => metadataWith(SourceContextFacts::AZDO_REPOSITORY_REMOTE_URL, 'https://dev.azure.com/acme/security/_git/payments-api'),
    ]);

    $virtualContainer = SecurityContainerLink::factory()->create();
    $virtualContainer->members()->attach($containerOne->id, ['sort_order' => 1]);

    RepositoryProvider::factory()->azureRepos()->create([
        'base_url' => 'https://dev.azure.com/acme',
    ]);

    $containerThree = SecurityContainer::factory()->forSystem($systemOne)->create([
        'metadata' => metadataWith(SourceContextFacts::AZDO_REPOSITORY_WEB_URL, 'https://dev.azure.com/acme/security/_git/ledger-api'),
    ]);

    $systemThree = SoftwareSystem::factory()->create([
        'metadata' => metadataWith(SourceContextFacts::TRACKER_JIRA_PROJECT_KEY, 'PAY'),
    ]);

    $created = app(FuzzyMappingSuggestionGenerator::class)->generate();

    expect($created)->toBeGreaterThanOrEqual(4)
        ->and(InferenceSuggestion::query()->where('suggestion_type', 'virtual_system_membership_candidate')->exists())->toBeTrue()
        ->and(InferenceSuggestion::query()->where('suggestion_type', 'virtual_container_membership_candidate')->exists())->toBeTrue()
        ->and(InferenceSuggestion::query()->where('suggestion_type', 'repository_mapping_candidate')->exists())->toBeTrue()
        ->and(InferenceSuggestion::query()->where('suggestion_type', 'tracker_project_mapping_candidate')->exists())->toBeTrue();

    $repositorySuggestion = InferenceSuggestion::query()
        ->where('suggestion_type', 'repository_mapping_candidate')
        ->where('subject_type', SecurityContainer::class)
        ->where('subject_id', $containerThree->id)
        ->first();

    expect($repositorySuggestion)->not->toBeNull()
        ->and($repositorySuggestion?->proposed_action)->toBe('create_repository_mapping');

    $trackerSuggestion = InferenceSuggestion::query()
        ->where('suggestion_type', 'tracker_project_mapping_candidate')
        ->where('subject_type', SoftwareSystem::class)
        ->where('subject_id', $systemThree->id)
        ->first();

    expect($trackerSuggestion)->not->toBeNull()
        ->and($trackerSuggestion?->proposed_action)->toBe('create_tracker_project_link');

    app(FuzzyMappingSuggestionGenerator::class)->generate();

    expect(InferenceSuggestion::query()->count())->toBe($created);
});

it('does not recreate rejected suggestions with the same evidence fingerprint', function () {
    $systemOne = SoftwareSystem::factory()->create([
        'metadata' => metadataWith(SourceContextFacts::AZDO_PROJECT_NAME, 'Core Platform'),
    ]);
    $systemTwo = SoftwareSystem::factory()->create([
        'metadata' => metadataWith(SourceContextFacts::AZDO_PROJECT_NAME, 'Core Platform'),
    ]);

    $virtualSystem = SoftwareSystemLink::factory()->create();
    $virtualSystem->members()->attach($systemOne->id, ['sort_order' => 1]);

    app(FuzzyMappingSuggestionGenerator::class)->generate();

    $suggestion = InferenceSuggestion::query()
        ->where('suggestion_type', 'virtual_system_membership_candidate')
        ->where('subject_id', $systemTwo->id)
        ->firstOrFail();

    $suggestion->forceFill([
        'status' => InferenceSuggestionStatus::Rejected,
        'review_note' => 'Not part of the same remediation scope.',
    ])->save();

    app(FuzzyMappingSuggestionGenerator::class)->generate();

    $matches = InferenceSuggestion::query()
        ->where('suggestion_type', 'virtual_system_membership_candidate')
        ->where('evidence_fingerprint', $suggestion->evidence_fingerprint)
        ->count();

    expect($matches)->toBe(1)
        ->and(InferenceSuggestion::query()->where('status', InferenceSuggestionStatus::Pending)->count())->toBe(0);
});

/**
 * @return array<string, mixed>
 */
function metadataWith(string $key, string $value): array
{
    return SourceContextFacts::set([], $key, $value);
}
