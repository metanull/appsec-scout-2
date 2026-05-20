<?php

use App\Sources\Contracts\Source;
use App\Sources\Registry;
use App\Sources\ValueObjects\SourceCapabilities;
use App\Sources\ValueObjects\TestResult;
use Tests\Fakes\FakeSource;

it('returns enabled sources from registry', function () {
    $fake = new FakeSource;

    $this->app->bind('appsec-scout.source.fake', fn () => $fake);
    $this->app->tag(['appsec-scout.source.fake'], 'appsec-scout.source');

    config(['integration_settings.fake.enabled' => true]);

    $registry = new Registry($this->app);

    expect($registry->enabled())->toHaveCount(1)
        ->and($registry->enabled()[0]->id())->toBe('fake');
});

it('excludes disabled sources', function () {
    $fake = new FakeSource;

    $this->app->bind('appsec-scout.source.fake', fn () => $fake);
    $this->app->tag(['appsec-scout.source.fake'], 'appsec-scout.source');

    config(['integration_settings.fake.enabled' => false]);

    $registry = new Registry($this->app);

    expect($registry->enabled())->toBeEmpty();
});

it('finds source by id', function () {
    $fake = new FakeSource;

    $this->app->bind('appsec-scout.source.fake', fn () => $fake);
    $this->app->tag(['appsec-scout.source.fake'], 'appsec-scout.source');

    config(['integration_settings.fake.enabled' => true]);

    $registry = new Registry($this->app);

    expect($registry->find('fake'))->toBe($fake)
        ->and($registry->find('nonexistent'))->toBeNull();
});

// Contract test: every implementation must satisfy the Source interface
it('fake source satisfies Source contract', function () {
    $fake = new FakeSource;

    expect($fake)->toBeInstanceOf(Source::class)
        ->and($fake->id())->toBeString()->not->toBeEmpty()
        ->and($fake->displayName())->toBeString()->not->toBeEmpty()
        ->and($fake->capabilities())->toBeInstanceOf(SourceCapabilities::class)
        ->and($fake->requiredCredentialKeys())->toBeArray()
        ->and($fake->testConnection())->toBeInstanceOf(TestResult::class);
});

it('fake source test connection returns success', function () {
    $result = (new FakeSource)->testConnection();

    expect($result->ok)->toBeTrue()
        ->and($result->error)->toBeNull();
});

it('fake source test connection returns failure', function () {
    $result = (new FakeSource)->withConnectionFailure()->testConnection();

    expect($result->ok)->toBeFalse()
        ->and($result->error)->not->toBeNull();
});
