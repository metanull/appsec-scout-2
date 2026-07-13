<?php

use App\Models\Attachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

it('imports pending scan reports and prints a summary', function () {
    $importPath = sys_get_temp_dir() . '/static-analysis-import-cmd-test-' . uniqid();
    $cursorPath = sys_get_temp_dir() . '/static-analysis-cursor-cmd-test-' . uniqid();
    File::ensureDirectoryExists($importPath . '/20260101T000000Z/Payments');
    File::put($importPath . '/20260101T000000Z/Payments/payments-api.dotnet.sarif', '{"runs":[]}');
    File::put($importPath . '/20260101T000000Z/run.jsonl', json_encode([
        'project' => 'Payments',
        'repository' => 'payments-api',
        'projectId' => 'project-guid-1',
        'repositoryId' => 'repo-guid-1',
        'dotnetAnalysisGenerated' => true,
        'dotnetAnalysisPath' => 'Payments/payments-api.dotnet.sarif',
        'javaAnalysisGenerated' => false,
        'javaAnalysisPath' => '',
    ], JSON_THROW_ON_ERROR) . "\n");
    config(['static_analysis.import_path' => $importPath, 'static_analysis.cursor_path' => $cursorPath]);

    $this->artisan('staticanalysis:import-pending-scans')
        ->expectsOutputToContain('Imported 1 report(s)')
        ->assertSuccessful();

    expect(Attachment::query()->where('kind', 'code-quality-dotnet')->count())->toBe(1);

    File::deleteDirectory($importPath);
    File::deleteDirectory($cursorPath);
});

it('reports zero imports when nothing is pending', function () {
    config([
        'static_analysis.import_path' => sys_get_temp_dir() . '/static-analysis-import-cmd-empty-' . uniqid(),
        'static_analysis.cursor_path' => sys_get_temp_dir() . '/static-analysis-cursor-cmd-empty-' . uniqid(),
    ]);

    $this->artisan('staticanalysis:import-pending-scans')
        ->expectsOutputToContain('Imported 0 report(s)')
        ->assertSuccessful();
});

it('fails and reports an abort when the database is unreachable', function () {
    config([
        'static_analysis.import_path' => sys_get_temp_dir() . '/static-analysis-import-cmd-db-down-' . uniqid(),
        'static_analysis.cursor_path' => sys_get_temp_dir() . '/static-analysis-cursor-cmd-db-down-' . uniqid(),
    ]);
    DB::shouldReceive('select')->once()->with('select 1')->andThrow(new RuntimeException('database unreachable'));

    $this->artisan('staticanalysis:import-pending-scans')
        ->expectsOutputToContain('Aborted after importing 0 report(s)')
        ->assertFailed();
});
