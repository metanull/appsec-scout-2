<?php

use App\Audit\AuditLog;
use App\Filament\Resources\AuditLogResource;
use App\Filament\Resources\AuditLogResource\Pages\ListAuditLogs;
use App\Filament\Resources\AuditLogResource\Pages\ViewAuditLog;
use App\Filament\Resources\SecurityContainerResource;
use App\Filament\Resources\SecurityEventResource;
use App\Filament\Resources\UserResource;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
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

it('view page shows a link to the acting user', function () {
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

    $this->actingAs($admin)
        ->get(AuditLogResource::getUrl('view', ['record' => $record]))
        ->assertOk()
        ->assertSee($admin->name)
        ->assertSee(UserResource::getUrl('edit', ['record' => $admin->id]), false);
});

it('view page shows no user link when the actor was not a user', function () {
    AuditLog::query()->create([
        'actor_kind' => 'system',
        'action' => 'test.no_user',
        'subject_type' => null,
        'subject_id' => null,
        'payload_json' => null,
        'user_id' => null,
        'ip' => null,
    ]);

    $admin = auditAdmin();
    $record = AuditLog::query()->where('action', 'test.no_user')->firstOrFail();

    $this->actingAs($admin)
        ->get(AuditLogResource::getUrl('view', ['record' => $record]))
        ->assertOk()
        ->assertDontSee('/admin/users/', false);
});

it('resolves and links a SecurityContainer subject to its name, kind, and system on the detail page', function () {
    $admin = auditAdmin();
    $system = SoftwareSystem::factory()->create(['name' => 'Apollo']);
    $container = SecurityContainer::factory()->forSystem($system)->create(['name' => 'Apollo.Api', 'kind' => 'repository']);

    AuditLog::query()->create([
        'actor_kind' => 'cli',
        'action' => 'attachment_created',
        'subject_type' => SecurityContainer::class,
        'subject_id' => (string) $container->id,
        'payload_json' => ['kind' => 'sbom', 'name' => 'sbom.cdx.json'],
        'user_id' => null,
        'ip' => null,
    ]);

    $record = AuditLog::query()->first();

    $this->actingAs($admin)
        ->get(AuditLogResource::getUrl('view', ['record' => $record]))
        ->assertOk()
        ->assertSee('Apollo.Api')
        ->assertSee('repository')
        ->assertSee('Apollo')
        ->assertSee(SecurityContainerResource::getUrl('view', ['record' => $container]), false);
});

it('promotes scalar payload keys onto the detail page', function () {
    $admin = auditAdmin();

    $log = AuditLog::query()->create([
        'actor_kind' => 'system',
        'action' => 'test.payload',
        'subject_type' => null,
        'subject_id' => null,
        // 'file_path' was previously masked because 'path' contains the 'pat' substring;
        // it must now render intact alongside every other value.
        'payload_json' => ['file_path' => '/etc/app/config', 'token' => 'shown-in-full', 'action' => 'dispatch'],
        'user_id' => null,
        'ip' => null,
    ]);

    $this->actingAs($admin)
        ->get(AuditLogResource::getUrl('view', ['record' => $log]))
        ->assertOk()
        ->assertDontSee('[redacted]')
        ->assertSeeText('/etc/app/config')
        ->assertSeeText('shown-in-full')
        ->assertSeeText('dispatch');
});

it('does not crash and shows no link for a non-model subject_type like "credential"', function () {
    $admin = auditAdmin();

    $log = AuditLog::query()->create([
        'actor_kind' => 'user',
        'action' => 'credential_change',
        'subject_type' => 'credential',
        'subject_id' => 'azdo-repos.pat',
        'payload_json' => ['actor' => 'admin', 'outcome' => 'updated'],
        'user_id' => $admin->id,
        'ip' => null,
    ]);

    $this->actingAs($admin)
        ->get(AuditLogResource::getUrl('view', ['record' => $log]))
        ->assertOk()
        ->assertSee('credential');

    Livewire::actingAs($admin)
        ->test(ListAuditLogs::class)
        ->assertCanSeeTableRecords([$log])
        ->assertOk();
});

it('lists a resolved, linked Subject column without one query per row', function () {
    $admin = auditAdmin();

    $system = SoftwareSystem::factory()->create();
    $containers = SecurityContainer::factory()->forSystem($system)->count(3)->create();
    $subjectless = AuditLog::query()->create([
        'actor_kind' => 'cli', 'action' => 'operations.prune_error_logs', 'user_id' => null, 'ip' => null,
    ]);

    foreach ($containers as $container) {
        AuditLog::query()->create([
            'actor_kind' => 'cli',
            'action' => 'attachment_created',
            'subject_type' => SecurityContainer::class,
            'subject_id' => (string) $container->id,
            'payload_json' => null,
            'user_id' => null,
            'ip' => null,
        ]);
    }

    DB::enableQueryLog();

    Livewire::actingAs($admin)
        ->test(ListAuditLogs::class)
        ->assertSee($containers->first()->name)
        ->assertCanSeeTableRecords([$subjectless]);

    $subjectQueries = collect(DB::getQueryLog())
        ->filter(fn (array $query): bool => str_contains($query['query'], 'security_containers'))
        ->count();

    DB::disableQueryLog();

    expect($subjectQueries)->toBeLessThanOrEqual(1);
});

it('resolves a SecurityEvent subject to its title and view link', function () {
    $admin = auditAdmin();
    $event = SecurityEvent::factory()->create(['title' => 'Hardcoded secret in config']);

    AuditLog::query()->create([
        'actor_kind' => 'user',
        'action' => 'state_change',
        'subject_type' => SecurityEvent::class,
        'subject_id' => (string) $event->id,
        'payload_json' => null,
        'user_id' => $admin->id,
        'ip' => null,
    ]);

    $record = AuditLog::query()->first();

    $this->actingAs($admin)
        ->get(AuditLogResource::getUrl('view', ['record' => $record]))
        ->assertOk()
        ->assertSee('Hardcoded secret in config')
        ->assertSee(SecurityEventResource::getUrl('view', ['record' => $event]), false);
});

it('cleans up audit logs older than the configured retention window, audits, and notifies', function () {
    $admin = auditAdmin();
    config(['audit.retain_days' => 30]);

    $old = AuditLog::query()->create(['actor_kind' => 'system', 'action' => 'old.action', 'user_id' => null, 'ip' => null]);
    $old->forceFill(['created_at' => now()->subDays(45)])->save();

    $recent = AuditLog::query()->create(['actor_kind' => 'system', 'action' => 'recent.action', 'user_id' => null, 'ip' => null]);
    $recent->forceFill(['created_at' => now()->subDays(10)])->save();

    Livewire::actingAs($admin)
        ->test(ListAuditLogs::class)
        ->callAction('cleanUpNow')
        ->assertNotified('1 audit log(s) deleted');

    expect(AuditLog::query()->whereKey($old->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->whereKey($recent->id)->exists())->toBeTrue();

    $audit = AuditLog::query()->where('action', 'operations.prune_audit_logs')->firstOrFail();
    expect($audit->payload_json)->toBe(['deleted' => 1, 'retain_days' => 30]);
});

it('hides the Clean up now action from a user without admin.audit', function () {
    $reader = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $reader->syncRoles(['Reader']);

    $this->actingAs($reader)
        ->get(AuditLogResource::getUrl('index'))
        ->assertForbidden();
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
