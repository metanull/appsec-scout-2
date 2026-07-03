<?php

use App\Assets\Parsers\SarifFindingParser;

function sarifFixture(string $name): string
{
    return (string) file_get_contents(base_path("tests/Fixtures/Trivy/{$name}"));
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
