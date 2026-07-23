<?php

use App\Assets\Sbom\PendingSbomScanImporter;
use App\Models\Attachment;
use App\Models\ErrorLog;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Sources\Context\SourceContextFacts;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Queue;

function setUpSbomTestDirectories(): array
{
    $importPath = sys_get_temp_dir() . '/sbom-import-test-' . uniqid();
    $cursorPath = sys_get_temp_dir() . '/sbom-cursor-test-' . uniqid();
    File::ensureDirectoryExists($importPath);
    File::ensureDirectoryExists($cursorPath);
    config(['sbom.import_path' => $importPath, 'sbom.cursor_path' => $cursorPath]);

    return [$importPath, $cursorPath];
}

function tearDownSbomTestDirectories(string $importPath, string $cursorPath): void
{
    File::deleteDirectory($importPath);
    File::deleteDirectory($cursorPath);
}

function writeSbomResultLine(array $overrides = []): string
{
    return json_encode(array_merge([
        'project' => 'Payments',
        'repository' => 'payments-api',
        'projectId' => 'project-guid-1',
        'repositoryId' => 'repo-guid-1',
        'webUrl' => 'https://org@dev.azure.com/org/Payments/_git/payments-api',
        'repositoryWebUrl' => 'https://dev.azure.com/org/Payments/_git/payments-api',
        'defaultBranch' => 'refs/heads/main',
        'projectDescription' => 'Payments platform services',
        'projectUrl' => 'https://dev.azure.com/org/_apis/projects/project-guid-1',
        'cloned' => true,
        'solutions' => [],
        'sbomGenerated' => true,
        'sbomPath' => 'Payments/payments-api.cdx.json',
        'vulnerabilitiesGenerated' => false,
        'vulnerabilitiesPath' => '',
        'secretsGenerated' => false,
        'secretsPath' => '',
    ], $overrides), JSON_THROW_ON_ERROR);
}

it('imports newly landed reports and advances the per-run cursor', function () {
    [$importPath, $cursorPath] = setUpSbomTestDirectories();
    $runDir = $importPath . '/20260101T000000Z';
    File::ensureDirectoryExists($runDir . '/Payments');
    File::put($runDir . '/Payments/payments-api.cdx.json', '{"components":[]}');
    File::put($runDir . '/run.jsonl', writeSbomResultLine() . "\n");

    $stats = app(PendingSbomScanImporter::class)->importPending();

    expect($stats)->toBe(['runsSeen' => 1, 'linesImported' => 1, 'reportsImported' => 1, 'reportsFailed' => 0, 'aborted' => false]);

    $system = SoftwareSystem::query()->where('source_id', 'azdo')->where('source_system_id', 'project-guid-1')->first();
    expect($system)->not()->toBeNull()
        ->and($system?->name)->toBe('Payments');

    $container = SecurityContainer::query()->where('source_container_id', 'repo-guid-1')->first();
    expect($container)->not()->toBeNull()
        ->and($container?->name)->toBe('payments-api')
        ->and($container?->kind)->toBe('repository')
        ->and(Attachment::query()->where('owner_type', SecurityContainer::class)->where('owner_id', $container?->id)->where('kind', 'sbom')->count())->toBe(1);

    expect(trim(File::get($cursorPath . '/20260101T000000Z.processed')))->toBe('1');

    tearDownSbomTestDirectories($importPath, $cursorPath);
});

it('enriches an ops-first system and container with the same facts a source sync writes', function () {
    [$importPath, $cursorPath] = setUpSbomTestDirectories();
    $runDir = $importPath . '/20260101T000000Z';
    File::ensureDirectoryExists($runDir . '/Payments');
    File::put($runDir . '/Payments/payments-api.cdx.json', '{"components":[]}');
    File::put($runDir . '/run.jsonl', writeSbomResultLine() . "\n");

    app(PendingSbomScanImporter::class)->importPending();

    $system = SoftwareSystem::query()->where('source_id', 'azdo')->where('source_system_id', 'project-guid-1')->firstOrFail();
    expect($system->description)->toBe('Payments platform services')
        ->and($system->url)->toBe('https://dev.azure.com/org/project-guid-1')
        ->and(SourceContextFacts::getString($system->metadata ?? [], SourceContextFacts::AZDO_PROJECT_ID))->toBe('project-guid-1')
        ->and(SourceContextFacts::getString($system->metadata ?? [], SourceContextFacts::AZDO_PROJECT_NAME))->toBe('Payments')
        ->and(SourceContextFacts::getString($system->metadata ?? [], SourceContextFacts::AZDO_PROJECT_WEB_URL))->toBe('https://dev.azure.com/org/project-guid-1');

    $container = SecurityContainer::query()->where('source_container_id', 'repo-guid-1')->firstOrFail();
    expect($container->url)->toBe('https://dev.azure.com/org/Payments/_git/payments-api')
        ->and(SourceContextFacts::getString($container->metadata ?? [], SourceContextFacts::AZDO_REPOSITORY_ID))->toBe('repo-guid-1')
        ->and(SourceContextFacts::getString($container->metadata ?? [], SourceContextFacts::AZDO_REPOSITORY_WEB_URL))->toBe('https://dev.azure.com/org/Payments/_git/payments-api')
        ->and(SourceContextFacts::getString($container->metadata ?? [], SourceContextFacts::AZDO_REPOSITORY_REMOTE_URL))->toBe('https://org@dev.azure.com/org/Payments/_git/payments-api')
        ->and(SourceContextFacts::getString($container->metadata ?? [], SourceContextFacts::CODE_DEFAULT_BRANCH))->toBe('main')
        ->and(SourceContextFacts::getString($container->metadata ?? [], SourceContextFacts::SOURCE_PROVIDER))->toBe('azure-repos');

    tearDownSbomTestDirectories($importPath, $cursorPath);
});

it('never overwrites an already-existing source-synced system or container', function () {
    [$importPath, $cursorPath] = setUpSbomTestDirectories();

    $system = SoftwareSystem::query()->create([
        'source_id' => 'azdo',
        'source_system_id' => 'project-guid-1',
        'name' => 'Synced Payments',
        'description' => 'Authored by the live sync',
        'url' => 'https://sync.example/project',
        'metadata' => ['azdo' => ['project' => ['id' => 'project-guid-1']], 'sync' => true],
    ]);
    $container = SecurityContainer::query()->create([
        'software_system_id' => $system->id,
        'source_container_id' => 'repo-guid-1',
        'name' => 'synced-repo',
        'kind' => 'repository',
        'url' => 'https://sync.example/repo',
        'metadata' => ['code' => ['default_branch' => 'develop']],
    ]);

    $runDir = $importPath . '/20260101T000000Z';
    File::ensureDirectoryExists($runDir . '/Payments');
    File::put($runDir . '/Payments/payments-api.cdx.json', '{"components":[]}');
    File::put($runDir . '/run.jsonl', writeSbomResultLine() . "\n");

    app(PendingSbomScanImporter::class)->importPending();

    expect($system->fresh())
        ->name->toBe('Synced Payments')
        ->description->toBe('Authored by the live sync')
        ->url->toBe('https://sync.example/project');
    expect($container->fresh())
        ->name->toBe('synced-repo')
        ->url->toBe('https://sync.example/repo')
        ->and(SourceContextFacts::getString($container->fresh()->metadata ?? [], SourceContextFacts::CODE_DEFAULT_BRANCH))->toBe('develop');

    // The attachment still lands on the existing (untouched) row.
    expect(Attachment::query()->where('owner_type', SecurityContainer::class)->where('owner_id', $container->id)->where('kind', 'sbom')->count())->toBe(1);

    tearDownSbomTestDirectories($importPath, $cursorPath);
});

it('does not reimport lines already covered by the cursor', function () {
    [$importPath, $cursorPath] = setUpSbomTestDirectories();
    $runDir = $importPath . '/20260101T000000Z';
    File::ensureDirectoryExists($runDir . '/Payments');
    File::put($runDir . '/Payments/payments-api.cdx.json', '{"components":[]}');
    File::put($runDir . '/run.jsonl', writeSbomResultLine() . "\n");

    $importer = app(PendingSbomScanImporter::class);
    $importer->importPending();
    $second = $importer->importPending();

    expect($second)->toBe(['runsSeen' => 1, 'linesImported' => 0, 'reportsImported' => 0, 'reportsFailed' => 0, 'aborted' => false])
        ->and(Attachment::query()->where('kind', 'sbom')->count())->toBe(1);

    tearDownSbomTestDirectories($importPath, $cursorPath);
});

it('imports only newly appended lines on a subsequent run', function () {
    [$importPath, $cursorPath] = setUpSbomTestDirectories();
    $runDir = $importPath . '/20260101T000000Z';
    File::ensureDirectoryExists($runDir . '/Payments');
    File::put($runDir . '/Payments/payments-api.cdx.json', '{"components":[]}');
    File::put($runDir . '/run.jsonl', writeSbomResultLine() . "\n");

    $importer = app(PendingSbomScanImporter::class);
    $importer->importPending();

    File::ensureDirectoryExists($runDir . '/Billing');
    File::put($runDir . '/Billing/billing-api.cdx.json', '{"components":[]}');
    File::append($runDir . '/run.jsonl', writeSbomResultLine([
        'project' => 'Billing',
        'repository' => 'billing-api',
        'projectId' => 'project-guid-2',
        'repositoryId' => 'repo-guid-2',
        'sbomPath' => 'Billing/billing-api.cdx.json',
    ]) . "\n");

    $second = $importer->importPending();

    expect($second)->toBe(['runsSeen' => 1, 'linesImported' => 1, 'reportsImported' => 1, 'reportsFailed' => 0, 'aborted' => false])
        ->and(Attachment::query()->where('kind', 'sbom')->count())->toBe(2)
        ->and(trim(File::get($cursorPath . '/20260101T000000Z.processed')))->toBe('2');

    tearDownSbomTestDirectories($importPath, $cursorPath);
});

it('logs a failure and still advances the cursor when a report file is missing', function () {
    [$importPath, $cursorPath] = setUpSbomTestDirectories();
    $runDir = $importPath . '/20260101T000000Z';
    File::ensureDirectoryExists($runDir);
    File::put($runDir . '/run.jsonl', writeSbomResultLine(['sbomPath' => 'Payments/missing.cdx.json']) . "\n");

    $stats = app(PendingSbomScanImporter::class)->importPending();

    expect($stats)->toBe(['runsSeen' => 1, 'linesImported' => 1, 'reportsImported' => 0, 'reportsFailed' => 1, 'aborted' => false])
        ->and(Attachment::query()->where('kind', 'sbom')->count())->toBe(0)
        ->and(ErrorLog::query()->where('channel', 'sbom-import')->count())->toBe(1);

    expect(trim(File::get($cursorPath . '/20260101T000000Z.processed')))->toBe('1');

    tearDownSbomTestDirectories($importPath, $cursorPath);
});

it('skips a run directory marked with .skip-import', function () {
    [$importPath, $cursorPath] = setUpSbomTestDirectories();
    $runDir = $importPath . '/20260101T000000Z';
    File::ensureDirectoryExists($runDir . '/Payments');
    File::put($runDir . '/Payments/payments-api.cdx.json', '{"components":[]}');
    File::put($runDir . '/run.jsonl', writeSbomResultLine() . "\n");
    File::put($runDir . '/.skip-import', '');

    $stats = app(PendingSbomScanImporter::class)->importPending();

    expect($stats)->toBe(['runsSeen' => 0, 'linesImported' => 0, 'reportsImported' => 0, 'reportsFailed' => 0, 'aborted' => false])
        ->and(Attachment::query()->where('kind', 'sbom')->count())->toBe(0)
        ->and(File::exists($cursorPath . '/20260101T000000Z.processed'))->toBeFalse();

    tearDownSbomTestDirectories($importPath, $cursorPath);
});

it('returns zeroed stats when the import directory does not exist', function () {
    $importPath = sys_get_temp_dir() . '/sbom-import-missing-' . uniqid();
    $cursorPath = sys_get_temp_dir() . '/sbom-cursor-missing-' . uniqid();
    config(['sbom.import_path' => $importPath, 'sbom.cursor_path' => $cursorPath]);

    $stats = app(PendingSbomScanImporter::class)->importPending();

    expect($stats)->toBe(['runsSeen' => 0, 'linesImported' => 0, 'reportsImported' => 0, 'reportsFailed' => 0, 'aborted' => false]);
});

it('aborts without importing or touching the cursor when the database is unreachable', function () {
    [$importPath, $cursorPath] = setUpSbomTestDirectories();
    $runDir = $importPath . '/20260101T000000Z';
    File::ensureDirectoryExists($runDir . '/Payments');
    File::put($runDir . '/Payments/payments-api.cdx.json', '{"components":[]}');
    File::put($runDir . '/run.jsonl', writeSbomResultLine() . "\n");

    DB::shouldReceive('select')->once()->with('select 1')->andThrow(new RuntimeException('database unreachable'));

    $stats = app(PendingSbomScanImporter::class)->importPending();

    expect($stats)->toBe(['runsSeen' => 0, 'linesImported' => 0, 'reportsImported' => 0, 'reportsFailed' => 0, 'aborted' => true])
        ->and(File::exists($cursorPath . '/20260101T000000Z.processed'))->toBeFalse()
        ->and(Attachment::query()->count())->toBe(0);

    tearDownSbomTestDirectories($importPath, $cursorPath);
});

it('aborts without importing or touching the cursor when the queue backend is unreachable', function () {
    [$importPath, $cursorPath] = setUpSbomTestDirectories();
    $runDir = $importPath . '/20260101T000000Z';
    File::ensureDirectoryExists($runDir . '/Payments');
    File::put($runDir . '/Payments/payments-api.cdx.json', '{"components":[]}');
    File::put($runDir . '/run.jsonl', writeSbomResultLine() . "\n");

    $fakeConnection = Mockery::mock();
    $fakeConnection->shouldReceive('size')->once()->andThrow(new RuntimeException('queue unreachable'));
    Queue::shouldReceive('connection')->once()->andReturn($fakeConnection);

    $stats = app(PendingSbomScanImporter::class)->importPending();

    expect($stats)->toBe(['runsSeen' => 0, 'linesImported' => 0, 'reportsImported' => 0, 'reportsFailed' => 0, 'aborted' => true])
        ->and(File::exists($cursorPath . '/20260101T000000Z.processed'))->toBeFalse()
        ->and(Attachment::query()->count())->toBe(0);

    tearDownSbomTestDirectories($importPath, $cursorPath);
});

it('prunes a cursor whose run directory no longer exists on disk', function () {
    [$importPath, $cursorPath] = setUpSbomTestDirectories();
    File::put($cursorPath . '/20250101T000000Z.processed', '5');

    $stats = app(PendingSbomScanImporter::class)->importPending();

    expect($stats['aborted'])->toBeFalse()
        ->and(File::exists($cursorPath . '/20250101T000000Z.processed'))->toBeFalse();

    tearDownSbomTestDirectories($importPath, $cursorPath);
});

it('keeps a cursor whose run directory still exists on disk', function () {
    [$importPath, $cursorPath] = setUpSbomTestDirectories();
    $runDir = $importPath . '/20260101T000000Z';
    File::ensureDirectoryExists($runDir . '/Payments');
    File::put($runDir . '/Payments/payments-api.cdx.json', '{"components":[]}');
    File::put($runDir . '/run.jsonl', writeSbomResultLine() . "\n");

    app(PendingSbomScanImporter::class)->importPending();

    expect(File::exists($cursorPath . '/20260101T000000Z.processed'))->toBeTrue();

    app(PendingSbomScanImporter::class)->importPending();

    expect(File::exists($cursorPath . '/20260101T000000Z.processed'))->toBeTrue();

    tearDownSbomTestDirectories($importPath, $cursorPath);
});

it('aborts mid-run without advancing the cursor when an unexpected error occurs importing a report', function () {
    [$importPath, $cursorPath] = setUpSbomTestDirectories();
    $runDir = $importPath . '/20260101T000000Z';
    File::ensureDirectoryExists($runDir . '/Payments');
    File::put($runDir . '/Payments/payments-api.cdx.json', '{"components":[]}');
    File::ensureDirectoryExists($runDir . '/Billing');
    File::put($runDir . '/Billing/billing-api.cdx.json', '{"components":[]}');
    File::put($runDir . '/run.jsonl',
        writeSbomResultLine() . "\n" .
        writeSbomResultLine([
            'project' => 'Billing',
            'repository' => 'billing-api',
            'projectId' => 'project-guid-2',
            'repositoryId' => 'repo-guid-2',
            'sbomPath' => 'Billing/billing-api.cdx.json',
        ]) . "\n",
    );

    // The file genuinely exists (passes the isFile check), but reading it fails —
    // simulating an infrastructure-level failure distinct from a missing report.
    $failingPath = $runDir . '/Payments/payments-api.cdx.json';
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

    $stats = app(PendingSbomScanImporter::class)->importPending();

    expect($stats['aborted'])->toBeTrue()
        ->and($stats['reportsImported'])->toBe(0)
        ->and($stats['linesImported'])->toBe(0)
        ->and(File::exists($cursorPath . '/20260101T000000Z.processed'))->toBeFalse()
        ->and(Attachment::query()->count())->toBe(0);

    tearDownSbomTestDirectories($importPath, $cursorPath);
});
