<?php

use App\Credentials\Vault;
use App\Mcp\Servers\AppSecScoutServer;
use App\Mcp\Tools\Sync\DiscardPendingChangesTool;
use App\Mcp\Tools\Sync\ListPendingChangesTool;
use App\Mcp\Tools\Sync\PushPendingChangesTool;
use App\Models\Enums\EventState;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Sources\Registry as SourceRegistry;
use Database\Seeders\RolePermissionSeeder;
use Tests\Fakes\FakeSource;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    $this->source = new FakeSource;
    app()->bind('appsec-scout.source.fake', fn () => $this->source);
    app()->tag(['appsec-scout.source.fake'], 'appsec-scout.source');
    app()->forgetInstance(SourceRegistry::class);

    $this->sync = mcpUser('Sync');
    app(Vault::class)->set('fake.apiKey', $this->sync->id, 'operator-key');
});

function pendingAlert(array $attributes = []): SecurityEvent
{
    return SecurityEvent::factory()->create([
        'source_id' => 'fake',
        'state' => EventState::Open,
        'pending_state' => EventState::Resolved,
        'pending_comment' => 'Fixed in the latest release.',
        'is_dirty' => true,
        ...$attributes,
    ]);
}

it('lists pending changes, filtered by scope and source', function () {
    $system = SoftwareSystem::factory()->create();
    $inScope = pendingAlert(['software_system_id' => $system->id, 'metadata' => ['pushRetryCount' => 2, 'lastPushError' => 'upstream error']]);
    pendingAlert();
    pendingAlert(['source_id' => 'other', 'software_system_id' => $system->id]);
    SecurityEvent::factory()->create(['source_id' => 'fake', 'software_system_id' => $system->id, 'is_dirty' => false]);

    $response = AppSecScoutServer::actingAs($this->sync)->tool(ListPendingChangesTool::class, ['system_id' => $system->id, 'source_id' => 'fake'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('items.0.pending_state', 'resolved')
            ->where('items.0.push_retry_count', 2)
            ->where('items.0.last_push_error', 'upstream error')
            ->etc());

    expect(mcpItemIds($response))->toBe([$inScope->id]);
});

it('describes a push on a dry run without pushing anything', function () {
    $alert = pendingAlert();

    AppSecScoutServer::actingAs($this->sync)->tool(PushPendingChangesTool::class, ['alert_ids' => [$alert->id]])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('dry_run', true)
            ->where('sources.0.source_id', 'fake')
            ->where('sources.0.source_enabled', true)
            ->where('sources.0.missing_credentials', [])
            ->where('sources.0.alerts.0.id', $alert->id)
            ->etc());

    expect($this->source->pushCalls)->toBe(0)
        ->and($alert->fresh()?->is_dirty)->toBeTrue();
});

it('pushes each source with the acting user\'s credential and reports refused sources separately', function () {
    $alert = pendingAlert();
    $unknown = pendingAlert(['source_id' => 'gone']);

    AppSecScoutServer::actingAs($this->sync)->tool(PushPendingChangesTool::class, ['alert_ids' => [$alert->id, $unknown->id], 'dry_run' => false])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('dry_run', false)
            ->where('pushed', [[
                'source_id' => 'fake', 'succeeded' => 1, 'failed' => 0, 'skipped' => 0, 'resolved_local_only' => 0, 'already_upstream' => 0, 'last_error' => null,
            ]])
            ->where('refused.0.source_id', 'gone')
            ->where('refused.0.alert_ids', [$unknown->id])
            ->etc());

    expect($this->source->pushCalls)->toBe(1)
        ->and($alert->fresh()?->state)->toBe(EventState::Resolved)
        ->and($alert->fresh()?->is_dirty)->toBeFalse();
});

it('refuses a source the acting user has no credential for, even with a system credential', function () {
    $alert = pendingAlert();
    $other = mcpUser('Sync');
    app(Vault::class)->set('fake.apiKey', null, 'system-key');

    AppSecScoutServer::actingAs($other)->tool(PushPendingChangesTool::class, ['alert_ids' => [$alert->id]])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('sources.0.missing_credentials', ['API Key'])->etc());

    AppSecScoutServer::actingAs($other)->tool(PushPendingChangesTool::class, ['alert_ids' => [$alert->id], 'dry_run' => false])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('pushed', [])
            ->where('refused.0.reason', 'Pushing to fake uses your own credential, and yours is missing: API Key. Add it under Profile → Integrations.')
            ->etc());

    expect($this->source->pushCalls)->toBe(0);
});

it('refuses alerts without a pending change and unknown ids', function () {
    $clean = SecurityEvent::factory()->create(['source_id' => 'fake', 'is_dirty' => false]);
    $alert = pendingAlert();

    AppSecScoutServer::actingAs($this->sync)->tool(PushPendingChangesTool::class, ['alert_ids' => [$alert->id, $clean->id], 'dry_run' => false])
        ->assertHasErrors(["Alert(s) {$clean->id} have no pending change. Nothing was pushed."]);

    AppSecScoutServer::actingAs($this->sync)->tool(PushPendingChangesTool::class, ['alert_ids' => [999999]])
        ->assertHasErrors(['No alert with id(s): 999999. Nothing was changed.']);

    expect($this->source->pushCalls)->toBe(0);
});

it('requires the Pending Sync permissions', function () {
    $alert = pendingAlert();
    $triage = mcpUser('Triage');

    AppSecScoutServer::actingAs($triage)->tool(ListPendingChangesTool::class, [])
        ->assertHasErrors(['Permission denied: this tool requires the work-items.sync permission.']);
    AppSecScoutServer::actingAs($triage)->tool(PushPendingChangesTool::class, ['alert_ids' => [$alert->id]])
        ->assertHasErrors(['Permission denied: this tool requires the work-items.sync permission.']);
});

it('describes a discard on a dry run without discarding anything', function () {
    $alert = pendingAlert();

    AppSecScoutServer::actingAs($this->sync)->tool(DiscardPendingChangesTool::class, ['alert_ids' => [$alert->id]])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('dry_run', true)
            ->where('alerts.0.id', $alert->id)
            ->where('alerts.0.pending_state', 'resolved')
            ->etc());

    expect($alert->fresh()?->is_dirty)->toBeTrue();
});

it('discards pending changes without pushing them', function () {
    $alert = pendingAlert(['metadata' => ['pushRetryCount' => 3, 'lastPushError' => 'upstream error']]);

    AppSecScoutServer::actingAs($this->sync)->tool(DiscardPendingChangesTool::class, ['alert_ids' => [$alert->id], 'dry_run' => false])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json->where('dry_run', false)->where('discarded', 1)->etc());

    $alert->refresh();

    expect($this->source->pushCalls)->toBe(0)
        ->and($alert->is_dirty)->toBeFalse()
        ->and($alert->pending_state)->toBeNull()
        ->and($alert->state)->toBe(EventState::Open)
        ->and($alert->metadata)->not->toHaveKey('pushRetryCount');
});

it('refuses to discard alerts without a pending change, or without alerts.edit', function () {
    $clean = SecurityEvent::factory()->create(['source_id' => 'fake', 'is_dirty' => false]);
    $alert = pendingAlert();

    AppSecScoutServer::actingAs($this->sync)->tool(DiscardPendingChangesTool::class, ['alert_ids' => [$alert->id, $clean->id], 'dry_run' => false])
        ->assertHasErrors(["Alert(s) {$clean->id} have no pending change. Nothing was discarded."]);

    AppSecScoutServer::actingAs(mcpUser('Triage'))->tool(DiscardPendingChangesTool::class, ['alert_ids' => [$alert->id], 'dry_run' => false])
        ->assertHasErrors(['Permission denied: this tool requires the work-items.sync permission.']);

    expect($alert->fresh()?->is_dirty)->toBeTrue();
});
