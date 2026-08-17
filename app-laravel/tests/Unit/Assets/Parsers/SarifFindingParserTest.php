<?php

use App\Assets\Parsers\SarifFindingParser;

function sarifFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/Trivy/{$name}"));
}

function staticAnalysisSarifFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/StaticAnalysis/{$name}"));
}

it('parses a vulnerability sarif result including package and version', function () {
    $findings = (new SarifFindingParser)->parse(sarifFixture('vuln-sarif-sample.json'));

    expect($findings)->toHaveCount(1);

    $finding = $findings[0];

    expect($finding->ruleId)->toBe('CVE-2024-56201')
        ->and($finding->title)->toBe('jinja2: Jinja has a sandbox breakout through malicious filenames')
        ->and($finding->severity)->toBe('MEDIUM')
        ->and($finding->filePath)->toBe('vendor/mockery/mockery/docs/requirements.txt')
        ->and($finding->startLine)->toBe(8)
        ->and($finding->endLine)->toBe(8)
        ->and($finding->packageName)->toBe('Jinja2')
        ->and($finding->packageVersion)->toBe('3.1.4');
});

it('parses a secret sarif result with a pre-masked match and no package fields', function () {
    $findings = (new SarifFindingParser)->parse(sarifFixture('secret-sarif-sample.json'));

    expect($findings)->toHaveCount(1);

    $finding = $findings[0];

    expect($finding->ruleId)->toBe('github-pat')
        ->and($finding->title)->toBe('GitHub Personal Access Token')
        ->and($finding->severity)->toBe('CRITICAL')
        ->and($finding->filePath)->toBe('config.php')
        ->and($finding->startLine)->toBe(3)
        ->and($finding->packageName)->toBeNull()
        ->and($finding->packageVersion)->toBeNull()
        ->and($finding->metadata['result']['message']['text'])->toContain('****');
});

it('returns an empty list for invalid json', function () {
    expect((new SarifFindingParser)->parse('not json'))->toBe([]);
});

it('returns an empty list when there are no runs', function () {
    expect((new SarifFindingParser)->parse('{"version":"2.1.0"}'))->toBe([]);
});

it('derives severity from level for a Roslynator result with no Trivy-style Severity field', function () {
    $findings = (new SarifFindingParser)->parse(staticAnalysisSarifFixture('roslynator-sample.json'));

    expect($findings)->toHaveCount(1);

    $finding = $findings[0];

    expect($finding->ruleId)->toBe('CA2100')
        ->and($finding->title)->toBe('Review SQL queries for security vulnerabilities')
        ->and($finding->severity)->toBe('MEDIUM')
        ->and($finding->filePath)->toBe('src/UserRepository.cs')
        ->and($finding->startLine)->toBe(42)
        ->and($finding->packageName)->toBeNull();
});

it('derives severity from level for a SpotBugs result with no Trivy-style Severity field', function () {
    $findings = (new SarifFindingParser)->parse(staticAnalysisSarifFixture('spotbugs-sample.json'));

    expect($findings)->toHaveCount(1);

    $finding = $findings[0];

    expect($finding->ruleId)->toBe('SQL_INJECTION_JDBC')
        ->and($finding->severity)->toBe('HIGH')
        ->and($finding->filePath)->toBe('src/main/java/com/example/UserDao.java')
        ->and($finding->startLine)->toBe(57);
});

it('returns null severity when neither a Severity field nor a recognized level is present', function () {
    $payload = json_encode([
        'version' => '2.1.0',
        'runs' => [[
            'tool' => ['driver' => ['rules' => [['id' => 'RULE1']]]],
            'results' => [[
                'ruleId' => 'RULE1',
                'message' => ['text' => 'no severity info here'],
                'locations' => [[
                    'physicalLocation' => ['artifactLocation' => ['uri' => 'a.txt'], 'region' => ['startLine' => 1]],
                ]],
            ]],
        ]],
    ], JSON_THROW_ON_ERROR);

    $findings = (new SarifFindingParser)->parse($payload);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity)->toBeNull();
});

it('derives severity from level for an Opengrep result with no Trivy-style Severity field', function () {
    $findings = (new SarifFindingParser)->parse(staticAnalysisSarifFixture('opengrep-sample.json'));

    expect($findings)->toHaveCount(2);

    expect($findings[0]->ruleId)->toBe('javascript.express.security.audit.xss.direct-response-write.direct-response-write')
        ->and($findings[0]->severity)->toBe('HIGH')
        ->and($findings[0]->filePath)->toBe('src/routes/search.js')
        ->and($findings[0]->startLine)->toBe(18)
        ->and($findings[0]->packageName)->toBeNull();

    expect($findings[1]->ruleId)->toBe('typescript.lang.security.audit.unsafe-child-process.unsafe-child-process')
        ->and($findings[1]->severity)->toBe('MEDIUM')
        ->and($findings[1]->filePath)->toBe('src/services/backup.ts')
        ->and($findings[1]->startLine)->toBe(34);
});

it('parses findings from every run, not just the first', function () {
    $findings = (new SarifFindingParser)->parse(staticAnalysisSarifFixture('roslynator-multi-run-sample.json'));

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->ruleId)->toBe('CA2100')
        ->and($findings[0]->severity)->toBe('MEDIUM')
        ->and($findings[1]->ruleId)->toBe('CA5350')
        ->and($findings[1]->severity)->toBe('HIGH');
});
