<?php

use App\Assets\LocalFindingIdentityRekeyer;
use App\Audit\AuditLog;
use App\Models\Enums\EventState;
use App\Models\LocalFinding;
use App\Models\LocalFindingComment;
use App\Models\LocalFindingWorkItemLink;
use App\Models\SecurityContainer;
use Illuminate\Support\Facades\DB;

/**
 * A row as stored before the line-independent identity: its dedup_hash is the line-based one.
 *
 * @param  array<string, mixed>  $overrides
 */
function legacyVulnerabilityFinding(SecurityContainer $container, int $line, array $overrides = []): LocalFinding
{
    $finding = $container->localFindings()->create(array_merge([
        'kind' => LocalFinding::KIND_VULNERABILITY,
        'rule_id' => 'CVE-2026-101911',
        'title' => 'ip-address: unbounded parse diagnostic',
        'file_path' => 'package-lock.json',
        'start_line' => $line,
        'package_name' => 'ip-address',
        'package_version' => '10.1.0',
        'first_seen_at' => now(),
        'last_seen_at' => now(),
    ], $overrides));

    DB::table('local_findings')->where('id', $finding->id)
        ->update(['dedup_hash' => LocalFinding::computeDedupHash('CVE-2026-101911', 'package-lock.json', $line)]);

    return $finding->fresh();
}

it('re-keys a vulnerability finding to its line-independent identity', function () {
    $container = SecurityContainer::factory()->create();
    $finding = legacyVulnerabilityFinding($container, 1063);

    $result = app(LocalFindingIdentityRekeyer::class)->rekey();

    expect($result)->toBe(['rekeyed' => 1, 'merged' => 0])
        ->and($finding->fresh()->dedup_hash)->toBe($finding->currentIdentityHash());
});

it('merges rows that now share an identity into the triaged one, keeping comments and links', function () {
    $container = SecurityContainer::factory()->create();
    $untriaged = legacyVulnerabilityFinding($container, 1063, ['first_seen_at' => now()->subDays(3)]);
    $triaged = legacyVulnerabilityFinding($container, 2210, ['status' => EventState::Dismissed]);

    LocalFindingComment::query()->create(['local_finding_id' => $untriaged->id, 'body' => 'Seen in the lockfile root.', 'created_at' => now()]);
    $link = ['tracker_id' => 'jira', 'work_item_id' => 'SEC-951', 'work_item_url' => 'https://jira.example/browse/SEC-951', 'created_at' => now()];
    LocalFindingWorkItemLink::query()->create(['local_finding_id' => $untriaged->id, ...$link]);
    LocalFindingWorkItemLink::query()->create(['local_finding_id' => $triaged->id, ...$link]);
    LocalFindingWorkItemLink::query()->create(['local_finding_id' => $untriaged->id, ...$link, 'work_item_id' => 'SEC-958']);

    $result = app(LocalFindingIdentityRekeyer::class)->rekey();

    $survivor = LocalFinding::query()->where('owner_id', $container->id)->sole();
    expect($result)->toBe(['rekeyed' => 1, 'merged' => 1])
        ->and($survivor->id)->toBe($triaged->id)
        ->and($survivor->status)->toBe(EventState::Dismissed)
        ->and($survivor->first_seen_at->toDateString())->toBe(now()->subDays(3)->toDateString())
        ->and($survivor->comments()->pluck('body')->all())->toBe(['Seen in the lockfile root.'])
        ->and($survivor->workItemLinks()->pluck('work_item_id')->sort()->values()->all())->toBe(['SEC-951', 'SEC-958'])
        ->and(AuditLog::query()->where('action', 'local_finding.merged')->where('subject_id', (string) $triaged->id)->exists())->toBeTrue();
});

it('leaves line-based identities alone and changes nothing on a second run', function () {
    $container = SecurityContainer::factory()->create();
    $secret = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'github-pat',
        'title' => 'GitHub Personal Access Token',
        'file_path' => 'config.php',
        'start_line' => 12,
    ]);
    legacyVulnerabilityFinding($container, 1063);
    legacyVulnerabilityFinding($container, 2210);

    $rekeyer = app(LocalFindingIdentityRekeyer::class);
    $first = $rekeyer->rekey();

    expect($first)->toBe(['rekeyed' => 1, 'merged' => 1])
        ->and($secret->fresh()->dedup_hash)->toBe(LocalFinding::computeDedupHash('github-pat', 'config.php', 12))
        ->and($rekeyer->rekey())->toBe(['rekeyed' => 0, 'merged' => 0]);
});
