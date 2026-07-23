<?php

use App\SourceControl\Contracts\SourceControlProvider;
use App\SourceControl\Registry;
use App\SourceControl\ValueObjects\TestResult;
use Tests\Fakes\FakeSourceControlProvider;

it('returns every registered source control provider from the registry', function () {
    $fake = new FakeSourceControlProvider;

    $this->app->bind('appsec-scout.source-control.fake', fn () => $fake);
    $this->app->tag(['appsec-scout.source-control.fake'], 'appsec-scout.source-control');

    $registry = new Registry($this->app);

    expect(collect($registry->all())->map->id()->all())->toContain('fake-repos');
});

it('finds source control provider by id', function () {
    $fake = new FakeSourceControlProvider;

    $this->app->bind('appsec-scout.source-control.fake', fn () => $fake);
    $this->app->tag(['appsec-scout.source-control.fake'], 'appsec-scout.source-control');

    $registry = new Registry($this->app);

    expect($registry->find('fake-repos'))->toBe($fake)
        ->and($registry->find('nonexistent'))->toBeNull();
});

// Contract test: every implementation must satisfy the SourceControlProvider interface
it('fake source control provider satisfies contract', function () {
    $fake = new FakeSourceControlProvider;

    expect($fake)->toBeInstanceOf(SourceControlProvider::class)
        ->and($fake->id())->toBeString()->not->toBeEmpty()
        ->and($fake->displayName())->toBeString()->not->toBeEmpty()
        ->and($fake->credentialFields())->toBeArray()
        ->and($fake->testConnection())->toBeInstanceOf(TestResult::class);
});

it('fake source control provider test connection returns success', function () {
    $result = (new FakeSourceControlProvider)->testConnection();

    expect($result->ok)->toBeTrue()
        ->and($result->error)->toBeNull();
});

it('fake source control provider test connection returns failure', function () {
    $result = (new FakeSourceControlProvider)->withConnectionFailure()->testConnection();

    expect($result->ok)->toBeFalse()
        ->and($result->error)->not->toBeNull();
});

// Real providers registered in AppServiceProvider must be discoverable through the registry.
it('registers the real azdo-repos and github-repos providers', function () {
    $registry = app(Registry::class);

    $ids = array_map(fn (SourceControlProvider $provider): string => $provider->id(), $registry->all());

    expect($ids)->toContain('azdo-repos')
        ->and($ids)->toContain('github-repos');
});
