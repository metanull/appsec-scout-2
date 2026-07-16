<?php

use App\Assets\StaticAnalysis\StaticAnalysisScanStatusReporter;
use App\Models\ErrorLog;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use Illuminate\Support\Facades\File;

function setUpStaticAnalysisStatusTestDirectories(): array
{
    $importPath = sys_get_temp_dir() . '/static-analysis-status-import-test-' . uniqid();
    $cursorPath = sys_get_temp_dir() . '/static-analysis-status-cursor-test-' . uniqid();
    File::ensureDirectoryExists($importPath);
    File::ensureDirectoryExists($cursorPath);
    config(['static_analysis.import_path' => $importPath, 'static_analysis.cursor_path' => $cursorPath]);

    return [$importPath, $cursorPath];
}

function tearDownStaticAnalysisStatusTestDirectories(string $importPath, string $cursorPath): void
{
    File::deleteDirectory($importPath);
    File::deleteDirectory($cursorPath);
}

it('returns an empty list when the import directory does not exist', function () {
    config([
        'static_analysis.import_path' => sys_get_temp_dir() . '/static-analysis-status-missing-' . uniqid(),
        'static_analysis.cursor_path' => sys_get_temp_dir() . '/static-analysis-status-cursor-missing-' . uniqid(),
    ]);

    expect(app(StaticAnalysisScanStatusReporter::class)->statusForAllRuns())->toBe([]);
});

it('reports an unknown total and never-updated cursor for a fresh in-progress run with no prior AzDO data', function () {
    [$importPath, $cursorPath] = setUpStaticAnalysisStatusTestDirectories();
    $runDir = $importPath . '/20260101T000000Z';
    File::ensureDirectoryExists($runDir);
    File::put($runDir . '/run.jsonl', json_encode(['project' => 'Payments', 'repository' => 'payments-api'], JSON_THROW_ON_ERROR) . "\n");

    $rows = app(StaticAnalysisScanStatusReporter::class)->statusForAllRuns();

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['run'])->toBe('20260101T000000Z')
        ->and($rows[0]['dryRun'])->toBeFalse()
        ->and($rows[0]['finished'])->toBeFalse()
        ->and($rows[0]['imported'])->toBe(0)
        ->and($rows[0]['total'])->toBeNull()
        ->and($rows[0]['failed'])->toBe(0)
        ->and($rows[0]['lastUpdated'])->toBeNull();

    tearDownStaticAnalysisStatusTestDirectories($importPath, $cursorPath);
});

it('reports an approximate total from known AzDO repositories for an in-progress run', function () {
    [$importPath, $cursorPath] = setUpStaticAnalysisStatusTestDirectories();

    $system = SoftwareSystem::factory()->create(['source_id' => 'azdo', 'source_system_id' => 'project-guid-1']);
    SecurityContainer::factory()->count(3)->create(['software_system_id' => $system->id, 'kind' => 'repository']);

    $runDir = $importPath . '/20260101T000000Z';
    File::ensureDirectoryExists($runDir);
    File::put($runDir . '/run.jsonl', json_encode(['project' => 'Payments', 'repository' => 'payments-api'], JSON_THROW_ON_ERROR) . "\n");
    File::put($cursorPath . '/20260101T000000Z.processed', '1');

    $rows = app(StaticAnalysisScanStatusReporter::class)->statusForAllRuns();

    expect($rows[0]['imported'])->toBe(1)
        ->and($rows[0]['total'])->toBe(3)
        ->and($rows[0]['totalIsApprox'])->toBeTrue()
        ->and($rows[0]['lastUpdated'])->not->toBeNull();

    tearDownStaticAnalysisStatusTestDirectories($importPath, $cursorPath);
});

it('uses the exact total from summary.json for a finished run instead of the approximation', function () {
    [$importPath, $cursorPath] = setUpStaticAnalysisStatusTestDirectories();

    $system = SoftwareSystem::factory()->create(['source_id' => 'azdo', 'source_system_id' => 'project-guid-1']);
    SecurityContainer::factory()->count(50)->create(['software_system_id' => $system->id, 'kind' => 'repository']);

    $runDir = $importPath . '/20260101T000000Z';
    File::ensureDirectoryExists($runDir);
    File::put($runDir . '/run.jsonl', json_encode(['project' => 'Payments', 'repository' => 'payments-api'], JSON_THROW_ON_ERROR) . "\n");
    File::put($runDir . '/summary.json', json_encode(['repositoriesConsidered' => 1], JSON_THROW_ON_ERROR));
    File::put($cursorPath . '/20260101T000000Z.processed', '1');

    $rows = app(StaticAnalysisScanStatusReporter::class)->statusForAllRuns();

    expect($rows[0]['finished'])->toBeTrue()
        ->and($rows[0]['total'])->toBe(1)
        ->and($rows[0]['totalIsApprox'])->toBeFalse();

    tearDownStaticAnalysisStatusTestDirectories($importPath, $cursorPath);
});

it('flags a dry-run scan distinctly', function () {
    [$importPath, $cursorPath] = setUpStaticAnalysisStatusTestDirectories();
    $runDir = $importPath . '/20260101T000000Z';
    File::ensureDirectoryExists($runDir);
    File::put($runDir . '/run.jsonl', '');
    File::put($runDir . '/.skip-import', '');

    $rows = app(StaticAnalysisScanStatusReporter::class)->statusForAllRuns();

    expect($rows[0]['dryRun'])->toBeTrue();

    tearDownStaticAnalysisStatusTestDirectories($importPath, $cursorPath);
});

it('counts static-analysis-import error log entries per run', function () {
    [$importPath, $cursorPath] = setUpStaticAnalysisStatusTestDirectories();
    $runDir = $importPath . '/20260101T000000Z';
    File::ensureDirectoryExists($runDir);
    File::put($runDir . '/run.jsonl', '');

    ErrorLog::query()->create([
        'level' => 'error',
        'channel' => 'static-analysis-import',
        'message' => 'boom',
        'context_json' => ['run' => '20260101T000000Z'],
        'occurred_at' => now(),
    ]);
    ErrorLog::query()->create([
        'level' => 'error',
        'channel' => 'sync',
        'message' => 'unrelated',
        'context_json' => ['run' => '20260101T000000Z'],
        'occurred_at' => now(),
    ]);

    $rows = app(StaticAnalysisScanStatusReporter::class)->statusForAllRuns();

    expect($rows[0]['failed'])->toBe(1);

    tearDownStaticAnalysisStatusTestDirectories($importPath, $cursorPath);
});

it('orders runs newest first', function () {
    [$importPath, $cursorPath] = setUpStaticAnalysisStatusTestDirectories();
    File::ensureDirectoryExists($importPath . '/20260101T000000Z');
    File::put($importPath . '/20260101T000000Z/run.jsonl', '');
    File::ensureDirectoryExists($importPath . '/20260201T000000Z');
    File::put($importPath . '/20260201T000000Z/run.jsonl', '');

    $rows = app(StaticAnalysisScanStatusReporter::class)->statusForAllRuns();

    expect(array_column($rows, 'run'))->toBe(['20260201T000000Z', '20260101T000000Z']);

    tearDownStaticAnalysisStatusTestDirectories($importPath, $cursorPath);
});
