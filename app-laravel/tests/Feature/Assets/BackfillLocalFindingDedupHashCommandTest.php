<?php

use App\Models\LocalFinding;
use App\Models\SecurityContainer;

/**
 * The backfill/duplicate-resolution logic this command exists for (#405) targets legacy rows
 * with a NULL dedup_hash or pre-existing duplicate (owner_type, owner_id, kind, dedup_hash)
 * groups — a precondition that predates #406's NOT NULL + UNIQUE constraint. Since this test
 * suite always applies every migration, including #406, before any test runs, that precondition
 * can no longer be constructed here: the schema itself now rejects a NULL dedup_hash or a
 * colliding insert. What remains testable in-suite is the command's steady-state behavior on an
 * already-consistent table, which is exactly what a real deployment sees if the command is
 * re-run after #406 has already landed.
 */
it('is a safe no-op, and idempotent, once every row already has a correct, unique dedup_hash', function () {
    $container = SecurityContainer::factory()->create();

    LocalFinding::query()->create([
        'owner_type' => SecurityContainer::class,
        'owner_id' => $container->id,
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'generic-api-key-1',
        'title' => 'Hardcoded API key',
        'file_path' => 'config/services.php',
        'start_line' => 12,
    ]);
    LocalFinding::query()->create([
        'owner_type' => SecurityContainer::class,
        'owner_id' => $container->id,
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'generic-api-key-2',
        'title' => 'Hardcoded API key',
        'file_path' => 'config/services.php',
        'start_line' => 34,
    ]);

    $this->artisan('local-findings:backfill-dedup-hash')
        ->expectsOutputToContain('Backfilled dedup_hash on 0 row(s). Found 0 duplicate group(s), deleted 0 row(s)')
        ->assertSuccessful();

    $this->artisan('local-findings:backfill-dedup-hash')
        ->expectsOutputToContain('Backfilled dedup_hash on 0 row(s). Found 0 duplicate group(s), deleted 0 row(s)')
        ->assertSuccessful();

    expect(LocalFinding::query()->where('owner_id', $container->id)->count())->toBe(2);
});
