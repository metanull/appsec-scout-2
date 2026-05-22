<?php

use App\Integrations\IntegrationSettingsRepository;
use App\Sources\Contracts\Source;
use App\Sources\Registry;
use App\Sources\ValueObjects\SourceCapabilities;
use App\Sources\ValueObjects\TestResult;
use Tests\Fakes\FakeSource;

it('returns enabled sources from registry', function () {
    $fake = new FakeSource;

    $this->app->bind('appsec-scout.source.fake', fn () => $fake);
    $this->app->tag(['appsec-scout.source.fake'], 'appsec-scout.source');

    app(IntegrationSettingsRepository::class)->update('source', 'fake', [
        'enabled' => true,
        'fetch_interval_minutes' => 30,
        'service_user_id' => null,
    ]);

    $registry = new Registry($this->app, app(IntegrationSettingsRepository::class));

    expect($registry->enabled())->toHaveCount(1)
        ->and($registry->enabled()[0]->id())->toBe('fake');
});

it('excludes disabled sources', function () {
    $fake = new FakeSource;

    $this->app->bind('appsec-scout.source.fake', fn () => $fake);
    $this->app->tag(['appsec-scout.source.fake'], 'appsec-scout.source');

    app(IntegrationSettingsRepository::class)->update('source', 'fake', [
        'enabled' => false,
        'fetch_interval_minutes' => 30,
        'service_user_id' => null,
    ]);

    $registry = new Registry($this->app, app(IntegrationSettingsRepository::class));

    expect($registry->enabled())->toBeEmpty();
});

it('finds source by id', function () {
    $fake = new FakeSource;

    $this->app->bind('appsec-scout.source.fake', fn () => $fake);
    $this->app->tag(['appsec-scout.source.fake'], 'appsec-scout.source');

    app(IntegrationSettingsRepository::class)->update('source', 'fake', [
        'enabled' => true,
        'fetch_interval_minutes' => 30,
        'service_user_id' => null,
    ]);

    $registry = new Registry($this->app, app(IntegrationSettingsRepository::class));

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
