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
        ->and(SourceContextFacts::get($systemDto->metadata ?? [], SourceContextFacts::AZDO_PROJECT_WEB_URL))->toBe('https://dev.azure.com/testorg/project-001')
        ->and(SourceContextFacts::get($containerDto->metadata ?? [], SourceContextFacts::AZDO_REPOSITORY_ID))->toBe('repo-001')
        ->and(SourceContextFacts::get($containerDto->metadata ?? [], SourceContextFacts::AZDO_REPOSITORY_NAME))->toBe('backend-api')
        ->and(SourceContextFacts::get($containerDto->metadata ?? [], SourceContextFacts::AZDO_REPOSITORY_WEB_URL))->toBe('https://dev.azure.com/testorg/SecurityProject/_git/backend-api')
        ->and(SourceContextFacts::get($containerDto->metadata ?? [], SourceContextFacts::AZDO_REPOSITORY_REMOTE_URL))->toBe('https://testorg@dev.azure.com/testorg/SecurityProject/_git/backend-api')
        ->and(SourceContextFacts::get($containerDto->metadata ?? [], SourceContextFacts::CODE_DEFAULT_BRANCH))->toBe('main')
        ->and(SourceContextFacts::get($containerDto->metadata ?? [], SourceContextFacts::SOURCE_PROVIDER))->toBe('azure-repos');
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

it('stores code alert context facts', function () {
    $project = new AzDoProject(id: 'project-001', name: 'SecurityProject');
    $repo = new AzDoRepository(
        id: 'repo-001',
        name: 'backend-api',
        defaultBranch: 'refs/heads/main',
        webUrl: 'https://dev.azure.com/testorg/SecurityProject/_git/backend-api',
        apiUrl: 'https://dev.azure.com/testorg/SecurityProject/_apis/git/repositories/repo-001',
    );

    $alert = new AzDoAlert(
        alertId: 5005,
        alertType: 'code',
        severity: 'high',
        state: 'active',
        title: 'Code issue',
        alertUri: null,
        physicalLocations: [
            [
                'filePath' => 'src/app.php',
                'versionControl' => [
                    'commitHash' => 'abc123',
                    'branch' => 'refs/heads/feature/code-fix',
                    'itemUrl' => 'https://dev.azure.com/testorg/SecurityProject/_git/backend-api?path=/src/app.php',
                ],
                'region' => [
                    'lineStart' => 12,
                    'lineEnd' => 14,
                    'columnStart' => 5,
                    'columnEnd' => 9,
                ],
            ],
        ],
        tools: [
            [
                'name' => 'CodeQL',
                'rules' => [
                    [
                        'id' => 'CWE-89',
                        'helpUri' => 'https://docs.example.com/rule/89',
                    ],
                ],
            ],
        ],
        additionalData: [
            'cveId' => 'CVE-2023-12345',
            'packageName' => 'express',
            'packageVersion' => '4.18.2',
            'ecosystem' => 'npm',
        ],
    );

    $dto = AzDoNormalizer::toEvent($alert, 'repo-001', $project, $repo);

    expect($dto->startLine)->toBe(12)
        ->and($dto->endLine)->toBe(14)
        ->and(SourceContextFacts::get($dto->metadata ?? [], SourceContextFacts::SOURCE_ALERT_WEB_URL))->toBe('https://dev.azure.com/testorg/SecurityProject/_git/backend-api/alerts/5005')
        ->and(SourceContextFacts::get($dto->metadata ?? [], SourceContextFacts::CODE_FILE_PATH))->toBe('src/app.php')
        ->and(SourceContextFacts::get($dto->metadata ?? [], SourceContextFacts::CODE_COMMIT_SHA))->toBe('abc123')
        ->and(SourceContextFacts::get($dto->metadata ?? [], SourceContextFacts::CODE_DEFAULT_BRANCH))->toBe('feature/code-fix')
        ->and(SourceContextFacts::get($dto->metadata ?? [], SourceContextFacts::AZDO_ALERT_TYPE))->toBe('code')
        ->and(SourceContextFacts::get($dto->metadata ?? [], SourceContextFacts::SECURITY_CWE))->toBe('CWE-89')
        ->and(SourceContextFacts::get($dto->metadata ?? [], SourceContextFacts::SECURITY_CVE))->toBe('CVE-2023-12345')
        ->and(SourceContextFacts::get($dto->metadata ?? [], SourceContextFacts::PACKAGE_NAME))->toBe('express')
        ->and(SourceContextFacts::get($dto->metadata ?? [], SourceContextFacts::PACKAGE_VERSION))->toBe('4.18.2')
        ->and(SourceContextFacts::get($dto->metadata ?? [], SourceContextFacts::PACKAGE_ECOSYSTEM))->toBe('npm');
});

it('reads the line of a single-line code alert as Advanced Security reports it', function () {
    // Shape of a real Advanced Security code alert: lineEnd is 0 for a single-line region.
    $alert = AzDoAlert::fromArray([
        'alertId' => 11783,
        'alertType' => 'code',
        'severity' => 'critical',
        'state' => 'active',
        'title' => 'LDAP query built from user-controlled sources',
        'tools' => [['name' => 'CodeQL', 'rules' => [['id' => 'java/ldap-injection', 'opaqueId' => 'java/ldap-injection']]]],
        'physicalLocations' => [[
            'versionControl' => ['commitHash' => '856597dfcef8edbf1a400a02445e7de0bdcfa4cd'],
            'filePath' => 'project/core-web/src/main/java/agora/action/common/ReLoginAction.java',
            'isValidGitPath' => true,
            'region' => ['lineStart' => 325, 'lineEnd' => 0, 'columnStart' => 70, 'columnEnd' => 76],
            'additionalProperties' => ['%SRCROOT%' => 'file:///D:/a/1/s'],
        ]],
    ]);

    $dto = AzDoNormalizer::toEvent($alert, 'repo-001');

    expect($dto->startLine)->toBe(325)
        ->and($dto->endLine)->toBe(325)
        ->and($dto->snippet)->toBeNull()
        ->and($dto->fingerprint)->toBe('java/ldap-injection:project/core-web/src/main/java/agora/action/common/ReLoginAction.java:325');
});

it('preserves full rule description text in event descriptions', function () {
    $alert = new AzDoAlert(
        alertId: 7007,
        alertType: 'code',
        severity: 'high',
        state: 'active',
        title: 'XSS vulnerability',
        tools: [
            [
                'name' => 'CodeQL',
                'rules' => [
                    [
                        'description' => 'Short desc',
                        'fullDescription' => [
                            'text' => 'This is a cross-site scripting vulnerability.',
                        ],
                    ],
                ],
            ],
        ],
    );

    $dto = AzDoNormalizer::toEvent($alert);

    expect($dto->description)->toBe("Short desc\n\nThis is a cross-site scripting vulnerability.");
});

it('decodes entity-encoded titles, descriptions and help texts', function () {
    $alert = new AzDoAlert(
        alertId: 7008,
        alertType: 'code',
        severity: 'high',
        state: 'active',
        title: 'Ensure that key vault secrets have &quot;content_type&quot; set',
        tools: [
            [
                'name' => 'Checkov',
                'rules' => [
                    [
                        'description' => 'Ensure &#39;Trusted Microsoft Services&#39; is enabled',
                        'helpMessage' => "## Example\n\n```xml\n&lt;url&gt;http://repo.example/?a=1&amp;b=2&lt;/url&gt;\n```",
                    ],
                ],
            ],
        ],
    );

    $dto = AzDoNormalizer::toEvent($alert);

    expect($dto->title)->toBe('Ensure that key vault secrets have "content_type" set')
        ->and($dto->description)->toBe("Ensure 'Trusted Microsoft Services' is enabled")
        ->and($dto->remediation)->toBe("## Example\n\n```xml\n<url>http://repo.example/?a=1&b=2</url>\n```");
});

it('keeps plain markdown help texts verbatim, including entities they show on purpose', function () {
    $help = "Escape `<` as `&lt;` before writing it:\n\n```javascript\ndocument.write(\"<option>\" + value + \"</option>\");\n```";
    $alert = new AzDoAlert(
        alertId: 7009,
        alertType: 'code',
        severity: 'high',
        state: 'active',
        title: 'Client-side cross-site scripting',
        tools: [['name' => 'CodeQL', 'rules' => [['helpMessage' => $help]]]],
    );

    expect(AzDoNormalizer::toEvent($alert)->remediation)->toBe($help);
});

it('stores dependency alert package context facts', function () {
    $project = new AzDoProject(id: 'project-001', name: 'SecurityProject');
    $repo = new AzDoRepository(
        id: 'repo-001',
        name: 'backend-api',
        webUrl: 'https://dev.azure.com/testorg/SecurityProject/_git/backend-api',
        apiUrl: 'https://dev.azure.com/testorg/SecurityProject/_apis/git/repositories/repo-001',
    );

    $alert = new AzDoAlert(
        alertId: 6006,
        alertType: 'dependency',
        severity: 'medium',
        state: 'active',
        title: 'Dependency issue',
        alertUri: 'https://dev.azure.com/testorg/SecurityProject/_git/backend-api/alerts/6006',
        additionalData: [
            'packageName' => 'lodash',
            'packageVersion' => '4.17.21',
            'ecosystem' => 'npm',
            'cveId' => 'CVE-2022-23307',
        ],
    );

    $dto = AzDoNormalizer::toEvent($alert, 'repo-001', $project, $repo);

    expect(SourceContextFacts::get($dto->metadata ?? [], SourceContextFacts::PACKAGE_NAME))->toBe('lodash')
        ->and(SourceContextFacts::get($dto->metadata ?? [], SourceContextFacts::PACKAGE_VERSION))->toBe('4.17.21')
        ->and(SourceContextFacts::get($dto->metadata ?? [], SourceContextFacts::PACKAGE_ECOSYSTEM))->toBe('npm')
        ->and(SourceContextFacts::get($dto->metadata ?? [], SourceContextFacts::SECURITY_CVE))->toBe('CVE-2022-23307')
        ->and(SourceContextFacts::get($dto->metadata ?? [], SourceContextFacts::SOURCE_ALERT_WEB_URL))->toBe('https://dev.azure.com/testorg/SecurityProject/_git/backend-api/alerts/6006');
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
