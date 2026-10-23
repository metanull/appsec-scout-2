<?php

use App\Assets\Parsers\SarifFindingParser;
use App\SourceControl\Collection\Hygiene\CommittedBuildOutput;
use App\SourceControl\Collection\Hygiene\RepositoryHygieneCheck;

/** @return array<string, CommittedBuildOutput> folder => finding */
function hygieneFindings(array $files, ?array $secretPaths = null): array
{
    $findings = app(RepositoryHygieneCheck::class)->findings(lsFilesOutput($files), $secretPaths === null ? null : trivySecretsSarif($secretPaths));

    return array_combine(array_map(fn (CommittedBuildOutput $finding): string => $finding->folder, $findings), $findings);
}

it('reads the tracked files from git ls-files, never the working tree', function () {
    expect(app(RepositoryHygieneCheck::class)->command('/scratch/work'))
        ->toBe(['git', '-C', '/scratch/work', 'ls-files', '-z', '--format=%(objecttype)%x09%(objectsize)%x09%(path)']);
});

it('reports one finding per build-output folder, with its file count and size', function () {
    $findings = hygieneFindings([
        'src/Api/Program.cs' => 900,
        'src/Api/bin/Release/net8.0/appsettings.json' => 400,
        'src/Api/bin/Release/net8.0/Api.dll' => 60000,
        'src/Api/obj/project.assets.json' => 2000,
        'web/dist/tinymce/tinymce.min.js' => 500000,
        'README.md' => 100,
    ]);

    expect(array_keys($findings))->toBe(['src/Api/bin', 'src/Api/obj', 'web/dist'])
        ->and($findings['src/Api/bin']->ruleId)->toBe(RepositoryHygieneCheck::RULE_BUILD_OUTPUT)
        ->and($findings['src/Api/bin']->fileCount)->toBe(2)
        ->and($findings['src/Api/bin']->sizeBytes)->toBe(60400)
        ->and($findings['src/Api/bin']->sampleFiles)->toBe(['src/Api/bin/Release/net8.0/Api.dll', 'src/Api/bin/Release/net8.0/appsettings.json'])
        ->and($findings['web/dist']->severity())->toBe('MEDIUM');
});

it('names a nested build-output folder by its outermost build-output directory, in any case', function () {
    $findings = hygieneFindings([
        'App/Bin/Release/publish/App.dll' => 10,
        'App/Bin/Release/publish/web.config' => 10,
        'ui/node_modules/lodash/dist/lodash.js' => 10,
        'ui/node_modules/lodash/package.json' => 10,
    ]);

    expect(array_keys($findings))->toBe(['App/Bin', 'ui/node_modules'])
        ->and($findings['App/Bin']->fileCount)->toBe(2)
        ->and($findings['ui/node_modules']->fileCount)->toBe(2);
});

it('reports compiled binaries outside build-output folders per directory', function (string $file) {
    $findings = hygieneFindings([$file => 10, 'lib/README.md' => 10]);

    expect($findings)->toHaveCount(1)
        ->and(array_values($findings)[0]->ruleId)->toBe(RepositoryHygieneCheck::RULE_BINARY)
        ->and(array_values($findings)[0]->fileCount)->toBe(1);
})->with(['lib/Newtonsoft.Json.dll', 'tools/setup.EXE', 'lib/struts.jar', 'deploy/app.war', 'deploy/app.ear', 'native/libfoo.so', 'lib/App.pdb', 'classes/com/example/App.class']);

it('groups binaries at the repository root under "."', function () {
    expect(array_keys(hygieneFindings(['installer.exe' => 10])))->toBe(['.']);
});

it('ignores a file named like a build-output directory and a submodule', function () {
    $output = lsFilesOutput(['docs/bin' => 10, 'dist' => 10, 'src/output.txt' => 10])
        . "commit\t-\tvendor/bin\0";

    expect(app(RepositoryHygieneCheck::class)->findings($output, null))->toBe([]);
});

it('raises a build-output folder holding a Trivy secret finding to HIGH', function () {
    $findings = hygieneFindings([
        'Api/bin/appsettings.json' => 10,
        'Api/obj/appsettings.json' => 10,
        'Api/appsettings.json' => 10,
    ], ['Api/bin/appsettings.json', 'Api/appsettings.json']);

    expect($findings['Api/bin']->secretCount)->toBe(1)
        ->and($findings['Api/bin']->severity())->toBe('HIGH')
        ->and($findings['Api/obj']->secretCount)->toBe(0)
        ->and($findings['Api/obj']->severity())->toBe('MEDIUM');
});

it('counts a secret toward a binaries finding only in that same directory', function () {
    $findings = hygieneFindings(['lib/App.dll' => 10, 'lib/sub/Other.dll' => 10], ['lib/sub/secret.txt']);

    expect($findings['lib']->severity())->toBe('MEDIUM')
        ->and($findings['lib/sub']->severity())->toBe('HIGH');
});

it('writes a SARIF report the shared parser reads back with folder, severity and references', function () {
    $check = app(RepositoryHygieneCheck::class);
    $findings = $check->findings(lsFilesOutput([
        'My App/bin/app settings.json' => 2048,
        'lib/App.dll' => 10,
    ]), trivySecretsSarif(['My%20App/bin/app%20settings.json']));

    $parsed = app(SarifFindingParser::class)->parse($check->report($findings));
    $byPath = collect($parsed)->keyBy('filePath');

    expect($byPath->keys()->all())->toBe(['My App/bin', 'lib'])
        ->and($byPath['My App/bin']->ruleId)->toBe(RepositoryHygieneCheck::RULE_BUILD_OUTPUT)
        ->and($byPath['My App/bin']->severity)->toBe('HIGH')
        ->and($byPath['My App/bin']->title)->toBe('Build output committed to Git')
        ->and($byPath['My App/bin']->startLine)->toBeNull()
        ->and($byPath['My App/bin']->metadata['message'])->toContain('1 committed file (2 KB) in My App/bin')
        ->and($byPath['My App/bin']->metadata['message'])->toContain('Secrets: 1 Trivy secret finding in this folder')
        ->and($byPath['My App/bin']->metadata['result']['properties'])->toMatchArray(['folder' => 'My App/bin', 'fileCount' => 1, 'sizeBytes' => 2048, 'secretCount' => 1])
        ->and($byPath['My App/bin']->metadata['help'])->toContain('CICD-SEC-9', 'PS.2', 'Binary-Artifacts')
        ->and($byPath['lib']->ruleId)->toBe(RepositoryHygieneCheck::RULE_BINARY)
        ->and($byPath['lib']->severity)->toBe('MEDIUM');
});

it('keeps a file name from adding a severity line of its own', function () {
    $check = app(RepositoryHygieneCheck::class);
    $findings = $check->findings(lsFilesOutput(["bin/x\nSeverity: LOW" => 10]), null);

    expect(app(SarifFindingParser::class)->parse($check->report($findings))[0]->severity)->toBe('MEDIUM');
});

it('writes a valid, empty report when nothing is committed', function () {
    $check = app(RepositoryHygieneCheck::class);

    expect(app(SarifFindingParser::class)->parse($check->report([])))->toBe([]);
});
