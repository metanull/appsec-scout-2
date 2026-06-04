<?php

use App\Credentials\Vault;
use App\Filament\Pages\ProfileIntegrationsPage;
use App\Integrations\IntegrationSettingsRepository;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\User;
use App\Trackers\Registry as TrackerRegistry;
use App\Trackers\WorkItemFormOptions;
use Filament\Forms\Components\Select;
use Illuminate\Support\Facades\Auth;
use Tests\Fakes\FakeTracker;

it('requires personal tracker credentials for interactive work item forms', function () {
    $user = User::factory()->create();
    Auth::login($user);

    bindFakeTrackerForWorkItemForms();

    app(Vault::class)->set('fake-tracker.token', null, 'system-token');

    $missingWithoutPersonal = app(WorkItemFormOptions::class)->missingCredentialLabelsForTracker('fake-tracker');

    expect($missingWithoutPersonal)->toBe(['Token']);

    app(Vault::class)->set('fake-tracker.token', $user->id, 'user-token');

    $missingWithPersonal = app(WorkItemFormOptions::class)->missingCredentialLabelsForTracker('fake-tracker');

    expect($missingWithPersonal)->toBe([]);
});

it('keeps profile integrations page route available for guidance links', function () {
    $user = User::factory()->create();
    Auth::login($user);

    expect(ProfileIntegrationsPage::getUrl())->toContain('/profile/integrations');
});

it('lists registered trackers for operator work item forms even when system scheduling is disabled', function () {
    $user = User::factory()->create();
    Auth::login($user);

    bindFakeTrackerForWorkItemForms(enabled: false);

    $options = app(WorkItemFormOptions::class)->trackerOptions();

    expect($options)->toHaveKey('fake-tracker')
        ->and($options['fake-tracker'])->toBe('Fake Tracker');
});

it('prefills create form tracker and project from jira mapping defaults', function () {
    $user = User::factory()->create();
    Auth::login($user);

    $system = SoftwareSystem::factory()->create();
    $event = SecurityEvent::factory()->create([
        'software_system_id' => $system->id,
        'container_id' => null,
    ]);

    $system->trackerProjectLinks()->create([
        'tracker_id' => 'jira',
        'project_key' => 'APP',
        'project_name' => 'Application',
        'is_default' => true,
    ]);

    $schema = app(WorkItemFormOptions::class)->createSchema([$event]);

    $tracker = collect($schema)->first(fn (mixed $component): bool => $component instanceof Select && $component->getName() === 'tracker');
    $project = collect($schema)->first(fn (mixed $component): bool => $component instanceof Select && $component->getName() === 'project');

    assert($tracker instanceof Select);
    assert($project instanceof Select);

    expect($tracker)->toBeInstanceOf(Select::class)
        ->and($project)->toBeInstanceOf(Select::class)
        ->and($tracker->getDefaultState())->toBe('jira')
        ->and($project->getDefaultState())->toBe('APP');
});

it('prefills link form tracker and project from github container mapping defaults', function () {
    $user = User::factory()->create();
    Auth::login($user);

    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create();

    $event = SecurityEvent::factory()->create([
        'software_system_id' => $system->id,
        'container_id' => $container->id,
    ]);

    $container->trackerProjectLinks()->create([
        'tracker_id' => 'github',
        'project_key' => 'octo-org/appsec-scout',
        'project_name' => 'AppSec Scout',
        'is_default' => true,
    ]);

    $schema = app(WorkItemFormOptions::class)->linkSchema([$event]);

    $tracker = collect($schema)->first(fn (mixed $component): bool => $component instanceof Select && $component->getName() === 'tracker');
    $project = collect($schema)->first(fn (mixed $component): bool => $component instanceof Select && $component->getName() === 'project');

    assert($tracker instanceof Select);
    assert($project instanceof Select);

    expect($tracker)->toBeInstanceOf(Select::class)
        ->and($project)->toBeInstanceOf(Select::class)
        ->and($tracker->getDefaultState())->toBe('github')
        ->and($project->getDefaultState())->toBe('octo-org/appsec-scout');
});

it('returns no grouped default when selected alerts resolve to conflicting projects', function () {
    $user = User::factory()->create();
    Auth::login($user);

    $systemA = SoftwareSystem::factory()->create();
    $systemB = SoftwareSystem::factory()->create();

    $eventA = SecurityEvent::factory()->create(['software_system_id' => $systemA->id, 'container_id' => null]);
    $eventB = SecurityEvent::factory()->create(['software_system_id' => $systemB->id, 'container_id' => null]);

    $systemA->trackerProjectLinks()->create([
        'tracker_id' => 'jira',
        'project_key' => 'APP',
        'is_default' => true,
    ]);

    $systemB->trackerProjectLinks()->create([
        'tracker_id' => 'jira',
        'project_key' => 'OPS',
        'is_default' => true,
    ]);

    $result = app(WorkItemFormOptions::class)->trackerDefaultForEvents([$eventA, $eventB], 'jira');

    expect($result)->toBeNull();
});

it('returns no default when no accepted mapping is available', function () {
    $user = User::factory()->create();
    Auth::login($user);

    $event = SecurityEvent::factory()->create();

    $result = app(WorkItemFormOptions::class)->trackerDefaultForEvents([$event], 'github');

    expect($result)->toBeNull();
});

function bindFakeTrackerForWorkItemForms(bool $enabled = true): void
{
    app()->bind('appsec-scout.tracker.fake', fn () => new FakeTracker);
    app()->tag(['appsec-scout.tracker.fake'], 'appsec-scout.tracker');

    app(IntegrationSettingsRepository::class)->update('tracker', 'fake-tracker', [
        'enabled' => $enabled,
        'fetch_interval_minutes' => 30,
        'service_user_id' => null,
    ]);

    app()->forgetInstance(TrackerRegistry::class);
}
