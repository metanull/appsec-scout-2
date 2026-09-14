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
    $findings = app(SarifFindingParser::class)->parse(sarifFixture('vuln-sarif-sample.json'));

    expect($findings)->toHaveCount(1);

    $finding = $findings[0];

    expect($finding->ruleId)->toBe('CVE-2024-56201')
        ->and($finding->title)->toBe('jinja2: Jinja has a sandbox breakout through malicious filenames')
        ->and($finding->severity)->toBe('MEDIUM')
        ->and($finding->filePath)->toBe('vendor/mockery/mockery/docs/requirements.txt')
        ->and($finding->startLine)->toBe(8)
        ->and($finding->endLine)->toBe(8)
        ->and($finding->packageName)->toBe('Jinja2')
        ->and($finding->packageVersion)->toBe('3.1.4')
        ->and($finding->metadata['message'])->toBe("Package: Jinja2\nInstalled Version: 3.1.4\nVulnerability CVE-2024-56201\nSeverity: MEDIUM\nFixed Version: 3.1.5\nLink: [CVE-2024-56201](https://avd.aquasec.com/nvd/cve-2024-56201)")
        ->and($finding->metadata['tags'])->toBe(['vulnerability', 'security', 'MEDIUM'])
        ->and($finding->metadata['level'])->toBe('warning');
});

it('parses a secret sarif result with a pre-masked match and no package fields', function () {
    $findings = app(SarifFindingParser::class)->parse(sarifFixture('secret-sarif-sample.json'));

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
    expect(app(SarifFindingParser::class)->parse('not json'))->toBe([]);
});

it('returns an empty list when there are no runs', function () {
    expect(app(SarifFindingParser::class)->parse('{"version":"2.1.0"}'))->toBe([]);
});

it('derives severity from level for a Roslynator result with no Trivy-style Severity field', function () {
    $findings = app(SarifFindingParser::class)->parse(staticAnalysisSarifFixture('roslynator-sample.json'));

    expect($findings)->toHaveCount(2);

    $finding = $findings[0];

    expect($finding->ruleId)->toBe('CA2100')
        ->and($finding->title)->toBe('Review SQL queries for security vulnerabilities')
        ->and($finding->severity)->toBe('MEDIUM')
        ->and($finding->filePath)->toBe('src/UserRepository.cs')
        ->and($finding->startLine)->toBe(42)
        ->and($finding->packageName)->toBeNull();
});

it('falls back to the first message line for a title when the rule has no shortDescription or name', function () {
    $findings = (new SarifFindingParser)->parse(staticAnalysisSarifFixture('roslynator-sample.json'));

    expect($findings)->toHaveCount(2);

    $finding = $findings[1];

    expect($finding->ruleId)->toBe('CA1062')
        ->and($finding->title)->toBe("Validate parameter 'connectionString' is non-null before using it.")
        ->and($finding->description)->toBeNull()
        ->and($finding->filePath)->toBe('src/Database/ConnectionFactory.cs')
        ->and($finding->startLine)->toBe(15);
});

it('derives severity from level for a SpotBugs result with no Trivy-style Severity field', function () {
    $findings = app(SarifFindingParser::class)->parse(staticAnalysisSarifFixture('spotbugs-sample.json'));

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

    $findings = app(SarifFindingParser::class)->parse($payload);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->severity)->toBeNull();
});

it('derives severity from level for an Opengrep result with no Trivy-style Severity field', function () {
    $findings = app(SarifFindingParser::class)->parse(staticAnalysisSarifFixture('opengrep-sample.json'));

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

it('uses the rule name as the title when shortDescription just restates the rule id, and captures help/tags/level', function () {
    $findings = (new SarifFindingParser)->parse(staticAnalysisSarifFixture('opengrep-real-shape.json'));

    expect($findings)->toHaveCount(2);

    $finding = $findings[0];

    expect($finding->ruleId)->toBe('python.django.security.injection.sql.sql-injection-using-rawsql-or-cursor-execute')
        ->and($finding->title)->toBe('SQL injection via raw SQL construction')
        ->and($finding->description)->toBe('User data flows into a raw SQL query without parameterization, which can lead to SQL injection.')
        ->and($finding->severity)->toBe('HIGH')
        ->and($finding->filePath)->toBe('app/reports/views.py')
        ->and($finding->startLine)->toBe(87)
        ->and($finding->metadata['message'])->toBe('User input flows into a raw SQL query built with string formatting — this is vulnerable to SQL injection.')
        ->and($finding->metadata['help'])->toBe('Use parameterized queries or the Django ORM instead of raw SQL string concatenation. See the [Django security docs](https://docs.djangoproject.com/en/stable/topics/security/#sql-injection-protection).')
        ->and($finding->metadata['tags'])->toBe(['security', 'sql-injection', 'owasp-a03'])
        ->and($finding->metadata['level'])->toBe('error');
});

it('falls back to the message line for a title when both shortDescription and name just restate the rule id', function () {
    $findings = (new SarifFindingParser)->parse(staticAnalysisSarifFixture('opengrep-real-shape.json'));

    expect($findings)->toHaveCount(2);

    $finding = $findings[1];

    expect($finding->ruleId)->toBe('javascript.lang.security.detect-eval-with-expression')
        ->and($finding->title)->toBe('Detected "eval" used with a non-literal argument, this could lead to a code injection vulnerability.')
        ->and($finding->description)->toBeNull()
        ->and($finding->severity)->toBeNull()
        ->and($finding->metadata['help'])->toBeNull()
        ->and($finding->metadata['tags'])->toBe([])
        ->and($finding->metadata['level'])->toBeNull();
});

it('parses findings from every run, not just the first', function () {
    $findings = app(SarifFindingParser::class)->parse(staticAnalysisSarifFixture('roslynator-multi-run-sample.json'));

    expect($findings)->toHaveCount(2)
        ->and($findings[0]->ruleId)->toBe('CA2100')
        ->and($findings[0]->severity)->toBe('MEDIUM')
        ->and($findings[1]->ruleId)->toBe('CA5350')
        ->and($findings[1]->severity)->toBe('HIGH');
});

it('relativises an absolute file:// artifact uri against the given source root', function () {
    $payload = json_encode([
        'version' => '2.1.0',
        'runs' => [[
            'tool' => ['driver' => ['rules' => [['id' => 'CA2100']]]],
            'results' => [[
                'ruleId' => 'CA2100',
                'message' => ['text' => 'Review SQL queries for security vulnerabilities'],
                'locations' => [[
                    'physicalLocation' => [
                        'artifactLocation' => ['uri' => 'file:///workspace-scratch/9c1b2d3e/work/Sources/A/B.cs'],
                        'region' => ['startLine' => 42],
                    ],
                ]],
            ]],
        ]],
    ], JSON_THROW_ON_ERROR);

    $findings = app(SarifFindingParser::class)->parse($payload, '/workspace-scratch/9c1b2d3e/work');

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->filePath)->toBe('Sources/A/B.cs');
});

it('leaves the absolute file:// artifact uri (scheme stripped) when no source root is given', function () {
    $payload = json_encode([
        'version' => '2.1.0',
        'runs' => [[
            'tool' => ['driver' => ['rules' => [['id' => 'CA2100']]]],
            'results' => [[
                'ruleId' => 'CA2100',
                'message' => ['text' => 'Review SQL queries for security vulnerabilities'],
                'locations' => [[
                    'physicalLocation' => [
                        'artifactLocation' => ['uri' => 'file:///workspace-scratch/9c1b2d3e/work/Sources/A/B.cs'],
                        'region' => ['startLine' => 42],
                    ],
                ]],
            ]],
        ]],
    ], JSON_THROW_ON_ERROR);

    $findings = app(SarifFindingParser::class)->parse($payload);

    expect($findings)->toHaveCount(1)
        ->and($findings[0]->filePath)->toBe('/workspace-scratch/9c1b2d3e/work/Sources/A/B.cs');
});

it('keeps producing byte-for-byte identical Trivy file_path output whether or not a source root is given', function () {
    $withoutRoot = app(SarifFindingParser::class)->parse(sarifFixture('vuln-sarif-sample.json'));
    $withRoot = app(SarifFindingParser::class)->parse(sarifFixture('vuln-sarif-sample.json'), '/scan');

    expect($withoutRoot[0]->filePath)->toBe('vendor/mockery/mockery/docs/requirements.txt')
        ->and($withRoot[0]->filePath)->toBe('vendor/mockery/mockery/docs/requirements.txt');

    $withoutRootSecret = app(SarifFindingParser::class)->parse(sarifFixture('secret-sarif-sample.json'));
    $withRootSecret = app(SarifFindingParser::class)->parse(sarifFixture('secret-sarif-sample.json'), '/scan');

    expect($withoutRootSecret[0]->filePath)->toBe('config.php')
        ->and($withRootSecret[0]->filePath)->toBe('config.php');
});
