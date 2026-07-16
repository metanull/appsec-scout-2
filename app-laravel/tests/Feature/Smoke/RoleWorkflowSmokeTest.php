<?php

use App\Audit\AuditLog;
use App\Audit\Recorder;
use App\Filament\Pages\IntegrationSettingsPage;
use App\Filament\Pages\OperationsPage;
use App\Filament\Pages\PendingSyncPage;
use App\Filament\Resources\SecurityContainerResource;
use App\Filament\Resources\SecurityEventResource;
use App\Filament\Resources\SoftwareSystemResource;
use App\Filament\Resources\UserResource;
use App\Integrations\OperatorIntegrationRuntime;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\IntegrationSetting;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\SyncRun;
use App\Models\User;
use App\Models\WorkItemLink;
use App\Sources\Registry as SourceRegistry;
use App\Sync\PendingSyncResolver;
use App\Sync\PushEventStatesJob;
use App\Trackers\Dto\WorkItemDto;
use App\Trackers\Registry;
use App\Trackers\WorkItemService;
use App\Triage\CommentManager;
use App\Triage\SeverityChanger;
use App\Triage\StateChanger;
use App\Users\UserAdminService;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;
use Tests\Fakes\FakeSource;
use Tests\Fakes\FakeTracker;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    bindSmokeIntegrations();
});

it('covers the reader workflow and a denied admin action', function () {
    $reader = smokeUser('Reader');
    $event = smokeEvent();

    $this->actingAs($reader);

    $this->get('/')->assertSuccessful();
    $this->get(SecurityEventResource::getUrl('index'))->assertSuccessful();
    $this->get(SecurityEventResource::getUrl('view', ['record' => $event]))->assertSuccessful();
    $this->get(SecurityContainerResource::getUrl('index'))->assertSuccessful();
    $this->get(SoftwareSystemResource::getUrl('index'))->assertSuccessful();

    expect(UserResource::canViewAny())->toBeFalse();
});

it('covers the triage workflow and a denied planning action', function () {
    $triage = smokeUser('Triage');
    $event = smokeEvent(['title' => 'Triage event']);
    $bulkEvent = smokeEvent(['title' => 'Bulk triage event', 'source_event_id' => 'evt-bulk']);

    app(StateChanger::class)->change($event, $triage, EventState::Resolved, 'Resolved after smoke review');
    app(SeverityChanger::class)->change($event, $triage, EventSeverity::Critical, 'Escalated after smoke review');
    app(CommentManager::class)->add($event, $triage, 'Additional smoke comment for triage workflow');
    app(StateChanger::class)->changeMany([$bulkEvent], $triage, EventState::Dismissed, 'Bulk smoke transition comment');

    $event->refresh();
    $bulkEvent->refresh();

    expect($event->pending_state)->toBe(EventState::Resolved)
        ->and($event->pending_severity)->toBe(EventSeverity::Critical)
        ->and($event->is_dirty)->toBeTrue()
        ->and($bulkEvent->pending_state)->toBe(EventState::Dismissed)
        ->and(AuditLog::query()->where('action', 'state_change')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'severity_change')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'comment_added')->exists())->toBeTrue()
        ->and($triage->can('work-items.create'))->toBeFalse();
});

it('covers the planning workflow and a denied sync action', function () {
    $planner = smokeUser('Plan');
    $tracker = bindSmokeTracker((new FakeTracker)
        ->withExistingWorkItem(new WorkItemDto(
            id: 'APP#99',
            projectKey: 'APP',
            title: 'Existing issue',
            state: 'Open',
            url: 'https://tracker.test/APP%2399',
        )));

    $singleEvent = smokeEvent(['title' => 'Plan single event', 'source_event_id' => 'evt-plan-1']);
    $groupEventA = smokeEvent(['title' => 'Plan grouped A', 'source_event_id' => 'evt-plan-2']);
    $groupEventB = smokeEvent(['title' => 'Plan grouped B', 'source_event_id' => 'evt-plan-3']);

    app(WorkItemService::class)->createForEvents([$singleEvent->id], $planner->id, 'fake-tracker', 'APP', 'Bug');
    app(WorkItemService::class)->createForEvents([$groupEventA->id, $groupEventB->id], $planner->id, 'fake-tracker', 'APP', 'Task');

    $workItemIds = WorkItemLink::query()->pluck('work_item_id')->unique()->values()->all();

    expect(WorkItemLink::query()->count())->toBe(3)
        ->and(count($workItemIds))->toBe(2)
        ->and($tracker->createCalls)->toBe(2)
        ->and(PendingSyncPage::canAccess())->toBeFalse();
});

it('covers the sync workflow and a denied admin action', function () {
    $syncUser = smokeUser('Sync');
    $source = bindSmokeSource(new FakeSource);
    $event = smokeEvent([
        'title' => 'Sync event',
        'source_id' => 'fake',
        'source_event_id' => 'evt-sync-1',
        'state' => EventState::Open,
        'pending_state' => EventState::Resolved,
        'pending_comment' => 'Sync this state to the source',
        'is_dirty' => true,
    ]);

    (new PushEventStatesJob([$event->id], $syncUser->id))->handle(app(OperatorIntegrationRuntime::class), app(Recorder::class), app(PendingSyncResolver::class));

    $event->refresh();

    expect($source->pushCalls)->toBe(1)
        ->and($event->state)->toBe(EventState::Resolved)
        ->and($event->pending_state)->toBeNull()
        ->and($event->is_dirty)->toBeFalse()
        ->and(SyncRun::query()->latest('id')->first()?->status)->toBe('success')
        ->and(AuditLog::query()->where('action', 'sync_push')->exists())->toBeTrue()
        ->and(UserResource::canViewAny())->toBeFalse();
});

it('covers the admin workflow and integration operations actions', function () {
    $this->artisan('appsec:bootstrap-admin', [
        '--name' => 'Smoke Admin',
        '--email' => 'smoke-admin@example.test',
        '--password' => 'secret-pass',
    ])->assertSuccessful();

    $admin = User::query()->where('email', 'smoke-admin@example.test')->firstOrFail();
    $admin->forceFill([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ])->save();

    $created = app(UserAdminService::class)->create([
        'name' => 'Smoke Operator',
        'email' => 'operator@example.test',
        'password' => 'secret-pass',
        'roles' => ['Reader'],
    ], $admin);

    app(UserAdminService::class)->update($created, [
        'name' => 'Smoke Operator',
        'email' => 'operator@example.test',
        'roles' => ['Plan', 'Reader'],
        'is_disabled' => false,
    ], $admin);
    app(UserAdminService::class)->resetTwoFactor($created, $admin);
    app(UserAdminService::class)->disable($created, $admin);
    app(UserAdminService::class)->enable($created, $admin);

    IntegrationSetting::query()->updateOrCreate(
        ['integration_kind' => 'source', 'integration_id' => 'fake'],
        ['enabled' => false, 'fetch_interval_minutes' => 30],
    );

    $record = IntegrationSetting::query()
        ->where('integration_kind', 'source')
        ->where('integration_id', 'fake')
        ->firstOrFail();

    Livewire::actingAs($admin)
        ->test(IntegrationSettingsPage::class)
        ->callTableAction('editSettings', $record, data: [
            'enabled' => true,
            'fetch_interval_minutes' => 5,
        ])
        ->callTableAction('testConnection', $record);

    Livewire::actingAs($admin)
        ->test(OperationsPage::class)
        ->call('dispatchDueIntegrationsNow');

    expect(AuditLog::query()->where('action', 'user.bootstrap_admin')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'user.created')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'user.roles_changed')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'user.two_factor_reset')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'user.disabled')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'user.enabled')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'integration.settings_updated')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'integration.connection_tested')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'operations.dispatch_due_integrations')->exists())->toBeTrue();
});

function bindSmokeIntegrations(): void
{
    config([
        'integration_settings.fake.enabled' => true,
        'integration_settings.fake.interval_minutes' => 1,
        'integration_settings.fake-tracker.enabled' => true,
    ]);

    bindSmokeSource(new FakeSource);
    bindSmokeTracker(new FakeTracker);
}

function bindSmokeSource(FakeSource $source): FakeSource
{
    app()->bind('appsec-scout.source.fake', fn () => $source);
    app()->tag(['appsec-scout.source.fake'], 'appsec-scout.source');
    app()->forgetInstance(SourceRegistry::class);

    return $source;
}

function bindSmokeTracker(FakeTracker $tracker): FakeTracker
{
    app()->bind('appsec-scout.tracker.fake', fn () => $tracker);
    app()->tag(['appsec-scout.tracker.fake'], 'appsec-scout.tracker');
    app()->forgetInstance(Registry::class);

    return $tracker;
}

function smokeUser(string $role): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles([$role]);

    return $user;
}

function smokeEvent(array $overrides = []): SecurityEvent
{
    $system = SoftwareSystem::factory()->create([
        'source_id' => $overrides['source_id'] ?? 'fake',
        'source_system_id' => $overrides['source_system_id'] ?? ('sys-' . str()->random(6)),
    ]);

    $container = SecurityContainer::factory()->create([
        'software_system_id' => $system->id,
    ]);

    return SecurityEvent::factory()->create(array_merge([
        'source_id' => $overrides['source_id'] ?? 'fake',
        'software_system_id' => $system->id,
        'container_id' => $container->id,
        'source_event_id' => $overrides['source_event_id'] ?? ('evt-' . str()->random(6)),
        'title' => $overrides['title'] ?? 'Smoke event',
        'severity' => $overrides['severity'] ?? EventSeverity::High,
        'state' => $overrides['state'] ?? EventState::Open,
        'type' => $overrides['type'] ?? EventType::Vulnerability,
        'is_dirty' => $overrides['is_dirty'] ?? false,
        'pending_state' => $overrides['pending_state'] ?? null,
        'pending_severity' => $overrides['pending_severity'] ?? null,
        'pending_comment' => $overrides['pending_comment'] ?? null,
    ], $overrides));
}
