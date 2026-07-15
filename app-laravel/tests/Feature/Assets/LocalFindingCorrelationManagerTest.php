<?php

use App\Assets\LocalFindingCorrelationManager;
use App\Audit\AuditLog;
use App\Models\Enums\EventType;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;

it('clears a correlation and records an audit entry with the previous values', function () {
    $container = SecurityContainer::factory()->create();

    $event = SecurityEvent::factory()->forContainer($container)->create([
        'type' => EventType::Secret,
        'file_path' => 'config.php',
        'start_line' => 4,
    ]);

    $finding = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'github-pat',
        'title' => 'GitHub Personal Access Token',
        'file_path' => 'config.php',
        'start_line' => 3,
        'correlated_security_event_id' => $event->id,
        'correlation_method' => 'file_line',
    ]);

    app(LocalFindingCorrelationManager::class)->unlink($finding);

    $fresh = $finding->fresh();

    expect($fresh->correlated_security_event_id)->toBeNull()
        ->and($fresh->correlation_method)->toBeNull();

    $auditEntry = AuditLog::query()
        ->where('action', 'correlation_cleared')
        ->where('subject_id', (string) $finding->id)
        ->first();

    expect($auditEntry)->not->toBeNull()
        ->and($auditEntry->payload_json['previous_security_event_id'])->toBe($event->id)
        ->and($auditEntry->payload_json['previous_correlation_method'])->toBe('file_line');
});

it('is a no-op besides the audit entry when the finding was not correlated', function () {
    $container = SecurityContainer::factory()->create();

    $finding = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'github-pat',
        'title' => 'GitHub Personal Access Token',
        'file_path' => 'config.php',
        'start_line' => 3,
    ]);

    app(LocalFindingCorrelationManager::class)->unlink($finding);

    expect($finding->fresh()->correlated_security_event_id)->toBeNull();
});
