<?php

use Illuminate\Support\Facades\File;

it('reports no runs found when the import directory is empty', function () {
    config([
        'static_analysis.import_path' => sys_get_temp_dir() . '/static-analysis-status-cmd-missing-' . uniqid(),
        'static_analysis.cursor_path' => sys_get_temp_dir() . '/static-analysis-status-cmd-cursor-missing-' . uniqid(),
    ]);

    $this->artisan('staticanalysis:scan-status')
        ->expectsOutputToContain('No static-analysis-scan runs found.')
        ->assertSuccessful();
});

it('renders a status table for an in-progress run', function () {
    $importPath = sys_get_temp_dir() . '/static-analysis-status-cmd-test-' . uniqid();
    $cursorPath = sys_get_temp_dir() . '/static-analysis-status-cmd-cursor-test-' . uniqid();
    File::ensureDirectoryExists($importPath . '/20260101T000000Z');
    File::put($importPath . '/20260101T000000Z/run.jsonl', json_encode(['project' => 'Payments', 'repository' => 'payments-api'], JSON_THROW_ON_ERROR) . "\n");
    config(['static_analysis.import_path' => $importPath, 'static_analysis.cursor_path' => $cursorPath]);

    // Not asserting the exact rendered table text beyond the run name: Symfony
    // Console's Table component wraps cell content to fit the (undetectable, so
    // assumed-narrow) terminal width in this non-interactive test run, which can
    // split a status word across lines. The in-progress/finished/dry-run
    // classification itself is covered by StaticAnalysisScanStatusReporterTest.
    $this->artisan('staticanalysis:scan-status')
        ->expectsOutputToContain('20260101T000000Z')
        ->assertSuccessful();

    File::deleteDirectory($importPath);
    File::deleteDirectory($cursorPath);
});
