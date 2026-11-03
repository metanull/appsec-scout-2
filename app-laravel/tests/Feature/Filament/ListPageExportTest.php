<?php

use App\Audit\AuditLog;
use App\Filament\Exports\SecurityContainerExporter;
use App\Filament\Exports\SecurityEventExporter;
use App\Filament\Resources\AuditLogResource\Pages\ListAuditLogs;
use App\Filament\Resources\ErrorLogResource\Pages\ListErrorLogs;
use App\Filament\Resources\FailedJobResource\Pages\ListFailedJobs;
use App\Filament\Resources\LocalFindingResource\Pages\ListLocalFindings;
use App\Filament\Resources\PendingSyncResource\Pages\ListPendingSync;
use App\Filament\Resources\RepositoryCollectionRunResource\Pages\ListRepositoryCollectionRuns;
use App\Filament\Resources\RepositoryProviderResource\Pages\ListRepositoryProviders;
use App\Filament\Resources\SecurityContainerResource\Pages\ListSecurityContainers;
use App\Filament\Resources\SecurityEventResource\Pages\ListSecurityEvents;
use App\Filament\Resources\SoftwareAssetResource\Pages\ListSoftwareAssets;
use App\Filament\Resources\SoftwareComponentResource\Pages\ListSoftwareComponents;
use App\Filament\Resources\SoftwareSystemResource\Pages\ListSoftwareSystems;
use App\Filament\Resources\StaticAnalysisRunResource\Pages\ListStaticAnalysisRuns;
use App\Filament\Resources\SyncRunResource\Pages\ListSyncRuns;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Jobs\PruneExports;
use App\Models\ErrorLog;
use App\Models\LocalFinding;
use App\Models\RepositoryCollectionRun;
use App\Models\RepositoryProvider;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareAsset;
use App\Models\SoftwareComponent;
use App\Models\SoftwareSystem;
use App\Models\StaticAnalysisRun;
use App\Models\SyncRun;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Actions\Exports\Models\Export;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use League\Csv\Reader;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    Storage::fake('local');
});

/**
 * The finished export's CSV rows (header first), read back from its chunk files in page order.
 *
 * @return list<list<string>>
 */
function exportedCsvRows(Export $export): array
{
    $disk = $export->getFileDisk();
    $files = collect($disk->files($export->getFileDirectory()))
        ->filter(fn (string $file): bool => str_ends_with($file, '.csv'))
        ->sortBy(fn (string $file): string => str_ends_with($file, 'headers.csv') ? '' : basename($file))
        ->values();

    $rows = [];

    foreach ($files as $file) {
        $csv = Reader::fromString((string) $disk->get($file));

        foreach ($csv->getRecords() as $record) {
            $rows[] = array_map(fn (mixed $cell): string => ltrim((string) $cell, "\u{FEFF}"), array_values($record));
        }
    }

    return $rows;
}

function latestExport(): Export
{
    return Export::query()->latest('id')->firstOrFail();
}

function containerExportFor(User $user): Export
{
    SecurityContainer::factory()->create();

    Livewire::actingAs($user)
        ->test(ListSecurityContainers::class)
        ->callAction(TestAction::make('export')->table());

    return latestExport();
}

function readerUser(): User
{
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    return $user;
}

it('exports every alert matching the active filter, not just the current page', function () {
    SecurityEvent::factory()->count(30)->create(['severity' => 'critical']);
    SecurityEvent::factory()->count(3)->create(['severity' => 'low']);

    Livewire::actingAs(readerUser())
        ->test(ListSecurityEvents::class)
        ->filterTable('severity', ['critical'])
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoErrors();

    $export = latestExport();
    $rows = exportedCsvRows($export);
    $severityIndex = array_search('Severity', $rows[0], true);

    expect($export->exporter)->toBe(SecurityEventExporter::class)
        ->and($export->total_rows)->toBe(30)
        ->and(array_slice($rows, 1))->toHaveCount(30)
        ->and(collect(array_slice($rows, 1))->pluck($severityIndex)->unique()->all())->toBe(['critical']);
});

it('exports only the containers matching the table search, with the list alert counts', function () {
    $alpha = SecurityContainer::factory()->count(27)->sequence(fn ($sequence) => ['name' => 'alpha-' . $sequence->index])->create();
    SecurityContainer::factory()->count(2)->sequence(fn ($sequence) => ['name' => 'beta-' . $sequence->index])->create();
    SecurityEvent::factory()->secret()->forContainer($alpha->first())->create();

    Livewire::actingAs(readerUser())
        ->test(ListSecurityContainers::class)
        ->searchTable('alpha')
        ->callAction(TestAction::make('export')->table());

    $rows = exportedCsvRows(latestExport());
    $header = $rows[0];
    $byName = collect(array_slice($rows, 1))->keyBy(array_search('Name', $header, true));

    expect($byName)->toHaveCount(27)
        ->and($byName->keys()->every(fn (string $name): bool => str_starts_with($name, 'alpha-')))->toBeTrue()
        ->and($byName[$alpha->first()->name][array_search('Critical', $header, true)])->toBe('1');
});

it('preselects the columns visible in the table', function () {
    SecurityContainer::factory()->create();

    Livewire::actingAs(readerUser())
        ->test(ListSecurityContainers::class)
        ->callAction(TestAction::make('export')->table());

    expect(exportedCsvRows(latestExport())[0])
        ->toBe(['Name', 'Kind', 'System', 'Open', 'Critical', 'High', 'Medium', 'Last seen', 'Removed']);
});

it('neutralises spreadsheet formulas in exported values', function () {
    SecurityContainer::factory()->create(['name' => '=HYPERLINK("https://example.test")']);

    Livewire::actingAs(readerUser())
        ->test(ListSecurityContainers::class)
        ->callAction(TestAction::make('export')->table());

    expect(exportedCsvRows(latestExport())[1][0])->toBe('\'=HYPERLINK("https://example.test")');
});

it('exports every list page', function (string $page, Closure $seed) {
    $seed();
    $admin = User::factory()->create();
    $admin->syncRoles(['Admin']);

    Livewire::actingAs($admin)
        ->test($page)
        ->removeTableFilters()
        ->callAction(TestAction::make('export')->table())
        ->assertHasNoErrors();

    $export = latestExport();
    $rows = exportedCsvRows($export);

    expect($export->total_rows)->toBeGreaterThanOrEqual(1)
        ->and($rows[0])->not->toBeEmpty()
        ->and(array_slice($rows, 1))->toHaveCount($export->total_rows);
})->with([
    'alerts' => [ListSecurityEvents::class, fn () => SecurityEvent::factory()->create()],
    'pending sync' => [ListPendingSync::class, fn () => SecurityEvent::factory()->create(['is_dirty' => true, 'pending_state' => 'dismissed'])],
    'containers' => [ListSecurityContainers::class, fn () => SecurityContainer::factory()->create()],
    'software systems' => [ListSoftwareSystems::class, fn () => SoftwareSystem::factory()->create()],
    'software assets' => [ListSoftwareAssets::class, fn () => SoftwareAsset::factory()->create()],
    'dependencies' => [ListSoftwareComponents::class, function () {
        $container = SecurityContainer::factory()->create();
        SoftwareComponent::query()->create([
            'owner_type' => SecurityContainer::class,
            'owner_id' => $container->id,
            'software_system_id' => $container->software_system_id,
            'name' => 'node',
            'version' => '24.0.4',
            'ecosystem' => 'npm',
            'purl' => 'pkg:npm/%40types/node@24.0.4',
        ]);
    }],
    'local findings' => [ListLocalFindings::class, function () {
        $container = SecurityContainer::factory()->create();
        LocalFinding::query()->create([
            'owner_type' => SecurityContainer::class,
            'owner_id' => $container->id,
            'kind' => LocalFinding::KIND_VULNERABILITY,
            'rule_id' => 'CVE-2026-0001',
            'title' => 'Vulnerable package',
            'severity' => 'high',
            'file_path' => 'package-lock.json',
        ]);
    }],
    'users' => [ListUsers::class, fn () => User::factory()->create()],
    'audit log' => [ListAuditLogs::class, fn () => AuditLog::query()->create(['actor_kind' => 'user', 'action' => 'test.action', 'payload_json' => ['key' => 'value']])],
    'error log' => [ListErrorLogs::class, fn () => ErrorLog::query()->create(['channel' => 'sync', 'level' => 'ERROR', 'message' => 'Sync failed', 'context_json' => ['source_id' => 'azdo'], 'occurred_at' => now()])],
    'repository providers' => [ListRepositoryProviders::class, fn () => RepositoryProvider::factory()->create()],
    'sync runs' => [ListSyncRuns::class, fn () => SyncRun::query()->create(['source_id' => 'fake', 'started_at' => now()->subMinute(), 'finished_at' => now(), 'status' => 'success', 'counts_json' => ['events_created' => 1]])],
    'collection runs' => [ListRepositoryCollectionRuns::class, fn () => RepositoryCollectionRun::query()->create(['source_control_id' => 'azdo-repos', 'started_at' => now(), 'status' => 'success', 'counts_json' => []])],
    'analysis runs' => [ListStaticAnalysisRuns::class, fn () => StaticAnalysisRun::query()->create(['source_control_id' => 'azdo-repos', 'started_at' => now(), 'status' => 'success', 'counts_json' => []])],
    'failed jobs' => [ListFailedJobs::class, fn () => DB::table('failed_jobs')->insert(['uuid' => (string) str()->uuid(), 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}', 'exception' => 'boom', 'failed_at' => now()])],
]);
it('hides the export action from a user without the exports.create permission', function () {
    Role::findByName('Reader', 'web')->revokePermissionTo('exports.create');

    Livewire::actingAs(readerUser())
        ->test(ListSecurityEvents::class)
        ->assertActionHidden(TestAction::make('export')->table());
});

it('audits the export request with its active filters and columns', function () {
    SecurityEvent::factory()->count(2)->create(['severity' => 'high']);
    $user = readerUser();

    Livewire::actingAs($user)
        ->test(ListSecurityEvents::class)
        ->filterTable('severity', ['high'])
        ->callAction(TestAction::make('export')->table());

    $export = latestExport();
    $log = AuditLog::query()->where('action', 'export_requested')->sole();

    expect($log->user_id)->toBe($user->id)
        ->and($log->subject_type)->toBe('export')
        ->and($log->subject_id)->toBe((string) $export->id)
        ->and($log->payload_json['exporter'])->toBe(SecurityEventExporter::class)
        ->and($log->payload_json['model'])->toBe(SecurityEvent::class)
        ->and($log->payload_json['total_rows'])->toBe(2)
        ->and($log->payload_json['columns'])->toContain('severity', 'title')
        ->and($log->payload_json['filters'])->toContain('Severity: High');
});

it('grants exports.create to every seeded role', function (string $role) {
    expect(Role::findByName($role, 'web')->hasPermissionTo('exports.create'))->toBeTrue();
})->with(['Reader', 'Triage', 'Plan', 'Sync', 'Admin']);

describe('export download route', function () {
    it('lets the TOTP-enrolled owner download the export', function () {
        $owner = User::factory()->withTotp()->create();
        $owner->syncRoles(['Reader']);
        $export = containerExportFor($owner);

        $this->actingAs($owner)
            ->get(route('filament.exports.download', ['export' => $export, 'format' => 'csv']))
            ->assertOk();
    });

    it('walls a password-authenticated session without TOTP behind MFA enrollment', function () {
        $owner = readerUser();
        $export = containerExportFor($owner);

        $this->actingAs($owner)
            ->get(route('filament.exports.download', ['export' => $export, 'format' => 'csv']))
            ->assertRedirect(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
    });

    it('forbids another user from downloading the export', function () {
        $export = containerExportFor(readerUser());
        $other = User::factory()->withTotp()->create();
        $other->syncRoles(['Admin']);

        $this->actingAs($other)
            ->get(route('filament.exports.download', ['export' => $export, 'format' => 'csv']))
            ->assertForbidden();
    });

    it('redirects a guest to sign in', function () {
        $export = containerExportFor(readerUser());
        auth()->logout();

        $this->get(route('filament.exports.download', ['export' => $export, 'format' => 'csv']))
            ->assertRedirect();
    });
});

it('prunes exports and their files past the retention window', function () {
    $user = readerUser();
    $old = containerExportFor($user);
    $recent = containerExportFor($user);
    $old->forceFill(['created_at' => now()->subDays(8)])->save();
    $oldDirectory = $old->getFileDirectory();

    expect(Storage::disk('local')->files($oldDirectory))->not->toBeEmpty();

    $pruned = (new PruneExports(7))->handle();

    expect($pruned)->toBe(1)
        ->and(Export::query()->pluck('id')->all())->toBe([$recent->id])
        ->and(Storage::disk('local')->files($oldDirectory))->toBeEmpty()
        ->and(Storage::disk('local')->files($recent->getFileDirectory()))->not->toBeEmpty();
});
