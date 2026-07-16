<?php

use App\Audit\AuditLog;
use App\Filament\Resources\AuditLogResource;
use App\Filament\Resources\AuditLogResource\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogResource\Pages\ViewAuditLog;
use App\Models\SecurityEvent;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function auditAdmin(): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Admin']);

    return $user;
}

it('audit rows have a record URL pointing to view page', function () {
    $admin = auditAdmin();

    AuditLog::query()->create([
        'actor_kind' => 'user',
        'action' => 'test.action',
        'subject_type' => null,
        'subject_id' => null,
        'payload_json' => ['key' => 'value'],
        'user_id' => $admin->id,
        'ip' => '127.0.0.1',
    ]);

    $record = AuditLog::query()->first();

    $url = AuditLogResource::getUrl('view', ['record' => $record]);

    expect($url)->toContain('audit-logs');
});

it('view page renders audit log detail', function () {
    $admin = auditAdmin();

    AuditLog::query()->create([
        'actor_kind' => 'user',
        'action' => 'test.view',
        'subject_type' => null,
        'subject_id' => null,
        'payload_json' => ['foo' => 'bar'],
        'user_id' => $admin->id,
        'ip' => '10.0.0.1',
    ]);

    $record = AuditLog::query()->first();

    Livewire::actingAs($admin)
        ->test(ViewAuditLog::class, ['record' => $record->getRouteKey()])
        ->assertSee('test.view')
        ->assertSee('10.0.0.1');
});

it('view page shows user link when user exists', function () {
    $admin = auditAdmin();

    AuditLog::query()->create([
        'actor_kind' => 'user',
        'action' => 'test.user_link',
        'subject_type' => null,
        'subject_id' => null,
        'payload_json' => null,
        'user_id' => $admin->id,
        'ip' => null,
    ]);

    $record = AuditLog::query()->first();
    $page = new ViewAuditLog;
    $page->record = $record;

    expect($page->getUserUrl())->toContain((string) $admin->id);
});

it('view page returns null user link when user is missing', function () {
    $ghost = User::factory()->create();
    $ghostId = $ghost->id;

    AuditLog::query()->create([
        'actor_kind' => 'system',
        'action' => 'test.no_user',
        'subject_type' => null,
        'subject_id' => null,
        'payload_json' => null,
        'user_id' => $ghostId,
        'ip' => null,
    ]);

    $ghost->forceDelete();

    $record = AuditLog::query()->where('action', 'test.no_user')->firstOrFail();
    $record->user_id = $ghostId; // FK is now SET NULL — restore for the test
    $page = new ViewAuditLog;
    $page->record = $record;

    expect($page->getUserUrl())->toBeNull();
});

it('view page shows subject link when SecurityEvent exists', function () {
    $admin = auditAdmin();

    $event = SecurityEvent::factory()->create();

    AuditLog::query()->create([
        'actor_kind' => 'user',
        'action' => 'test.subject_link',
        'subject_type' => 'App\\Models\\SecurityEvent',
        'subject_id' => (string) $event->id,
        'payload_json' => null,
        'user_id' => $admin->id,
        'ip' => null,
    ]);

    $record = AuditLog::query()->first();
    $page = new ViewAuditLog;
    $page->record = $record;

    expect($page->getSubjectUrl())->toContain((string) $event->id);
});

it('view page returns null subject link when SecurityEvent is missing', function () {
    $admin = auditAdmin();

    AuditLog::query()->create([
        'actor_kind' => 'user',
        'action' => 'test.missing_subject',
        'subject_type' => 'App\\Models\\SecurityEvent',
        'subject_id' => '999999',
        'payload_json' => null,
        'user_id' => $admin->id,
        'ip' => null,
    ]);

    $record = AuditLog::query()->first();
    $page = new ViewAuditLog;
    $page->record = $record;

    expect($page->getSubjectUrl())->toBeNull();
});

it('view page redacts sensitive payload keys', function () {
    AuditLog::query()->create([
        'actor_kind' => 'system',
        'action' => 'test.redact',
        'subject_type' => null,
        'subject_id' => null,
        'payload_json' => ['token' => 'super-secret', 'action' => 'dispatch'],
        'user_id' => null,
        'ip' => null,
    ]);

    $record = AuditLog::query()->first();
    $page = new ViewAuditLog;
    $page->record = $record;

    $rendered = $page->getRedactedPayload();

    expect($rendered)->not->toContain('super-secret')
        ->and($rendered)->toContain('[redacted]')
        ->and($rendered)->toContain('dispatch');
});

it('filters audit log rows by a created_at date range', function () {
    $admin = auditAdmin();

    $early = AuditLog::query()->create(['actor_kind' => 'system', 'action' => 'early.action', 'user_id' => null, 'ip' => null]);
    $early->forceFill(['created_at' => '2026-01-05 00:00:00'])->save();

    $late = AuditLog::query()->create(['actor_kind' => 'system', 'action' => 'late.action', 'user_id' => null, 'ip' => null]);
    $late->forceFill(['created_at' => '2026-01-25 00:00:00'])->save();

    Livewire::actingAs($admin)
        ->test(ListAuditLogs::class)
        ->filterTable('created_at_from', ['created_at_from' => '2026-01-10'])
        ->assertCanSeeTableRecords([$late])
        ->assertCanNotSeeTableRecords([$early])
        ->removeTableFilter('created_at_from')
        ->filterTable('created_at_until', ['created_at_until' => '2026-01-10'])
        ->assertCanSeeTableRecords([$early])
        ->assertCanNotSeeTableRecords([$late]);
});
