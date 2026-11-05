<?php

use App\Assets\AttachmentService;
use App\Audit\AuditLog;
use App\Models\Enums\EventState;
use App\Models\LocalFinding;
use App\Models\LocalFindingComment;
use App\Models\LocalFindingWorkItemLink;
use App\Models\SecurityContainer;

beforeEach(fn () => withoutDependencyTrackPush());

/** @param array<string, mixed> $overrides */
function prunableFinding(SecurityContainer $container, array $overrides = []): LocalFinding
{
    return LocalFinding::query()->create(array_merge([
        'owner_type' => SecurityContainer::class,
        'owner_id' => $container->id,
        'kind' => LocalFinding::KIND_CODE_QUALITY,
        'report_kind' => 'code-quality-dotnet',
        'rule_id' => 'CA2100',
        'title' => 'Review SQL queries for security vulnerabilities',
        'description' => 'A SQL command built from a string.',
        'file_path' => 'src/App.cs',
        'start_line' => 10,
        'metadata' => ['message' => 'CA2100 finding', 'level' => 'warning'],
        'status' => EventState::Dismissed,
        'first_seen_at' => now()->subDays(200),
        'last_seen_at' => now()->subDays(100),
    ], $overrides));
}

function prunableSarif(string $ruleId): string
{
    return json_encode(['version' => '2.1.0', 'runs' => [[
        'tool' => ['driver' => ['name' => 'Roslynator', 'rules' => [['id' => $ruleId, 'shortDescription' => ['text' => "{$ruleId} description"], 'fullDescription' => ['text' => "{$ruleId} full description"]]]]],
        'results' => [[
            'ruleId' => $ruleId,
            'level' => 'warning',
            'message' => ['text' => "{$ruleId} finding"],
            'locations' => [['physicalLocation' => ['artifactLocation' => ['uri' => 'src/App.cs'], 'region' => ['startLine' => 10]]]],
        ]],
    ]]], JSON_THROW_ON_ERROR);
}

it('deletes the compiler diagnostics of .NET reports, unless linked to a work item', function () {
    $container = SecurityContainer::factory()->create();
    $diagnostic = prunableFinding($container, ['rule_id' => 'CS0246', 'status' => EventState::Open, 'last_seen_at' => now()]);
    $linked = prunableFinding($container, ['rule_id' => 'CS0518', 'start_line' => 11]);
    $analyzer = prunableFinding($container, ['rule_id' => 'RCS1001', 'last_seen_at' => now()]);
    $otherTool = prunableFinding($container, ['rule_id' => 'CS0246', 'report_kind' => 'code-quality-opengrep', 'start_line' => 12, 'last_seen_at' => now()]);
    LocalFindingComment::query()->create(['local_finding_id' => $diagnostic->id, 'body' => 'Build failed.', 'created_at' => now()]);
    LocalFindingWorkItemLink::query()->create(['local_finding_id' => $linked->id, 'tracker_id' => 'jira', 'work_item_id' => 'SEC-1', 'work_item_url' => 'https://jira.example/browse/SEC-1', 'created_at' => now()]);

    $this->artisan('local-findings:prune')
        ->expectsOutputToContain('compiler_diagnostics_deleted=1')
        ->assertSuccessful();

    expect(LocalFinding::query()->find($diagnostic->id))->toBeNull()
        ->and(LocalFindingComment::query()->where('local_finding_id', $diagnostic->id)->exists())->toBeFalse()
        ->and(LocalFinding::query()->whereKey([$linked->id, $analyzer->id, $otherTool->id])->count())->toBe(3)
        ->and(AuditLog::query()->where('action', 'local_findings_pruned')->sole()->payload_json['compiler_diagnostics_deleted'])->toBe(1);
});

it('compacts closed findings not reported for the given days, keeping their status and latest comment', function () {
    $container = SecurityContainer::factory()->create();
    $old = prunableFinding($container);
    LocalFinding::query()->whereKey($old->id)->toBase()->update(['updated_at' => now()->subDays(50)]);
    $resolved = prunableFinding($container, ['rule_id' => 'CA5350', 'status' => EventState::Resolved]);
    $recent = prunableFinding($container, ['rule_id' => 'CA1001', 'last_seen_at' => now()->subDays(10)]);
    $open = prunableFinding($container, ['rule_id' => 'CA1002', 'status' => EventState::Open]);
    LocalFindingComment::query()->create(['local_finding_id' => $old->id, 'body' => 'Closed by a scan.', 'created_at' => now()->subDays(120)]);
    $decision = LocalFindingComment::query()->create(['local_finding_id' => $old->id, 'body' => 'False positive: the query is parameterised.', 'created_at' => now()->subDays(90)]);

    $this->artisan('local-findings:prune', ['--days' => 30])
        ->expectsOutputToContain('findings_compacted=2 comments_deleted=1')
        ->assertSuccessful();

    $old->refresh();
    expect($old->status)->toBe(EventState::Dismissed)
        ->and($old->metadata)->toBeNull()
        ->and($old->description)->toBeNull()
        ->and($old->title)->toBe('Review SQL queries for security vulnerabilities')
        ->and($old->updated_at->toDateString())->toBe(now()->subDays(50)->toDateString())
        ->and($old->comments()->pluck('id')->all())->toBe([$decision->id])
        ->and($resolved->fresh()->metadata)->toBeNull()
        ->and($recent->fresh()->metadata)->not->toBeNull()
        ->and($open->fresh()->metadata)->not->toBeNull();
});

it('keeps the decision on a compacted finding a later scan reports again, and restores its details', function () {
    $container = SecurityContainer::factory()->create();
    $service = app(AttachmentService::class);
    $service->attachTo($container, 'code-quality-dotnet', 'application/json', 'roslynator.sarif', prunableSarif('CA2100'));
    $finding = LocalFinding::query()->where('owner_id', $container->id)->sole();
    $finding->update(['status' => EventState::Dismissed, 'last_seen_at' => now()->subDays(100)]);

    $this->artisan('local-findings:prune')->expectsOutputToContain('findings_compacted=1')->assertSuccessful();
    expect($finding->fresh()->metadata)->toBeNull();

    $service->attachTo($container, 'code-quality-dotnet', 'application/json', 'roslynator.sarif', prunableSarif('CA2100'));

    $reported = LocalFinding::query()->where('owner_id', $container->id)->sole();
    expect($reported->id)->toBe($finding->id)
        ->and($reported->status)->toBe(EventState::Dismissed)
        ->and($reported->description)->toBe('CA2100 full description')
        ->and($reported->messageText())->toBe('CA2100 finding');
});

it('changes nothing on a dry run', function () {
    $container = SecurityContainer::factory()->create();
    $diagnostic = prunableFinding($container, ['rule_id' => 'CS0246', 'last_seen_at' => now()]);
    $closed = prunableFinding($container, ['rule_id' => 'CA5350']);

    $this->artisan('local-findings:prune', ['--dry-run' => true])
        ->expectsOutputToContain('compiler_diagnostics_deleted=1 findings_compacted=1 comments_deleted=0')
        ->assertSuccessful();

    expect($diagnostic->fresh())->not->toBeNull()
        ->and($closed->fresh()->metadata)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'local_findings_pruned')->exists())->toBeFalse();
});
