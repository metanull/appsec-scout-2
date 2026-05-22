<?php

use App\Integrations\IntegrationSettingsRepository;
use App\Trackers\Contracts\Tracker;
use App\Trackers\Registry;
use App\Trackers\ValueObjects\TestResult;
use App\Trackers\ValueObjects\TrackerCapabilities;
use Tests\Fakes\FakeTracker;

it('returns enabled trackers from registry', function () {
    $fake = new FakeTracker;

    $this->app->bind('appsec-scout.tracker.fake', fn () => $fake);
    $this->app->tag(['appsec-scout.tracker.fake'], 'appsec-scout.tracker');

    app(IntegrationSettingsRepository::class)->update('tracker', 'fake-tracker', [
        'enabled' => true,
        'fetch_interval_minutes' => 30,
        'service_user_id' => null,
    ]);

    $registry = new Registry($this->app, app(IntegrationSettingsRepository::class));

    expect($registry->enabled())->toHaveCount(1)
        ->and($registry->enabled()[0]->id())->toBe('fake-tracker');
});

it('excludes disabled trackers', function () {
    $fake = new FakeTracker;

    $this->app->bind('appsec-scout.tracker.fake', fn () => $fake);
    $this->app->tag(['appsec-scout.tracker.fake'], 'appsec-scout.tracker');

    app(IntegrationSettingsRepository::class)->update('tracker', 'fake-tracker', [
        'enabled' => false,
        'fetch_interval_minutes' => 30,
        'service_user_id' => null,
    ]);

    $registry = new Registry($this->app, app(IntegrationSettingsRepository::class));

    expect($registry->enabled())->toBeEmpty();
});

it('finds tracker by id', function () {
    $fake = new FakeTracker;

    $this->app->bind('appsec-scout.tracker.fake', fn () => $fake);
    $this->app->tag(['appsec-scout.tracker.fake'], 'appsec-scout.tracker');

    app(IntegrationSettingsRepository::class)->update('tracker', 'fake-tracker', [
        'enabled' => true,
        'fetch_interval_minutes' => 30,
        'service_user_id' => null,
    ]);

    $registry = new Registry($this->app, app(IntegrationSettingsRepository::class));

    expect($registry->find('fake-tracker'))->toBe($fake)
        ->and($registry->find('nonexistent'))->toBeNull();
});

it('registered trackers satisfy the tracker contract', function () {
    $fake = new FakeTracker;

    $this->app->bind('appsec-scout.tracker.fake', fn () => $fake);
    $this->app->tag(['appsec-scout.tracker.fake'], 'appsec-scout.tracker');

    app(IntegrationSettingsRepository::class)->update('tracker', 'fake-tracker', [
        'enabled' => true,
        'fetch_interval_minutes' => 30,
        'service_user_id' => null,
    ]);

    $registry = new Registry($this->app, app(IntegrationSettingsRepository::class));

    foreach ($registry->enabled() as $tracker) {
        expect($tracker)->toBeInstanceOf(Tracker::class)
            ->and($tracker->id())->toBeString()->not->toBeEmpty()
            ->and($tracker->displayName())->toBeString()->not->toBeEmpty()
            ->and($tracker->capabilities())->toBeInstanceOf(TrackerCapabilities::class)
            ->and($tracker->requiredCredentialKeys())->toBeArray()
            ->and($tracker->testConnection())->toBeInstanceOf(TestResult::class);
    }
});

it('fake tracker test connection returns success', function () {
    $result = (new FakeTracker)->testConnection();

    expect($result->ok)->toBeTrue()
        ->and($result->error)->toBeNull();
});

it('fake tracker test connection returns failure', function () {
    $result = (new FakeTracker)->withConnectionFailure()->testConnection();

    expect($result->ok)->toBeFalse()
        ->and($result->error)->not->toBeNull();
});
