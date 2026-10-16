<?php

namespace App\Mcp\Tools\Sync;

use App\Mcp\Support\FindingSelection;
use App\Mcp\Tools\AppSecTool;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Sync\PendingChangeDiscarder;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('discard-pending-changes')]
#[Title('Discard pending alert changes')]
#[Description('Discards the pending (not yet pushed) state, severity and comment changes of the given alerts (ids from list-pending-changes) without pushing them, e.g. when the source keeps refusing the push. Each alert keeps the state last read from its source, and a note on the alert records what was discarded. Defaults to a dry run that lists what would be discarded; call again with dry_run=false to discard. All ids must exist and have a pending change, or nothing changes. Requires work-items.sync, sources.push-state and alerts.edit.')]
final class DiscardPendingChangesTool extends AppSecTool
{
    public function __construct(private readonly PendingChangeDiscarder $discarder) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'alert_ids' => FindingSelection::idsSchema($schema, 'alert'),
            'dry_run' => FindingSelection::dryRunSchema($schema),
        ];
    }

    protected function requiredPermissions(): array
    {
        return ['work-items.sync', 'sources.push-state', 'alerts.edit'];
    }

    protected function run(Request $request, User $actor): ResponseFactory
    {
        $alerts = FindingSelection::resolve($request, 'alert_ids', SecurityEvent::class, 'alert');
        $clean = $alerts->reject(fn (SecurityEvent $event): bool => $event->is_dirty)->modelKeys();

        if ($clean !== []) {
            throw ValidationException::withMessages([
                'alert_ids' => 'Alert(s) ' . implode(', ', $clean) . ' have no pending change. Nothing was discarded.',
            ]);
        }

        if (FindingSelection::isDryRun($request)) {
            $alerts->load(['softwareSystem:id,name', 'container:id,name']);

            return Response::structured([
                'dry_run' => true,
                'note' => 'Nothing discarded. Applying drops these pending changes without pushing them; it cannot be undone.',
                'alerts' => $alerts->map(fn (SecurityEvent $event): array => ListPendingChangesTool::row($event))->values()->all(),
            ]);
        }

        return Response::structured([
            'dry_run' => false,
            'discarded' => $this->discarder->discard($alerts, $actor),
        ]);
    }
}
