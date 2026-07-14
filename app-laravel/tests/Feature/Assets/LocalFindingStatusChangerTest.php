<?php

use App\Assets\LocalFindingStatusChanger;
use App\Audit\AuditLog;
use App\Models\Enums\EventState;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\User;
use Illuminate\Validation\ValidationException;

it('changes a local finding status and records the justification comment', function () {
    $user = User::factory()->create();
    $container = SecurityContainer::factory()->create();
    $finding = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'generic-api-key',
        'title' => 'Hardcoded API key',
        'severity' => 'HIGH',
        'file_path' => 'config/services.php',
        'status' => EventState::Open,
    ]);

    $updated = app(LocalFindingStatusChanger::class)->change(
        $finding,
        $user,
        EventState::Dismissed,
        'Confirmed as a false positive after review.',
    );

    expect($updated->status)->toBe(EventState::Dismissed)
        ->and($updated->comments()->latest('id')->first()?->body)->toBe('[Status change: dismissed] Confirmed as a false positive after review.')
        ->and(AuditLog::query()->where('action', 'state_change')->where('subject_id', (string) $finding->id)->exists())->toBeTrue();
});

it('requires a justification comment of at least ten characters', function () {
    $user = User::factory()->create();
    $container = SecurityContainer::factory()->create();
    $finding = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'generic-api-key',
        'title' => 'Hardcoded API key',
        'file_path' => 'config/services.php',
    ]);

    expect(fn () => app(LocalFindingStatusChanger::class)->change($finding, $user, EventState::Resolved, 'Too short'))
        ->toThrow(ValidationException::class);
});
