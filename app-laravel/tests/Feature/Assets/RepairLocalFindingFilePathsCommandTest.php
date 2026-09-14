<?php

use App\Audit\AuditLog;
use App\Models\LocalFinding;
use App\Models\LocalFindingComment;
use App\Models\LocalFindingWorkItemLink;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use Illuminate\Support\Carbon;

beforeEach(function () {
    config(['static_analysis_collection.workspace_path' => '/workspace-scratch']);
});

/** @param array<string, mixed> $overrides */
function makeLocalFinding(SecurityContainer $container, array $overrides = []): LocalFinding
{
    return LocalFinding::query()->create(array_merge([
        'owner_type' => SecurityContainer::class,
        'owner_id' => $container->id,
        'kind' => LocalFinding::KIND_CODE_QUALITY,
        'rule_id' => 'CA2100',
        'title' => 'Review SQL queries for security vulnerabilities',
        'file_path' => 'file:///workspace-scratch/9c1b2d3e/work/Sources/A.cs',
        'start_line' => 42,
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ], $overrides));
}

it('repairs an in-place finding stored under the workspace-scratch/<uuid>/work shape', function () {
    $container = SecurityContainer::factory()->create();

    $finding = makeLocalFinding($container, [
        'file_path' => 'file:///workspace-scratch/9c1b2d3e/work/Sources/A.cs',
    ]);

    $this->artisan('local-findings:repair-file-paths')->assertSuccessful();

    $finding->refresh();

    expect($finding->file_path)->toBe('Sources/A.cs')
        ->and($finding->dedup_hash)->toBe(LocalFinding::computeDedupHash('CA2100', 'Sources/A.cs', 42));
});

it('repairs an in-place finding stored under the /tmp/tmp.XXXXXXXXXX shape', function () {
    $container = SecurityContainer::factory()->create();

    $finding = makeLocalFinding($container, [
        'file_path' => 'file:///tmp/tmp.aBcDeF1234/src/index.js',
        'rule_id' => 'javascript.lang.security.detect-eval.detect-eval',
    ]);

    $this->artisan('local-findings:repair-file-paths')->assertSuccessful();

    $finding->refresh();

    expect($finding->file_path)->toBe('src/index.js')
        ->and($finding->dedup_hash)->toBe(LocalFinding::computeDedupHash('javascript.lang.security.detect-eval.detect-eval', 'src/index.js', 42));
});

it('merges into an existing clean twin, moving a comment and a work-item link and keeping the earliest first_seen_at', function () {
    $container = SecurityContainer::factory()->create();

    $stale = makeLocalFinding($container, [
        'file_path' => 'file:///workspace-scratch/9c1b2d3e/work/Sources/A.cs',
        'first_seen_at' => Carbon::parse('2026-01-01 00:00:00'),
    ]);
    $twin = makeLocalFinding($container, [
        'file_path' => 'Sources/A.cs',
        'first_seen_at' => Carbon::parse('2026-03-01 00:00:00'),
    ]);

    $comment = LocalFindingComment::query()->create([
        'local_finding_id' => $stale->id,
        'body' => 'Looks like a real issue.',
        'created_at' => now(),
    ]);
    $workItemLink = LocalFindingWorkItemLink::query()->create([
        'local_finding_id' => $stale->id,
        'tracker_id' => 'jira',
        'work_item_id' => 'SEC-123',
        'created_at' => now(),
    ]);

    $this->artisan('local-findings:repair-file-paths')->assertSuccessful();

    expect(LocalFinding::query()->where('owner_id', $container->id)->count())->toBe(1)
        ->and(LocalFinding::query()->whereKey($stale->id)->exists())->toBeFalse();

    $twin->refresh();
    $comment->refresh();
    $workItemLink->refresh();

    expect($comment->local_finding_id)->toBe($twin->id)
        ->and($workItemLink->local_finding_id)->toBe($twin->id)
        ->and($twin->first_seen_at->equalTo(Carbon::parse('2026-01-01 00:00:00')))->toBeTrue();
});

it('copies the correlated security event onto the twin when only the stale row carries one', function () {
    $container = SecurityContainer::factory()->create();
    $event = SecurityEvent::factory()->forContainer($container)->create();

    $stale = makeLocalFinding($container, [
        'file_path' => 'file:///workspace-scratch/9c1b2d3e/work/Sources/A.cs',
        'correlated_security_event_id' => $event->id,
        'correlation_method' => 'package_version',
    ]);
    makeLocalFinding($container, ['file_path' => 'Sources/A.cs']);

    $this->artisan('local-findings:repair-file-paths')->assertSuccessful();

    $twin = LocalFinding::query()->where('owner_id', $container->id)->firstOrFail();

    expect($twin->correlated_security_event_id)->toBe($event->id)
        ->and($twin->correlation_method)->toBe('package_version');
});

it('skips a row whose absolute path does not match either known collector shape, and reports it', function () {
    $container = SecurityContainer::factory()->create();

    makeLocalFinding($container, ['file_path' => 'file:///some/other/unrecognized/path/A.cs']);

    $result = $this->artisan('local-findings:repair-file-paths');
    $result->assertSuccessful();
    $result->expectsOutputToContain('skipped=1');
});

it('changes nothing in --dry-run mode', function () {
    $container = SecurityContainer::factory()->create();

    $stale = makeLocalFinding($container, ['file_path' => 'file:///workspace-scratch/9c1b2d3e/work/Sources/A.cs']);
    $twin = makeLocalFinding($container, ['file_path' => 'Sources/A.cs']);

    $comment = LocalFindingComment::query()->create([
        'local_finding_id' => $stale->id,
        'body' => 'Looks like a real issue.',
        'created_at' => now(),
    ]);

    $result = $this->artisan('local-findings:repair-file-paths', ['--dry-run' => true]);
    $result->assertSuccessful();
    $result->expectsOutputToContain('repaired=0 merged=1 skipped=0');

    expect(LocalFinding::query()->where('owner_id', $container->id)->count())->toBe(2);

    $stale->refresh();
    $twin->refresh();
    $comment->refresh();

    expect($stale->file_path)->toBe('file:///workspace-scratch/9c1b2d3e/work/Sources/A.cs')
        ->and($comment->local_finding_id)->toBe($stale->id)
        ->and(AuditLog::query()->where('action', 'like', 'local_finding.%')->count())->toBe(0);
});

it('leaves rows that already have a relative file_path untouched', function () {
    $container = SecurityContainer::factory()->create();

    $finding = makeLocalFinding($container, ['file_path' => 'Sources/A.cs']);

    $this->artisan('local-findings:repair-file-paths')->assertSuccessful();

    $finding->refresh();

    expect($finding->file_path)->toBe('Sources/A.cs');
});

it('records an audit entry for a repaired row and for a merged row', function () {
    $container = SecurityContainer::factory()->create();

    $repairedOnly = makeLocalFinding($container, [
        'file_path' => 'file:///workspace-scratch/9c1b2d3e/work/Sources/A.cs',
        'rule_id' => 'CA2100',
    ]);

    $stale = makeLocalFinding($container, [
        'file_path' => 'file:///workspace-scratch/9c1b2d3e/work/Sources/B.cs',
        'rule_id' => 'CA5350',
    ]);
    makeLocalFinding($container, ['file_path' => 'Sources/B.cs', 'rule_id' => 'CA5350']);

    $this->artisan('local-findings:repair-file-paths')->assertSuccessful();

    expect(AuditLog::query()->where('action', 'local_finding.file_path_repaired')->where('subject_id', (string) $repairedOnly->id)->exists())->toBeTrue();

    $mergeEntry = AuditLog::query()->where('action', 'local_finding.merged')->firstOrFail();

    expect($mergeEntry->payload_json['merged_from_id'] ?? null)->toBe($stale->id);
});
