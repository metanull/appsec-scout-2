<?php

use App\Audit\AuditLog;
use App\Mcp\Servers\AppSecScoutServer;
use App\Mcp\Tools\Sync\ReloadAlertsTool;
use App\Models\SecurityEvent;
use App\Sync\RefetchEventJob;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Queue;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    Queue::fake();
    $this->sync = mcpUser('Sync');
});

it('lists the alerts it would reload on a dry run without queuing anything', function () {
    $alert = SecurityEvent::factory()->create(['is_dirty' => true]);

    AppSecScoutServer::actingAs($this->sync)->tool(ReloadAlertsTool::class, ['alert_ids' => [$alert->id]])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('dry_run', true)
            ->where('alerts.0.id', $alert->id)
            ->where('alerts.0.has_pending_change', true)
            ->has('alerts', 1)
            ->etc());

    Queue::assertNothingPushed();
    expect(AuditLog::query()->where('action', 'operations.dispatch_event_refetch')->exists())->toBeFalse();
});

it('queues one reload per alert and audits each dispatch', function () {
    $first = SecurityEvent::factory()->create();
    $second = SecurityEvent::factory()->create();

    AppSecScoutServer::actingAs($this->sync)->tool(ReloadAlertsTool::class, ['alert_ids' => [$second->id, $first->id], 'dry_run' => false])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('dry_run', false)
            ->where('queued', [$first->id, $second->id])
            ->etc());

    Queue::assertPushed(RefetchEventJob::class, 2);
    Queue::assertPushed(RefetchEventJob::class, fn (RefetchEventJob $job): bool => $job->eventId === $first->id);
    Queue::assertPushed(RefetchEventJob::class, fn (RefetchEventJob $job): bool => $job->eventId === $second->id);

    $audits = AuditLog::query()->where('action', 'operations.dispatch_event_refetch')->get();

    expect($audits)->toHaveCount(2)
        ->and($audits->every(fn (AuditLog $audit): bool => $audit->payload_json['channel'] === 'mcp'))->toBeTrue();
});

it('refuses unknown ids without queuing anything', function () {
    $alert = SecurityEvent::factory()->create();

    AppSecScoutServer::actingAs($this->sync)->tool(ReloadAlertsTool::class, ['alert_ids' => [$alert->id, 999999], 'dry_run' => false])
        ->assertHasErrors(['No alert with id(s): 999999. Nothing was changed.']);

    Queue::assertNothingPushed();
});

it('refuses more than the per-call cap of alerts', function () {
    $ids = range(1, ReloadAlertsTool::MAX_IDS + 1);

    AppSecScoutServer::actingAs($this->sync)->tool(ReloadAlertsTool::class, ['alert_ids' => $ids, 'dry_run' => false])
        ->assertHasErrors(['The alert ids field must not have more than ' . ReloadAlertsTool::MAX_IDS . ' items.']);

    Queue::assertNothingPushed();
});

it('requires work-items.sync', function (string $role) {
    $alert = SecurityEvent::factory()->create();

    AppSecScoutServer::actingAs(mcpUser($role))->tool(ReloadAlertsTool::class, ['alert_ids' => [$alert->id], 'dry_run' => false])
        ->assertHasErrors(['Permission denied: this tool requires the work-items.sync permission.']);

    Queue::assertNothingPushed();
})->with(['Reader', 'Triage']);
