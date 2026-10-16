<?php

namespace App\Mcp\Tools\Sync;

use App\Mcp\Support\FindingSelection;
use App\Mcp\Tools\AppSecTool;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Sync\PendingStatePusher;
use App\Triage\OperatorIntegrationRuntime;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('push-pending-changes')]
#[Title('Push pending alert changes to their sources')]
#[Description('Pushes the pending state, severity and comment changes of the given alerts (ids from list-pending-changes) to their sources now, with your own source credentials, one source at a time; each source reports its own outcome and a refused source does not stop the others. Changes a source cannot accept are kept locally with an explanatory comment; a change the source already holds (made there directly) is marked synced without pushing (already_upstream). Changes you no longer want pushed can be dropped with discard-pending-changes. Defaults to a dry run that lists what would be pushed and whether your credentials are in place; call again with dry_run=false to push. Requires work-items.sync and sources.push-state.')]
final class PushPendingChangesTool extends AppSecTool
{
    public function __construct(
        private readonly PendingStatePusher $pusher,
        private readonly OperatorIntegrationRuntime $runtime,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'alert_ids' => FindingSelection::idsSchema($schema, 'alert'),
            'dry_run' => FindingSelection::dryRunSchema($schema),
        ];
    }

    protected function requiredPermissions(): array
    {
        return ['work-items.sync', 'sources.push-state'];
    }

    protected function run(Request $request, User $actor): ResponseFactory
    {
        $alerts = FindingSelection::resolve($request, 'alert_ids', SecurityEvent::class, 'alert');
        $clean = $alerts->reject(fn (SecurityEvent $event): bool => $event->is_dirty)->modelKeys();

        if ($clean !== []) {
            throw ValidationException::withMessages([
                'alert_ids' => 'Alert(s) ' . implode(', ', $clean) . ' have no pending change. Nothing was pushed.',
            ]);
        }

        /** @var Collection<string, Collection<int, SecurityEvent>> $bySource */
        $bySource = $alerts->toBase()->groupBy(fn (SecurityEvent $event): string => (string) $event->source_id);

        if (FindingSelection::isDryRun($request)) {
            $alerts->load(['softwareSystem:id,name', 'container:id,name']);

            return Response::structured([
                'dry_run' => true,
                'note' => 'Nothing pushed. Applying pushes each source now with your own credentials.',
                'sources' => $bySource->map(fn (Collection $events, string $sourceId): array => $this->preview($sourceId, $events, $actor))->values()->all(),
            ]);
        }

        $pushed = [];
        $refused = [];

        foreach ($bySource as $sourceId => $events) {
            try {
                $outcome = $this->pusher->push($events, $actor);
            } catch (InvalidArgumentException $exception) {
                $refused[] = ['source_id' => $sourceId, 'alert_ids' => $events->map(fn (SecurityEvent $event): int => $event->id)->values()->all(), 'reason' => $exception->getMessage()];

                continue;
            }

            $pushed[] = [
                'source_id' => $outcome->sourceId,
                'succeeded' => $outcome->succeeded,
                'failed' => $outcome->failed,
                'skipped' => $outcome->skipped,
                'resolved_local_only' => $outcome->resolvedLocalOnly,
                'already_upstream' => $outcome->alreadyUpstream,
                'last_error' => $outcome->lastError,
            ];
        }

        return Response::structured(['dry_run' => false, 'pushed' => $pushed, 'refused' => $refused]);
    }

    /**
     * @param  Collection<int, SecurityEvent>  $events
     * @return array<string, mixed>
     */
    private function preview(string $sourceId, Collection $events, User $actor): array
    {
        $enabled = $this->runtime->source($sourceId) !== null;

        return [
            'source_id' => $sourceId,
            'source_enabled' => $enabled,
            'missing_credentials' => $enabled ? $this->runtime->missingSourceCredentialLabels($sourceId, $actor->id) : [],
            'alerts' => $events->map(fn (SecurityEvent $event): array => ListPendingChangesTool::row($event))->values()->all(),
        ];
    }
}
