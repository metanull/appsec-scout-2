<?php

use App\Assets\StaticAnalysis\PendingStaticAnalysisScanImporter;
use App\Models\Attachment;
use App\Models\ErrorLog;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

function setUpStaticAnalysisTestDirectories(): array
{
    $importPath = sys_get_temp_dir() . '/static-analysis-import-test-' . uniqid();
    $cursorPath = sys_get_temp_dir() . '/static-analysis-cursor-test-' . uniqid();
    File::ensureDirectoryExists($importPath);
    File::ensureDirectoryExists($cursorPath);
    config(['static_analysis.import_path' => $importPath, 'static_analysis.cursor_path' => $cursorPath]);

    return [$importPath, $cursorPath];
}

function tearDownStaticAnalysisTestDirectories(string $importPath, string $cursorPath): void
{
    File::deleteDirectory($importPath);
    File::deleteDirectory($cursorPath);
}

function writeStaticAnalysisResultLine(array $overrides = []): string
{
    return json_encode(array_merge([
        'project' => 'Payments',
        'repository' => 'payments-api',
        'projectId' => 'project-guid-1',
        'repositoryId' => 'repo-guid-1',
        'webUrl' => 'https://dev.azure.com/org/Payments/_git/payments-api',
        'cloned' => true,
        'solutions' => [],
        'dotnetAnalysisGenerated' => true,
        'dotnetAnalysisPath' => 'Payments/payments-api.dotnet.sarif',
        'javaAnalysisGenerated' => false,
        'javaAnalysisPath' => '',
    ], $overrides), JSON_THROW_ON_ERROR);
}

it('imports newly landed reports and advances the per-run cursor', function () {
    [$importPath, $cursorPath] = setUpStaticAnalysisTestDirectories();
    $runDir = $importPath . '/20260101T000000Z';
    File::ensureDirectoryExists($runDir . '/Payments');
    File::put($runDir . '/Payments/payments-api.dotnet.sarif', '{"runs":[]}');
    File::put($runDir . '/run.jsonl', writeStaticAnalysisResultLine() . "\n");

    $stats = app(PendingStaticAnalysisScanImporter::class)->importPending();

    expect($stats)->toBe(['runsSeen' => 1, 'linesImported' => 1, 'reportsImported' => 1, 'reportsFailed' => 0, 'aborted' => false]);

    $system = SoftwareSystem::query()->where('source_id', 'azdo')->where('source_system_id', 'project-guid-1')->first();
    expect($system)->not()->toBeNull()
        ->and($system?->name)->toBe('Payments');

    $container = SecurityContainer::query()->where('source_container_id', 'repo-guid-1')->first();
    expect($container)->not()->toBeNull()
        ->and($container?->name)->toBe('payments-api')
        ->and(Attachment::query()->where('owner_type', SecurityContainer::class)->where('owner_id', $container?->id)->where('kind', 'code-quality-dotnet')->count())->toBe(1);

    expect(trim(File::get($cursorPath . '/20260101T000000Z.processed')))->toBe('1');

    tearDownStaticAnalysisTestDirectories($importPath, $cursorPath);
});

it('imports both dotnet and java reports for the same repository line', function () {
    [$importPath, $cursorPath] = setUpStaticAnalysisTestDirectories();
    $runDir = $importPath . '/20260101T000000Z';
    File::ensureDirectoryExists($runDir . '/Payments');
    File::put($runDir . '/Payments/payments-api.dotnet.sarif', '{"runs":[]}');
    File::put($runDir . '/Payments/payments-api.java.sarif', '{"runs":[]}');
    File::put($runDir . '/run.jsonl', writeStaticAnalysisResultLine([
        'javaAnalysisGenerated' => true,
        'javaAnalysisPath' => 'Payments/payments-api.java.sarif',
    ]) . "\n");

    $stats = app(PendingStaticAnalysisScanImporter::class)->importPending();

    expect($stats)->toBe(['runsSeen' => 1, 'linesImported' => 1, 'reportsImported' => 2, 'reportsFailed' => 0, 'aborted' => false]);

    $container = SecurityContainer::query()->where('source_container_id', 'repo-guid-1')->firstOrFail();
    expect(Attachment::query()->where('owner_id', $container->id)->where('kind', 'code-quality-dotnet')->count())->toBe(1)
        ->and(Attachment::query()->where('owner_id', $container->id)->where('kind', 'code-quality-java')->count())->toBe(1);

    tearDownStaticAnalysisTestDirectories($importPath, $cursorPath);
});

it('does not reimport lines already covered by the cursor', function () {
    [$importPath, $cursorPath] = setUpStaticAnalysisTestDirectories();
    $runDir = $importPath . '/20260101T000000Z';
    File::ensureDirectoryExists($runDir . '/Payments');
    File::put($runDir . '/Payments/payments-api.dotnet.sarif', '{"runs":[]}');
    File::put($runDir . '/run.jsonl', writeStaticAnalysisResultLine() . "\n");

    $importer = app(PendingStaticAnalysisScanImporter::class);
    $importer->importPending();
    $second = $importer->importPending();

    expect($second)->toBe(['runsSeen' => 1, 'linesImported' => 0, 'reportsImported' => 0, 'reportsFailed' => 0, 'aborted' => false])
        ->and(Attachment::query()->where('kind', 'code-quality-dotnet')->count())->toBe(1);

    tearDownStaticAnalysisTestDirectories($importPath, $cursorPath);
});

it('logs a failure and still advances the cursor when a report file is missing', function () {
    [$importPath, $cursorPath] = setUpStaticAnalysisTestDirectories();
    $runDir = $importPath . '/20260101T000000Z';
    File::ensureDirectoryExists($runDir);
    File::put($runDir . '/run.jsonl', writeStaticAnalysisResultLine(['dotnetAnalysisPath' => 'Payments/missing.dotnet.sarif']) . "\n");

    $stats = app(PendingStaticAnalysisScanImporter::class)->importPending();

    expect($stats)->toBe(['runsSeen' => 1, 'linesImported' => 1, 'reportsImported' => 0, 'reportsFailed' => 1, 'aborted' => false])
        ->and(Attachment::query()->where('kind', 'code-quality-dotnet')->count())->toBe(0)
        ->and(ErrorLog::query()->where('channel', 'static-analysis-import')->count())->toBe(1);

    tearDownStaticAnalysisTestDirectories($importPath, $cursorPath);
});

it('skips a run directory marked with .skip-import', function () {
    [$importPath, $cursorPath] = setUpStaticAnalysisTestDirectories();
    $runDir = $importPath . '/20260101T000000Z';
    File::ensureDirectoryExists($runDir . '/Payments');
    File::put($runDir . '/Payments/payments-api.dotnet.sarif', '{"runs":[]}');
    File::put($runDir . '/run.jsonl', writeStaticAnalysisResultLine() . "\n");
    File::put($runDir . '/.skip-import', '');

    $stats = app(PendingStaticAnalysisScanImporter::class)->importPending();

    expect($stats)->toBe(['runsSeen' => 0, 'linesImported' => 0, 'reportsImported' => 0, 'reportsFailed' => 0, 'aborted' => false])
        ->and(Attachment::query()->where('kind', 'code-quality-dotnet')->count())->toBe(0)
        ->and(File::exists($cursorPath . '/20260101T000000Z.processed'))->toBeFalse();

    tearDownStaticAnalysisTestDirectories($importPath, $cursorPath);
});

it('returns zeroed stats when the import directory does not exist', function () {
    $importPath = sys_get_temp_dir() . '/static-analysis-import-missing-' . uniqid();
    $cursorPath = sys_get_temp_dir() . '/static-analysis-cursor-missing-' . uniqid();
    config(['static_analysis.import_path' => $importPath, 'static_analysis.cursor_path' => $cursorPath]);

    $stats = app(PendingStaticAnalysisScanImporter::class)->importPending();

    expect($stats)->toBe(['runsSeen' => 0, 'linesImported' => 0, 'reportsImported' => 0, 'reportsFailed' => 0, 'aborted' => false]);
});

it('aborts without importing or touching the cursor when the database is unreachable', function () {
    [$importPath, $cursorPath] = setUpStaticAnalysisTestDirectories();
    $runDir = $importPath . '/20260101T000000Z';
    File::ensureDirectoryExists($runDir . '/Payments');
    File::put($runDir . '/Payments/payments-api.dotnet.sarif', '{"runs":[]}');
    File::put($runDir . '/run.jsonl', writeStaticAnalysisResultLine() . "\n");

    DB::shouldReceive('select')->once()->with('select 1')->andThrow(new RuntimeException('database unreachable'));

    $stats = app(PendingStaticAnalysisScanImporter::class)->importPending();

    expect($stats)->toBe(['runsSeen' => 0, 'linesImported' => 0, 'reportsImported' => 0, 'reportsFailed' => 0, 'aborted' => true])
        ->and(File::exists($cursorPath . '/20260101T000000Z.processed'))->toBeFalse()
        ->and(Attachment::query()->count())->toBe(0);

    tearDownStaticAnalysisTestDirectories($importPath, $cursorPath);
});

it('aborts without importing or touching the cursor when the queue backend is unreachable', function () {
    [$importPath, $cursorPath] = setUpStaticAnalysisTestDirectories();
    $runDir = $importPath . '/20260101T000000Z';
    File::ensureDirectoryExists($runDir . '/Payments');
    File::put($runDir . '/Payments/payments-api.dotnet.sarif', '{"runs":[]}');
    File::put($runDir . '/run.jsonl', writeStaticAnalysisResultLine() . "\n");

    $fakeConnection = Mockery::mock();
    $fakeConnection->shouldReceive('size')->once()->andThrow(new RuntimeException('queue unreachable'));
    Queue::shouldReceive('connection')->once()->andReturn($fakeConnection);

    $stats = app(PendingStaticAnalysisScanImporter::class)->importPending();

    expect($stats)->toBe(['runsSeen' => 0, 'linesImported' => 0, 'reportsImported' => 0, 'reportsFailed' => 0, 'aborted' => true])
        ->and(File::exists($cursorPath . '/20260101T000000Z.processed'))->toBeFalse()
        ->and(Attachment::query()->count())->toBe(0);

    tearDownStaticAnalysisTestDirectories($importPath, $cursorPath);
});

it('prunes a cursor whose run directory no longer exists on disk', function () {
    [$importPath, $cursorPath] = setUpStaticAnalysisTestDirectories();
    File::put($cursorPath . '/20250101T000000Z.processed', '5');

    $stats = app(PendingStaticAnalysisScanImporter::class)->importPending();

    expect($stats['aborted'])->toBeFalse()
        ->and(File::exists($cursorPath . '/20250101T000000Z.processed'))->toBeFalse();

    tearDownStaticAnalysisTestDirectories($importPath, $cursorPath);
});

it('aborts mid-run without advancing the cursor when an unexpected error occurs importing a report', function () {
    [$importPath, $cursorPath] = setUpStaticAnalysisTestDirectories();
    $runDir = $importPath . '/20260101T000000Z';
    File::ensureDirectoryExists($runDir . '/Payments');
    File::put($runDir . '/Payments/payments-api.dotnet.sarif', '{"runs":[]}');
    File::ensureDirectoryExists($runDir . '/Billing');
    File::put($runDir . '/Billing/billing-api.dotnet.sarif', '{"runs":[]}');
    File::put($runDir . '/run.jsonl',
        writeStaticAnalysisResultLine() . "\n" .
        writeStaticAnalysisResultLine([
            'project' => 'Billing',
            'repository' => 'billing-api',
            'projectId' => 'project-guid-2',
            'repositoryId' => 'repo-guid-2',
            'dotnetAnalysisPath' => 'Billing/billing-api.dotnet.sarif',
        ]) . "\n",
    );

    // The file genuinely exists (passes the isFile check), but reading it fails —
    // simulating an infrastructure-level failure distinct from a missing report.
    $failingPath = $runDir . '/Payments/payments-api.dotnet.sarif';
    app()->bind(Filesystem::class, function () use ($failingPath) {
        return new class($failingPath) extends Filesystem
        {
            public function __construct(private readonly string $failingPath) {}

            public function get($path, $lock = false)
            {
                if ($path === $this->failingPath) {
                    throw new RuntimeException('disk read failure');
                }

                return parent::get($path, $lock);
            }
        };
    });

    $stats = app(PendingStaticAnalysisScanImporter::class)->importPending();

    expect($stats['aborted'])->toBeTrue()
        ->and($stats['reportsImported'])->toBe(0)
        ->and($stats['linesImported'])->toBe(0)
        ->and(File::exists($cursorPath . '/20260101T000000Z.processed'))->toBeFalse()
        ->and(Attachment::query()->count())->toBe(0);

    tearDownStaticAnalysisTestDirectories($importPath, $cursorPath);
});
