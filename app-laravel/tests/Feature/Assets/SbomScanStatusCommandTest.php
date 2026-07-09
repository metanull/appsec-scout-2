<?php

use Illuminate\Support\Facades\File;

it('reports no runs found when the import directory is empty', function () {
    config([
        'sbom.import_path' => sys_get_temp_dir() . '/sbom-status-cmd-missing-' . uniqid(),
        'sbom.cursor_path' => sys_get_temp_dir() . '/sbom-status-cmd-cursor-missing-' . uniqid(),
    ]);

    $this->artisan('sbom:scan-status')
        ->expectsOutputToContain('No sbom-scan runs found.')
        ->assertSuccessful();
});

it('renders a status table for an in-progress run', function () {
    $importPath = sys_get_temp_dir() . '/sbom-status-cmd-test-' . uniqid();
    $cursorPath = sys_get_temp_dir() . '/sbom-status-cmd-cursor-test-' . uniqid();
    File::ensureDirectoryExists($importPath . '/20260101T000000Z');
    File::put($importPath . '/20260101T000000Z/run.jsonl', json_encode(['project' => 'Payments', 'repository' => 'payments-api'], JSON_THROW_ON_ERROR) . "\n");
    config(['sbom.import_path' => $importPath, 'sbom.cursor_path' => $cursorPath]);

    // Not asserting the exact rendered table text beyond the run name: Symfony
    // Console's Table component wraps cell content to fit the (undetectable, so
    // assumed-narrow) terminal width in this non-interactive test run, which can
    // split a status word across lines. The in-progress/finished/dry-run
    // classification itself is covered by SbomScanStatusReporterTest.
    $this->artisan('sbom:scan-status')
        ->expectsOutputToContain('20260101T000000Z')
        ->assertSuccessful();

    File::deleteDirectory($importPath);
    File::deleteDirectory($cursorPath);
});
