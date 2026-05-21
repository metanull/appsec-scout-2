<?php

use App\Audit\AuditLog;
use App\Audit\Recorder;
use App\Models\User;

it('recordStateChange writes a state_change row', function () {
    $recorder = new Recorder;
    $recorder->recordStateChange('App\\Models\\Alert', '42', ['field' => 'status', 'from' => 'new', 'to' => 'reviewed']);

    expect(AuditLog::count())->toBe(1);
    $log = AuditLog::first();
    expect($log->action)->toBe('state_change')
        ->and($log->subject_type)->toBe('App\\Models\\Alert')
        ->and($log->subject_id)->toBe('42')
        ->and($log->payload_json)->toEqual(['field' => 'status', 'from' => 'new', 'to' => 'reviewed']);
});

it('recordSyncPush writes a sync_push row', function () {
    $recorder = new Recorder;
    $recorder->recordSyncPush('App\\Models\\Alert', '10', ['direction' => 'outbound']);

    $log = AuditLog::first();
    expect($log->action)->toBe('sync_push');
});

it('recordWorkItemCreated writes a work_item_created row', function () {
    $recorder = new Recorder;
    $recorder->recordWorkItemCreated('App\\Models\\Alert', '7', ['tracker' => 'github']);

    $log = AuditLog::first();
    expect($log->action)->toBe('work_item_created');
});

it('recordWorkItemLinked writes a work_item_linked row', function () {
    $recorder = new Recorder;
    $recorder->recordWorkItemLinked('App\\Models\\Alert', '7', ['tracker' => 'github']);

    $log = AuditLog::first();
    expect($log->action)->toBe('work_item_linked');
});

it('recordWorkItemUnlinked writes a work_item_unlinked row', function () {
    $recorder = new Recorder;
    $recorder->recordWorkItemUnlinked('App\\Models\\Alert', '7', ['tracker' => 'github']);

    $log = AuditLog::first();
    expect($log->action)->toBe('work_item_unlinked');
});

it('recordAdminAction writes a custom action row', function () {
    $recorder = new Recorder;
    $recorder->recordAdminAction('user.role_changed', ['role' => 'Admin']);

    $log = AuditLog::first();
    expect($log->action)->toBe('user.role_changed')
        ->and($log->subject_type)->toBeNull();
});

it('recordCredentialChange masks PAT value', function () {
    $recorder = new Recorder;
    $recorder->recordCredentialChange('azdo.pat', 'system', 'set');

    $log = AuditLog::first();
    expect($log->action)->toBe('credential_change')
        ->and($log->payload_json)->toHaveKey('actor', 'system')
        ->and($log->payload_json)->not()->toHaveKey('value');
});

it('stores user_id when authenticated', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $recorder = new Recorder;
    $recorder->recordAdminAction('test.action');

    $log = AuditLog::first();
    expect($log->user_id)->toBe($user->id);
});
