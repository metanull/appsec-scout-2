<?php

use App\Assets\AzDoScanResultDtoFactory;
use App\Sources\Context\SourceContextFacts;

it('maps a full scan result line to the same facts a source sync writes', function () {
    $result = [
        'project' => 'Payments',
        'repository' => 'payments-api',
        'projectId' => 'project-guid-1',
        'repositoryId' => 'repo-guid-1',
        'webUrl' => 'https://org@dev.azure.com/org/Payments/_git/payments-api',
        'repositoryWebUrl' => 'https://dev.azure.com/org/Payments/_git/payments-api',
        'defaultBranch' => 'refs/heads/main',
        'projectDescription' => 'Payments platform services',
        'projectUrl' => 'https://dev.azure.com/org/_apis/projects/project-guid-1',
    ];

    $system = AzDoScanResultDtoFactory::system($result);
    expect($system->name)->toBe('Payments')
        ->and($system->description)->toBe('Payments platform services')
        ->and($system->url)->toBe('https://dev.azure.com/org/project-guid-1')
        ->and(SourceContextFacts::getString($system->metadata ?? [], SourceContextFacts::AZDO_PROJECT_ID))->toBe('project-guid-1');

    $container = AzDoScanResultDtoFactory::container($result);
    expect($container->name)->toBe('payments-api')
        ->and($container->kind)->toBe('repository')
        ->and($container->url)->toBe('https://dev.azure.com/org/Payments/_git/payments-api')
        ->and(SourceContextFacts::getString($container->metadata ?? [], SourceContextFacts::AZDO_REPOSITORY_WEB_URL))->toBe('https://dev.azure.com/org/Payments/_git/payments-api')
        ->and(SourceContextFacts::getString($container->metadata ?? [], SourceContextFacts::AZDO_REPOSITORY_REMOTE_URL))->toBe('https://org@dev.azure.com/org/Payments/_git/payments-api')
        ->and(SourceContextFacts::getString($container->metadata ?? [], SourceContextFacts::CODE_DEFAULT_BRANCH))->toBe('main')
        ->and(SourceContextFacts::getString($container->metadata ?? [], SourceContextFacts::SOURCE_PROVIDER))->toBe('azure-repos');
});

it('degrades gracefully for an old run.jsonl line missing the enrichment fields', function () {
    // Lines written before this feature carry only project/repo id + name.
    $result = [
        'project' => 'Payments',
        'repository' => 'payments-api',
        'projectId' => 'project-guid-1',
        'repositoryId' => 'repo-guid-1',
    ];

    $system = AzDoScanResultDtoFactory::system($result);
    expect($system->name)->toBe('Payments')
        ->and($system->description)->toBeNull()
        ->and($system->url)->toBeNull()
        ->and(SourceContextFacts::getString($system->metadata ?? [], SourceContextFacts::AZDO_PROJECT_ID))->toBe('project-guid-1')
        ->and(SourceContextFacts::getString($system->metadata ?? [], SourceContextFacts::AZDO_PROJECT_WEB_URL))->toBeNull();

    $container = AzDoScanResultDtoFactory::container($result);
    expect($container->url)->toBeNull()
        ->and(SourceContextFacts::getString($container->metadata ?? [], SourceContextFacts::CODE_DEFAULT_BRANCH))->toBeNull()
        ->and(SourceContextFacts::getString($container->metadata ?? [], SourceContextFacts::AZDO_REPOSITORY_REMOTE_URL))->toBeNull()
        // A provider is still stamped — it is a constant, not derived from the missing fields.
        ->and(SourceContextFacts::getString($container->metadata ?? [], SourceContextFacts::SOURCE_PROVIDER))->toBe('azure-repos');
});
