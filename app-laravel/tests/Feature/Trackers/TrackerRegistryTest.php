<?php

use App\Trackers\Contracts\Tracker;
use App\Trackers\Registry;
use App\Trackers\ValueObjects\TestResult;
use App\Trackers\ValueObjects\TrackerCapabilities;
use Tests\Fakes\FakeTracker;

it('returns every registered tracker from the registry', function () {
    $fake = new FakeTracker;

    $this->app->bind('appsec-scout.tracker.fake', fn () => $fake);
    $this->app->tag(['appsec-scout.tracker.fake'], 'appsec-scout.tracker');

    $registry = new Registry($this->app);

    expect(collect($registry->all())->map->id()->all())->toContain('fake-tracker');
});

it('finds tracker by id', function () {
    $fake = new FakeTracker;

    $this->app->bind('appsec-scout.tracker.fake', fn () => $fake);
    $this->app->tag(['appsec-scout.tracker.fake'], 'appsec-scout.tracker');

    $registry = new Registry($this->app);

    expect($registry->find('fake-tracker'))->toBe($fake)
        ->and($registry->find('nonexistent'))->toBeNull();
});

it('registered trackers satisfy the tracker contract', function () {
    $fake = new FakeTracker;

    $this->app->bind('appsec-scout.tracker.fake', fn () => $fake);
    $this->app->tag(['appsec-scout.tracker.fake'], 'appsec-scout.tracker');

    $registry = new Registry($this->app);

    foreach ($registry->all() as $tracker) {
        expect($tracker)->toBeInstanceOf(Tracker::class)
            ->and($tracker->id())->toBeString()->not->toBeEmpty()
            ->and($tracker->displayName())->toBeString()->not->toBeEmpty()
            ->and($tracker->capabilities())->toBeInstanceOf(TrackerCapabilities::class)
            ->and($tracker->credentialFields())->toBeArray()
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
