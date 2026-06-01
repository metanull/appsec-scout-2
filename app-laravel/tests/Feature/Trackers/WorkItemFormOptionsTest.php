<?php

use App\Credentials\Vault;
use App\Filament\Pages\ProfileIntegrationsPage;
use App\Integrations\IntegrationSettingsRepository;
use App\Models\User;
use App\Trackers\Registry as TrackerRegistry;
use App\Trackers\WorkItemFormOptions;
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

function bindFakeTrackerForWorkItemForms(): void
{
    app()->bind('appsec-scout.tracker.fake', fn () => new FakeTracker);
    app()->tag(['appsec-scout.tracker.fake'], 'appsec-scout.tracker');

    app(IntegrationSettingsRepository::class)->update('tracker', 'fake-tracker', [
        'enabled' => true,
        'fetch_interval_minutes' => 30,
        'service_user_id' => null,
    ]);

    app()->forgetInstance(TrackerRegistry::class);
}
