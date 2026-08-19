<?php

use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use Illuminate\Support\Facades\DB;

function createFindingWithoutDedupHash(SecurityContainer $container, array $overrides = []): LocalFinding
{
    return LocalFinding::query()->create(array_merge([
        'owner_type' => SecurityContainer::class,
        'owner_id' => $container->id,
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'generic-api-key',
        'title' => 'Hardcoded API key',
        'file_path' => 'config/services.php',
        'start_line' => 12,
    ], $overrides));
}

it('backfills dedup_hash for rows missing it', function () {
    $container = SecurityContainer::factory()->create();
    $finding = createFindingWithoutDedupHash($container);

    expect($finding->dedup_hash)->toBeNull();

    $this->artisan('local-findings:backfill-dedup-hash')
        ->expectsOutputToContain('Backfilled dedup_hash on 1 row(s)')
        ->assertSuccessful();

    expect($finding->fresh()->dedup_hash)->toBe(
        LocalFinding::computeDedupHash($finding->rule_id, $finding->file_path, $finding->start_line),
    );
});

it('resolves a duplicate group by keeping the most recently updated row', function () {
    $container = SecurityContainer::factory()->create();

    $older = createFindingWithoutDedupHash($container);
    $newer = createFindingWithoutDedupHash($container);

    // Force a deterministic ordering: $older really is older than $newer.
    DB::table('local_findings')->where('id', $older->id)->update(['updated_at' => now()->subMinutes(5)]);
    DB::table('local_findings')->where('id', $newer->id)->update(['updated_at' => now()]);

    $this->artisan('local-findings:backfill-dedup-hash')
        ->expectsOutputToContain('Found 1 duplicate group(s), deleted 1 row(s)')
        ->assertSuccessful();

    expect(LocalFinding::query()->where('owner_id', $container->id)->count())->toBe(1)
        ->and(LocalFinding::query()->find($newer->id))->not->toBeNull()
        ->and(LocalFinding::query()->find($older->id))->toBeNull();
});

it('is idempotent: a second run backfills and deletes nothing further', function () {
    $container = SecurityContainer::factory()->create();
    createFindingWithoutDedupHash($container);
    createFindingWithoutDedupHash($container);

    $this->artisan('local-findings:backfill-dedup-hash')->assertSuccessful();

    expect(LocalFinding::query()->where('owner_id', $container->id)->count())->toBe(1);

    $this->artisan('local-findings:backfill-dedup-hash')
        ->expectsOutputToContain('Backfilled dedup_hash on 0 row(s). Found 0 duplicate group(s), deleted 0 row(s)')
        ->assertSuccessful();
});
