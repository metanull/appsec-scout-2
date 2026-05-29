<?php

use App\Credentials\CredentialField;
use App\Sources\Asoc\AsocSource;
use App\Sources\AzDo\AzDoSource;
use App\Sources\Detectify\DetectifySource;
use App\Trackers\GitHub\GitHubTracker;
use App\Trackers\Jira\JiraTracker;

it('asoc source declares correct credential fields', function () {
    $source = app(AsocSource::class);
    $fields = $source->credentialFields();

    expect($fields)->toHaveCount(3);

    $byKey = collect($fields)->keyBy(fn (CredentialField $f) => $f->key);

    expect($byKey->has('asoc.baseUrl'))->toBeTrue();
    expect($byKey->get('asoc.baseUrl')->isSecret)->toBeFalse();
    expect($byKey->get('asoc.baseUrl')->required)->toBeTrue();

    expect($byKey->has('asoc.keyId'))->toBeTrue();
    expect($byKey->get('asoc.keyId')->isSecret)->toBeFalse();
    expect($byKey->get('asoc.keyId')->required)->toBeTrue();

    expect($byKey->has('asoc.keySecret'))->toBeTrue();
    expect($byKey->get('asoc.keySecret')->isSecret)->toBeTrue();
    expect($byKey->get('asoc.keySecret')->required)->toBeTrue();
});

it('azdo source declares correct credential fields', function () {
    $source = app(AzDoSource::class);
    $fields = $source->credentialFields();

    expect($fields)->toHaveCount(2);

    $byKey = collect($fields)->keyBy(fn (CredentialField $f) => $f->key);

    expect($byKey->has('azdo.pat'))->toBeTrue();
    expect($byKey->get('azdo.pat')->isSecret)->toBeTrue();
    expect($byKey->get('azdo.pat')->required)->toBeTrue();

    expect($byKey->has('azdo.organization'))->toBeTrue();
    expect($byKey->get('azdo.organization')->isSecret)->toBeFalse();
    expect($byKey->get('azdo.organization')->required)->toBeTrue();
});

it('detectify source declares correct credential fields', function () {
    $source = app(DetectifySource::class);
    $fields = $source->credentialFields();

    expect($fields)->toHaveCount(1);

    $field = $fields[0];
    expect($field->key)->toBe('detectify.apiKey');
    expect($field->isSecret)->toBeTrue();
    expect($field->required)->toBeTrue();
});

it('github tracker declares correct credential fields', function () {
    $tracker = app(GitHubTracker::class);
    $fields = $tracker->credentialFields();

    expect($fields)->toHaveCount(1);

    $field = $fields[0];
    expect($field->key)->toBe('github.token');
    expect($field->isSecret)->toBeTrue();
    expect($field->required)->toBeTrue();
});

it('jira tracker declares correct credential fields', function () {
    $tracker = app(JiraTracker::class);
    $fields = $tracker->credentialFields();

    expect($fields)->toHaveCount(3);

    $byKey = collect($fields)->keyBy(fn (CredentialField $f) => $f->key);

    expect($byKey->has('jira.host'))->toBeTrue();
    expect($byKey->get('jira.host')->isSecret)->toBeFalse();
    expect($byKey->get('jira.host')->required)->toBeTrue();

    expect($byKey->has('jira.email'))->toBeTrue();
    expect($byKey->get('jira.email')->isSecret)->toBeFalse();
    expect($byKey->get('jira.email')->required)->toBeTrue();

    expect($byKey->has('jira.api_token'))->toBeTrue();
    expect($byKey->get('jira.api_token')->isSecret)->toBeTrue();
    expect($byKey->get('jira.api_token')->required)->toBeTrue();
});

it('all credential fields have non-empty labels', function () {
    $sources = [app(AsocSource::class), app(AzDoSource::class), app(DetectifySource::class)];
    $trackers = [app(GitHubTracker::class), app(JiraTracker::class)];

    foreach ([...$sources, ...$trackers] as $integration) {
        foreach ($integration->credentialFields() as $field) {
            expect($field->label)->not->toBeEmpty("Field '{$field->key}' must have a non-empty label");
            expect($field->key)->not->toBeEmpty();
        }
    }
});

it('credential field state key replaces dots and hyphens with underscores', function () {
    $field = new CredentialField(key: 'jira.api_token', label: 'API Token', isSecret: true);
    expect($field->stateKey())->toBe('jira_api_token');

    $field2 = new CredentialField(key: 'fake-tracker.token', label: 'Token', isSecret: true);
    expect($field2->stateKey())->toBe('fake_tracker_token');
});
