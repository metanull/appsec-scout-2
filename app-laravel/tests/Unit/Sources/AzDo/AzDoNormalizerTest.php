<?php

use App\Models\Enums\EventType;
use App\Sources\AzDo\AzDoAlert;
use App\Sources\AzDo\AzDoNormalizer;
use App\Sources\AzDo\AzDoProject;
use App\Sources\AzDo\AzDoRepository;
use App\Sources\Context\SourceContextFacts;

it('adds project and repository context facts to normalized dtos', function () {
    $project = new AzDoProject(
        id: 'project-001',
        name: 'SecurityProject',
        description: 'Security testing project',
        url: 'https://dev.azure.com/testorg/_apis/projects/project-001',
    );

    $repo = new AzDoRepository(
        id: 'repo-001',
        name: 'backend-api',
        projectId: 'project-001',
        projectName: 'SecurityProject',
        defaultBranch: 'refs/heads/main',
        remoteUrl: 'https://testorg@dev.azure.com/testorg/SecurityProject/_git/backend-api',
        apiUrl: 'https://dev.azure.com/testorg/SecurityProject/_apis/git/repositories/repo-001',
        webUrl: 'https://dev.azure.com/testorg/SecurityProject/_git/backend-api',
    );

    $systemDto = AzDoNormalizer::toSystem($project);
    $containerDto = AzDoNormalizer::toContainer($repo);

    expect(SourceContextFacts::get($systemDto->metadata ?? [], SourceContextFacts::AZDO_PROJECT_ID))->toBe('project-001')
        ->and(SourceContextFacts::get($systemDto->metadata ?? [], SourceContextFacts::AZDO_PROJECT_NAME))->toBe('SecurityProject')
        ->and($systemDto->metadata['azdo']['project']['web_url'] ?? null)->toBe('https://dev.azure.com/testorg/project-001')
        ->and(SourceContextFacts::get($containerDto->metadata ?? [], SourceContextFacts::AZDO_REPOSITORY_ID))->toBe('repo-001')
        ->and(SourceContextFacts::get($containerDto->metadata ?? [], SourceContextFacts::AZDO_REPOSITORY_NAME))->toBe('backend-api')
        ->and(SourceContextFacts::get($containerDto->metadata ?? [], SourceContextFacts::AZDO_REPOSITORY_WEB_URL))->toBe('https://dev.azure.com/testorg/SecurityProject/_git/backend-api')
        ->and(SourceContextFacts::get($containerDto->metadata ?? [], SourceContextFacts::AZDO_REPOSITORY_REMOTE_URL))->toBe('https://testorg@dev.azure.com/testorg/SecurityProject/_git/backend-api')
        ->and(SourceContextFacts::get($containerDto->metadata ?? [], SourceContextFacts::CODE_DEFAULT_BRANCH))->toBe('main')
        ->and($containerDto->metadata['source']['provider'] ?? null)->toBe('azure-repos');
});

it('reconstructs alert web url when alertUri is missing', function () {
    $project = new AzDoProject(id: 'project-001', name: 'SecurityProject');
    $repo = new AzDoRepository(
        id: 'repo-001',
        name: 'backend-api',
        webUrl: 'https://dev.azure.com/testorg/SecurityProject/_git/backend-api',
    );

    $alert = new AzDoAlert(
        alertId: 3001,
        alertType: 'secret',
        severity: 'critical',
        state: 'active',
        title: 'Secret detected',
        alertUri: null,
    );

    $dto = AzDoNormalizer::toEvent($alert, 'repo-001', $project, $repo);

    expect($dto->url)->toBe('https://dev.azure.com/testorg/SecurityProject/_git/backend-api/alerts/3001')
        ->and(SourceContextFacts::get($dto->metadata ?? [], SourceContextFacts::SOURCE_ALERT_WEB_URL))
        ->toBe('https://dev.azure.com/testorg/SecurityProject/_git/backend-api/alerts/3001');
});

it('does not store unsafe reconstructed alert urls in link metadata', function () {
    $project = new AzDoProject(id: 'project-001', name: 'SecurityProject');
    $repo = new AzDoRepository(
        id: 'repo-001',
        name: 'backend-api',
        webUrl: 'file:///backend-api',
        apiUrl: 'file:///apis/repositories/repo-001',
    );

    $alert = new AzDoAlert(
        alertId: 4004,
        alertType: 'code',
        severity: 'high',
        state: 'active',
        title: 'Unsafe URL test',
        alertUri: null,
        tools: [
            [
                'name' => 'CodeQL',
                'rules' => [
                    ['id' => 'CWE-89', 'opaqueId' => 'SAST-CWE-89'],
                ],
            ],
        ],
    );

    $dto = AzDoNormalizer::toEvent($alert, 'repo-001', $project, $repo);

    expect($dto->type)->toBe(EventType::Vulnerability)
        ->and(SourceContextFacts::get($dto->metadata ?? [], SourceContextFacts::SOURCE_ALERT_WEB_URL))->toBeNull()
        ->and($dto->metadata['links'] ?? [])->not->toContain([
            'label' => 'Source alert',
            'url' => 'file:///backend-api/alerts/4004',
        ]);
});
