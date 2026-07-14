<?php

use App\Assets\LocalFindingSeverityChanger;
use App\Audit\AuditLog;
use App\Models\Enums\EventSeverity;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\User;
use Illuminate\Validation\ValidationException;

it('overrides a local finding severity without touching the scanner-reported value', function () {
    $user = User::factory()->create();
    $container = SecurityContainer::factory()->create();
    $finding = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY,
        'rule_id' => 'CVE-2024-56201',
        'title' => 'Jinja sandbox breakout',
        'severity' => 'MEDIUM',
        'file_path' => 'requirements.txt',
    ]);

    $updated = app(LocalFindingSeverityChanger::class)->change(
        $finding,
        $user,
        EventSeverity::Critical,
        'Exploitable in our deployment, escalating severity.',
    );

    expect($updated->overridden_severity)->toBe(EventSeverity::Critical)
        ->and($updated->severity)->toBe('MEDIUM')
        ->and($updated->effectiveSeverityLabel())->toBe('Critical')
        ->and(AuditLog::query()->where('action', 'severity_change')->where('subject_id', (string) $finding->id)->exists())->toBeTrue();
});

it('requires a justification comment of at least ten characters', function () {
    $user = User::factory()->create();
    $container = SecurityContainer::factory()->create();
    $finding = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY,
        'rule_id' => 'CVE-2024-56201',
        'title' => 'Jinja sandbox breakout',
        'file_path' => 'requirements.txt',
    ]);

    expect(fn () => app(LocalFindingSeverityChanger::class)->change($finding, $user, EventSeverity::Low, 'nope'))
        ->toThrow(ValidationException::class);
});
