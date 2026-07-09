<?php

use App\Models\Attachment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

it('imports pending scan reports and prints a summary', function () {
    $importPath = sys_get_temp_dir() . '/sbom-import-cmd-test-' . uniqid();
    $cursorPath = sys_get_temp_dir() . '/sbom-cursor-cmd-test-' . uniqid();
    File::ensureDirectoryExists($importPath . '/20260101T000000Z/Payments');
    File::put($importPath . '/20260101T000000Z/Payments/payments-api.cdx.json', '{"components":[]}');
    File::put($importPath . '/20260101T000000Z/run.jsonl', json_encode([
        'project' => 'Payments',
        'repository' => 'payments-api',
        'projectId' => 'project-guid-1',
        'repositoryId' => 'repo-guid-1',
        'sbomGenerated' => true,
        'sbomPath' => 'Payments/payments-api.cdx.json',
        'vulnerabilitiesGenerated' => false,
        'vulnerabilitiesPath' => '',
        'secretsGenerated' => false,
        'secretsPath' => '',
    ], JSON_THROW_ON_ERROR) . "\n");
    config(['sbom.import_path' => $importPath, 'sbom.cursor_path' => $cursorPath]);

    $this->artisan('sbom:import-pending-scans')
        ->expectsOutputToContain('Imported 1 report(s)')
        ->assertSuccessful();

    expect(Attachment::query()->where('kind', 'sbom')->count())->toBe(1);

    File::deleteDirectory($importPath);
    File::deleteDirectory($cursorPath);
});

it('reports zero imports when nothing is pending', function () {
    config([
        'sbom.import_path' => sys_get_temp_dir() . '/sbom-import-cmd-empty-' . uniqid(),
        'sbom.cursor_path' => sys_get_temp_dir() . '/sbom-cursor-cmd-empty-' . uniqid(),
    ]);

    $this->artisan('sbom:import-pending-scans')
        ->expectsOutputToContain('Imported 0 report(s)')
        ->assertSuccessful();
});

it('fails and reports an abort when the database is unreachable', function () {
    config([
        'sbom.import_path' => sys_get_temp_dir() . '/sbom-import-cmd-db-down-' . uniqid(),
        'sbom.cursor_path' => sys_get_temp_dir() . '/sbom-cursor-cmd-db-down-' . uniqid(),
    ]);
    DB::shouldReceive('select')->once()->with('select 1')->andThrow(new RuntimeException('database unreachable'));

    $this->artisan('sbom:import-pending-scans')
        ->expectsOutputToContain('Aborted after importing 0 report(s)')
        ->assertFailed();
});
