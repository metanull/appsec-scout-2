<?php

namespace App\Mcp\Tools\Sync;

use App\Audit\Recorder;
use App\Mcp\Support\FindingPresenter;
use App\Mcp\Support\FindingSelection;
use App\Mcp\Support\Format;
use App\Mcp\Tools\AppSecTool;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Sync\RefetchEventJob;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('reload-alerts')]
#[Title('Reload alerts from their source')]
#[Description('Reloads the given alerts (ids from list-alerts) from their source, one background request per alert, like "Reload from source" on the alert page. Much cheaper than fetch-alerts when only a few known alerts need refreshing. Pending (not yet pushed) changes are preserved. Defaults to a dry run that lists the alerts that would be reloaded; call again with dry_run=false to queue them. All ids must exist, or nothing is queued. Requires work-items.sync.')]
final class ReloadAlertsTool extends AppSecTool
{
    public const MAX_IDS = 50;

    public function __construct(private readonly Recorder $recorder) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'alert_ids' => FindingSelection::idsSchema($schema, 'alert', self::MAX_IDS),
            'dry_run' => FindingSelection::dryRunSchema($schema),
        ];
    }

    protected function requiredPermissions(): array
    {
        return ['work-items.sync'];
    }

    protected function run(Request $request, User $actor): ResponseFactory
    {
        $alerts = FindingSelection::resolve($request, 'alert_ids', SecurityEvent::class, 'alert', self::MAX_IDS);

        if (FindingSelection::isDryRun($request)) {
            $alerts->load(['softwareSystem:id,name', 'container:id,name']);

            return Response::structured([
                'dry_run' => true,
                'note' => 'Nothing queued. Applying queues one reload per alert from its source; pending changes are preserved.',
                'alerts' => $alerts->map(fn (SecurityEvent $event): array => self::row($event))->values()->all(),
            ]);
        }

        foreach ($alerts as $event) {
            RefetchEventJob::dispatch($event->id);
            $this->recorder->recordAdminAction('operations.dispatch_event_refetch', [
                'security_event_id' => $event->id,
                'source_id' => $event->source_id,
            ]);
        }

        return Response::structured([
            'dry_run' => false,
            'queued' => $alerts->modelKeys(),
            'note' => 'Queued in the background. Re-read the alerts with get-alert once the reloads have finished; failures are recorded in the Error log.',
        ]);
    }

    /** @return array<string, mixed> */
    private static function row(SecurityEvent $event): array
    {
        return [
            'id' => $event->id,
            'title' => $event->title,
            'source_id' => $event->source_id,
            'system' => FindingPresenter::named($event->softwareSystem),
            'container' => FindingPresenter::named($event->container),
            'state' => Format::enum($event->getAttribute('state')),
            'severity' => Format::enum($event->getAttribute('severity')),
            'has_pending_change' => (bool) $event->is_dirty,
        ];
    }
}
