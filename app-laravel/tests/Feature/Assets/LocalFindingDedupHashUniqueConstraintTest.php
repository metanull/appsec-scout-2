<?php

use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use Illuminate\Database\QueryException;

it('rejects two local_findings rows with the same (owner_type, owner_id, kind, dedup_hash) at the database level', function () {
    $container = SecurityContainer::factory()->create();

    $attributes = [
        'owner_type' => SecurityContainer::class,
        'owner_id' => $container->id,
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'generic-api-key',
        'title' => 'Hardcoded API key',
        'file_path' => 'config/services.php',
        'start_line' => 12,
        'dedup_hash' => LocalFinding::computeDedupHash('generic-api-key', 'config/services.php', 12),
    ];

    LocalFinding::query()->create($attributes);

    expect(fn () => LocalFinding::query()->create($attributes))->toThrow(QueryException::class);
});
