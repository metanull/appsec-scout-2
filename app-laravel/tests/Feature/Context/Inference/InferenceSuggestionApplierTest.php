<?php

use App\Context\Inference\InferenceSuggestionApplier;
use App\Models\Enums\InferenceSuggestionStatus;
use App\Models\InferenceSuggestion;
use App\Models\RepositoryMapping;
use App\Models\RepositoryProvider;
use App\Models\SecurityContainer;
use App\Models\SecurityContainerLink;
use App\Models\SoftwareSystem;
use App\Models\SoftwareSystemLink;
use App\Models\TrackerProjectLink;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('applies virtual memberships repository mappings and tracker links', function () {
    $plan = inferenceApplierUser(['Plan']);
    $system = SoftwareSystem::factory()->create(['name' => 'Payments']);
    $container = SecurityContainer::factory()->forSystem($system)->create(['name' => 'Payments container']);
    $systemLink = SoftwareSystemLink::factory()->create(['name' => 'Payments cluster']);
    $containerLink = SecurityContainerLink::factory()->create(['name' => 'Payments containers']);
    $provider = RepositoryProvider::factory()->azureRepos()->create([
        'base_url' => 'https://dev.azure.com/acme',
    ]);

    $systemSuggestion = InferenceSuggestion::factory()->forSubject($system)->forTarget($systemLink)->create([
        'suggestion_type' => InferenceSuggestion::TYPE_VIRTUAL_SYSTEM_MEMBERSHIP,
        'proposed_action' => InferenceSuggestion::ACTION_ADD_SYSTEM_TO_VIRTUAL_SYSTEM,
        'status' => InferenceSuggestionStatus::Pending,
        'evidence' => [
            'matched_key' => 'azdo.project.name',
            'matched_value' => 'Payments',
            'reason' => 'Exact project name match.',
        ],
    ]);

    $containerSuggestion = InferenceSuggestion::factory()->forSubject($container)->forTarget($containerLink)->create([
        'suggestion_type' => InferenceSuggestion::TYPE_VIRTUAL_CONTAINER_MEMBERSHIP,
        'proposed_action' => InferenceSuggestion::ACTION_ADD_CONTAINER_TO_VIRTUAL_CONTAINER,
        'status' => InferenceSuggestionStatus::Pending,
        'evidence' => [
            'matched_key' => 'azdo.repository.remote_url',
            'matched_value' => 'https://dev.azure.com/acme/security/_git/payments',
            'reason' => 'Exact repository URL match.',
        ],
    ]);

    $repositorySuggestion = InferenceSuggestion::factory()->forSubject($container)->forTarget($provider)->create([
        'suggestion_type' => InferenceSuggestion::TYPE_REPOSITORY_MAPPING,
        'proposed_action' => InferenceSuggestion::ACTION_CREATE_REPOSITORY_MAPPING,
        'status' => InferenceSuggestionStatus::Pending,
        'evidence' => [
            'repository_provider_id' => $provider->id,
            'repository_name' => 'payments-api',
            'default_branch' => 'main',
            'path_prefix' => 'services/payments',
            'reason' => 'Exact repository URL indicates a mapping.',
        ],
    ]);

    $trackerSuggestion = InferenceSuggestion::factory()->forSubject($system)->forTarget(null)->create([
        'suggestion_type' => InferenceSuggestion::TYPE_TRACKER_PROJECT_MAPPING,
        'proposed_action' => InferenceSuggestion::ACTION_CREATE_TRACKER_PROJECT_LINK,
        'status' => InferenceSuggestionStatus::Pending,
        'evidence' => [
            'tracker_id' => 'jira',
            'matched_value' => 'PAY',
            'project_name' => 'Payments',
            'reason' => 'Exact Jira project key.',
        ],
    ]);

    app(InferenceSuggestionApplier::class)->accept($systemSuggestion, $plan);
    app(InferenceSuggestionApplier::class)->accept($containerSuggestion, $plan);
    app(InferenceSuggestionApplier::class)->accept($repositorySuggestion, $plan);
    app(InferenceSuggestionApplier::class)->accept($trackerSuggestion, $plan);

    expect($systemLink->fresh()->members()->whereKey($system->id)->exists())->toBeTrue()
        ->and($containerLink->fresh()->members()->whereKey($container->id)->exists())->toBeTrue()
        ->and(RepositoryMapping::query()->where('owner_type', SecurityContainer::class)->where('owner_id', $container->id)->exists())->toBeTrue()
        ->and(TrackerProjectLink::query()->where('owner_type', SoftwareSystem::class)->where('owner_id', $system->id)->where('tracker_id', 'jira')->where('project_key', 'PAY')->exists())->toBeTrue()
        ->and(InferenceSuggestion::query()->findOrFail($systemSuggestion->id)->status)->toBe(InferenceSuggestionStatus::Accepted)
        ->and(InferenceSuggestion::query()->findOrFail($containerSuggestion->id)->status)->toBe(InferenceSuggestionStatus::Accepted)
        ->and(InferenceSuggestion::query()->findOrFail($repositorySuggestion->id)->status)->toBe(InferenceSuggestionStatus::Accepted)
        ->and(InferenceSuggestion::query()->findOrFail($trackerSuggestion->id)->status)->toBe(InferenceSuggestionStatus::Accepted);
});

it('does not duplicate durable mappings on repeat acceptance', function () {
    $plan = inferenceApplierUser(['Plan']);
    $system = SoftwareSystem::factory()->create(['name' => 'Billing']);
    $link = SoftwareSystemLink::factory()->create(['name' => 'Billing cluster']);

    $suggestion = InferenceSuggestion::factory()->forSubject($system)->forTarget($link)->create([
        'suggestion_type' => InferenceSuggestion::TYPE_VIRTUAL_SYSTEM_MEMBERSHIP,
        'proposed_action' => InferenceSuggestion::ACTION_ADD_SYSTEM_TO_VIRTUAL_SYSTEM,
        'status' => InferenceSuggestionStatus::Pending,
        'evidence' => [
            'matched_key' => 'azdo.project.name',
            'matched_value' => 'Billing',
            'reason' => 'Exact project name match.',
        ],
    ]);

    app(InferenceSuggestionApplier::class)->accept($suggestion, $plan);

    expect(fn () => app(InferenceSuggestionApplier::class)->accept($suggestion->fresh(), $plan))
        ->toThrow(ValidationException::class);

    expect($link->fresh()->members()->whereKey($system->id)->count())->toBe(1);
});

it('rolls back failed accept attempts and keeps the suggestion pending', function () {
    $plan = inferenceApplierUser(['Plan']);
    $system = SoftwareSystem::factory()->create();
    $provider = RepositoryProvider::factory()->azureRepos()->create([
        'base_url' => 'https://dev.azure.com/acme',
    ]);

    $suggestion = InferenceSuggestion::factory()->forSubject($system)->forTarget($provider)->create([
        'suggestion_type' => InferenceSuggestion::TYPE_REPOSITORY_MAPPING,
        'proposed_action' => InferenceSuggestion::ACTION_CREATE_REPOSITORY_MAPPING,
        'status' => InferenceSuggestionStatus::Pending,
        'evidence' => [
            'repository_provider_id' => $provider->id,
            'repository_name' => 'payments-api',
            'default_branch' => 'main',
            'path_prefix' => 'services/payments',
        ],
    ]);

    app(InferenceSuggestionApplier::class)->accept($suggestion, $plan);

    $duplicate = InferenceSuggestion::factory()->forSubject($system)->forTarget($provider)->create([
        'suggestion_type' => InferenceSuggestion::TYPE_REPOSITORY_MAPPING,
        'proposed_action' => InferenceSuggestion::ACTION_CREATE_REPOSITORY_MAPPING,
        'status' => InferenceSuggestionStatus::Pending,
        'evidence' => [
            'repository_provider_id' => $provider->id,
            'repository_name' => 'payments-api',
            'default_branch' => 'main',
            'path_prefix' => 'services/payments',
        ],
    ]);

    expect(fn () => app(InferenceSuggestionApplier::class)->accept($duplicate, $plan, [
        'repository_provider_id' => 999999,
    ]))->toThrow(ValidationException::class);

    expect(InferenceSuggestion::query()->findOrFail($duplicate->id)->status)->toBe(InferenceSuggestionStatus::Pending)
        ->and(RepositoryMapping::query()->where('owner_type', SoftwareSystem::class)->where('owner_id', $system->id)->count())->toBe(1);
});

it('rejects unauthorized reviewers', function () {
    $reader = inferenceApplierUser(['Reader']);
    $system = SoftwareSystem::factory()->create();

    $suggestion = InferenceSuggestion::factory()->forSubject($system)->forTarget(null)->create([
        'suggestion_type' => InferenceSuggestion::TYPE_TRACKER_PROJECT_MAPPING,
        'proposed_action' => InferenceSuggestion::ACTION_CREATE_TRACKER_PROJECT_LINK,
        'status' => InferenceSuggestionStatus::Pending,
        'evidence' => [
            'tracker_id' => 'jira',
            'matched_value' => 'PAY',
        ],
    ]);

    expect(fn () => app(InferenceSuggestionApplier::class)->accept($suggestion, $reader))->toThrow(AuthorizationException::class);
});

function inferenceApplierUser(array $roles): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);

    $user->syncRoles($roles);

    return $user;
}
