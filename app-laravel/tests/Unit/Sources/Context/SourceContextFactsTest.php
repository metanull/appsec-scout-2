<?php

use App\Sources\Context\SourceContextFacts;

it('exposes the minimum supported schema keys', function () {
    expect(SourceContextFacts::supportedKeys())->toBe([
        'source.alert.web_url',
        'azdo.project.id',
        'azdo.project.name',
        'azdo.repository.id',
        'azdo.repository.name',
        'azdo.repository.web_url',
        'azdo.repository.remote_url',
        'code.default_branch',
        'code.commit_sha',
        'security.cve',
        'security.cwe',
        'package.name',
        'package.version',
        'package.ecosystem',
        'tracker.jira.project_key',
        'tracker.github.repository',
    ]);
});

it('sets and gets nested context values', function () {
    $metadata = [];
    $metadata = SourceContextFacts::set($metadata, SourceContextFacts::AZDO_PROJECT_ID, 'proj-123');
    $metadata = SourceContextFacts::set($metadata, SourceContextFacts::AZDO_REPOSITORY_NAME, 'app-repo');

    expect(SourceContextFacts::get($metadata, SourceContextFacts::AZDO_PROJECT_ID))->toBe('proj-123')
        ->and(SourceContextFacts::getString($metadata, SourceContextFacts::AZDO_REPOSITORY_NAME))->toBe('app-repo')
        ->and(SourceContextFacts::has($metadata, SourceContextFacts::AZDO_REPOSITORY_NAME))->toBeTrue();
});

it('returns null for missing and non-string values in getString', function () {
    $metadata = [
        'package' => [
            'version' => 3,
        ],
    ];

    expect(SourceContextFacts::getString($metadata, SourceContextFacts::PACKAGE_NAME))->toBeNull()
        ->and(SourceContextFacts::getString($metadata, SourceContextFacts::PACKAGE_VERSION))->toBeNull();
});

it('preserves unrelated metadata branches when setting values', function () {
    $metadata = [
        'links' => [
            ['label' => 'Docs', 'url' => 'https://example.test/docs'],
        ],
        'security' => [
            'cwe' => 'CWE-79',
        ],
    ];

    $updated = SourceContextFacts::set($metadata, SourceContextFacts::TRACKER_JIRA_PROJECT_KEY, 'APP');

    expect($updated['links'] ?? null)->toBe($metadata['links'])
        ->and(SourceContextFacts::get($updated, SourceContextFacts::SECURITY_CWE))->toBe('CWE-79')
        ->and(SourceContextFacts::get($updated, SourceContextFacts::TRACKER_JIRA_PROJECT_KEY))->toBe('APP');
});

it('removes keys when set to null and prunes empty nested arrays', function () {
    $metadata = [
        'source' => [
            'alert' => [
                'web_url' => 'https://example.test/alerts/1',
            ],
        ],
        'azdo' => [
            'project' => [
                'id' => 'project-1',
            ],
        ],
    ];

    $updated = SourceContextFacts::set($metadata, SourceContextFacts::SOURCE_ALERT_WEB_URL, null);

    expect(SourceContextFacts::has($updated, SourceContextFacts::SOURCE_ALERT_WEB_URL))->toBeFalse()
        ->and(SourceContextFacts::get($updated, SourceContextFacts::AZDO_PROJECT_ID))->toBe('project-1')
        ->and($updated)->not->toHaveKey('source');
});

it('can report whether a key is part of the supported schema', function () {
    expect(SourceContextFacts::isSupportedKey(SourceContextFacts::TRACKER_GITHUB_REPOSITORY))->toBeTrue()
        ->and(SourceContextFacts::isSupportedKey('unknown.path'))->toBeFalse();
});
