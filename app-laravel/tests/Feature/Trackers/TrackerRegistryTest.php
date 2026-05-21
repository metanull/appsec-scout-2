<?php

use App\Trackers\Contracts\Tracker;
use App\Trackers\Registry;
use App\Trackers\ValueObjects\TestResult;
use App\Trackers\ValueObjects\TrackerCapabilities;
use Tests\Fakes\FakeTracker;

it('returns enabled trackers from registry', function () {
    $fake = new FakeTracker;

    $this->app->bind('appsec-scout.tracker.fake', fn () => $fake);
    $this->app->tag(['appsec-scout.tracker.fake'], 'appsec-scout.tracker');

    config(['integration_settings.fake-tracker.enabled' => true]);

    $registry = new Registry($this->app);

    expect($registry->enabled())->toHaveCount(1)
        ->and($registry->enabled()[0]->id())->toBe('fake-tracker');
});

it('excludes disabled trackers', function () {
    $fake = new FakeTracker;

    $this->app->bind('appsec-scout.tracker.fake', fn () => $fake);
    $this->app->tag(['appsec-scout.tracker.fake'], 'appsec-scout.tracker');

    config(['integration_settings.fake-tracker.enabled' => false]);

    $registry = new Registry($this->app);

    expect($registry->enabled())->toBeEmpty();
});

it('finds tracker by id', function () {
    $fake = new FakeTracker;

    $this->app->bind('appsec-scout.tracker.fake', fn () => $fake);
    $this->app->tag(['appsec-scout.tracker.fake'], 'appsec-scout.tracker');

    config(['integration_settings.fake-tracker.enabled' => true]);

    $registry = new Registry($this->app);

    expect($registry->find('fake-tracker'))->toBe($fake)
        ->and($registry->find('nonexistent'))->toBeNull();
});

it('registered trackers satisfy the tracker contract', function () {
    $fake = new FakeTracker;

    $this->app->bind('appsec-scout.tracker.fake', fn () => $fake);
    $this->app->tag(['appsec-scout.tracker.fake'], 'appsec-scout.tracker');

    config(['integration_settings.fake-tracker.enabled' => true]);

    $registry = new Registry($this->app);

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
