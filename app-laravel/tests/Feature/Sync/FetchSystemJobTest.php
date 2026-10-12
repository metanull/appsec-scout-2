<?php

use App\Audit\AuditLog;
use App\Filament\Resources\SoftwareSystemResource\Pages\ViewSoftwareSystem;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\ErrorLog;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\SyncRun;
use App\Models\User;
use App\Sources\Dto\ContainerDto;
use App\Sources\Dto\EventDto;
use App\Sources\Dto\SystemDto;
use App\Sync\FetchSystemJob;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Fakes\FakeSource;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function bindFetchSystemSource(FakeSource $source): FakeSource
{
    app()->bind('appsec-scout.source.fake', fn () => $source);
    app()->tag(['appsec-scout.source.fake'], 'appsec-scout.source');

    return $source;
}

function fakeSourceEvent(string $id, string $systemId, ?string $containerId = null): EventDto
{
    return new EventDto(
        sourceEventId: $id,
        sourceSystemId: $systemId,
        sourceContainerId: $containerId,
        title: "Alert {$id}",
        severity: EventSeverity::High,
        state: EventState::Open,
        type: EventType::Vulnerability,
    );
}

it('fetches one system\'s containers and alerts in full without touching the sync run or other systems', function () {
    $source = bindFetchSystemSource((new FakeSource)
        ->withContainers('sys-1', new ContainerDto('repo-1', 'payments-service', 'sys-1', 'repository'))
        ->withEvents(fakeSourceEvent('evt-1', 'sys-1', 'repo-1'), fakeSourceEvent('evt-2', 'sys-2')));

    $system = SoftwareSystem::factory()->create(['source_id' => 'fake', 'source_system_id' => 'sys-1', 'name' => 'Payments API']);
    $other = SoftwareSystem::factory()->create(['source_id' => 'fake', 'source_system_id' => 'sys-2']);
    $otherContainer = SecurityContainer::factory()->forSystem($other)->create();

    app()->call([new FetchSystemJob($system->id), 'handle']);

    $event = SecurityEvent::query()->where('source_event_id', 'evt-1')->sole();
    $container = SecurityContainer::query()->where('source_container_id', 'repo-1')->sole();

    expect($event->software_system_id)->toBe($system->id)
        ->and($event->container_id)->toBe($container->id)
        ->and($container->software_system_id)->toBe($system->id)
        ->and(SecurityEvent::query()->where('source_event_id', 'evt-2')->exists())->toBeFalse()
        ->and($source->fetchEventsCalls)->toHaveCount(1)
        ->and($source->fetchEventsCalls[0]['since'])->toBeNull()
        ->and($source->fetchEventsCalls[0]['system']?->sourceSystemId)->toBe('sys-1')
        ->and(SyncRun::query()->count())->toBe(0)
        ->and($other->fresh()?->removed_at)->toBeNull()
        ->and($otherContainer->fresh()?->removed_at)->toBeNull();
});

it('logs and rethrows when the source fails', function () {
    $source = new class extends FakeSource
    {
        public function fetchEvents(?Carbon\Carbon $since = null, ?SystemDto $system = null): iterable
        {
            throw new RuntimeException('AzDO answered 503');
        }
    };
    bindFetchSystemSource($source);
    $system = SoftwareSystem::factory()->create(['source_id' => 'fake', 'source_system_id' => 'sys-1']);

    expect(fn () => app()->call([new FetchSystemJob($system->id), 'handle']))
        ->toThrow(RuntimeException::class, 'AzDO answered 503');

    $error = ErrorLog::query()->where('channel', 'sync')->sole();

    expect($error->message)->toContain('AzDO answered 503')
        ->and($error->context_json['software_system_id'])->toBe($system->id);
});

it('refuses a system its source no longer reports', function () {
    bindFetchSystemSource(new FakeSource);
    $system = SoftwareSystem::factory()->create(['source_id' => 'fake', 'removed_at' => now()]);

    expect(fn () => app()->call([new FetchSystemJob($system->id), 'handle']))
        ->toThrow(RuntimeException::class, 'is no longer reported by fake');
});

it('queues a single-system fetch from the system page for sync operators', function () {
    Queue::fake();
    $user = User::factory()->create();
    $user->syncRoles(['Sync']);
    $system = SoftwareSystem::factory()->create();

    Livewire::actingAs($user)
        ->test(ViewSoftwareSystem::class, ['record' => $system->getRouteKey()])
        ->assertActionVisible('fetchFromSource')
        ->callAction('fetchFromSource')
        ->assertNotified();

    Queue::assertPushed(FetchSystemJob::class, fn (FetchSystemJob $job): bool => $job->softwareSystemId === $system->id);

    expect(AuditLog::query()->where('action', 'operations.dispatch_system_fetch')->sole()->payload_json['software_system_id'])
        ->toBe($system->id);
});

it('hides the fetch action from readers and on removed systems', function () {
    $reader = User::factory()->create();
    $reader->syncRoles(['Reader']);
    $admin = User::factory()->create();
    $admin->syncRoles(['Admin']);

    Livewire::actingAs($reader)
        ->test(ViewSoftwareSystem::class, ['record' => SoftwareSystem::factory()->create()->getRouteKey()])
        ->assertActionHidden('fetchFromSource');

    Livewire::actingAs($admin)
        ->test(ViewSoftwareSystem::class, ['record' => SoftwareSystem::factory()->create(['removed_at' => now()])->getRouteKey()])
        ->assertActionHidden('fetchFromSource');
});
