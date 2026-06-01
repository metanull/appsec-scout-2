<?php

use App\Credentials\Vault;
use App\Filament\Pages\ProfileIntegrationsPage;
use App\Integrations\IntegrationSettingsRepository;
use App\Models\User;
use App\Trackers\Registry as TrackerRegistry;
use App\Trackers\WorkItemFormOptions;
use Illuminate\Support\Facades\Log;
use Tests\Fakes\FakeTracker;

it('requires personal tracker credentials for interactive work item forms', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

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
    $this->actingAs($user);

    expect(ProfileIntegrationsPage::getUrl())->toContain('/profile/integrations');
});

it('returns no tracker options and logs an error when no tracker is enabled', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    bindFakeTrackerForWorkItemForms(enabled: false);
    Log::spy();

    $options = app(WorkItemFormOptions::class)->trackerOptions();

    expect($options)->toBe([]);

    Log::shouldHaveReceived('error')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'No enabled trackers available for work item forms.'
            && array_key_exists('available_tracker_ids', $context)
            && in_array('fake-tracker', $context['available_tracker_ids'], true));
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
