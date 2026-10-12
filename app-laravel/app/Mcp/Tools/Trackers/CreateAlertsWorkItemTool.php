<?php

namespace App\Mcp\Tools\Trackers;

use App\Mcp\Support\FindingSelection;
use App\Mcp\Support\TrackerAccess;
use App\Mcp\Tools\AppSecTool;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Trackers\DescriptionBuilder;
use App\Trackers\WorkItemService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('create-alerts-work-item')]
#[Title('Create a work item for alerts')]
#[Description('Creates ONE tracker work item (Jira issue / GitHub issue) for a selection of related alerts and links every alert to it; several alerts produce one grouped ticket. Alerts and local findings never share a ticket: use create-local-findings-work-item for those. Uses the acting user\'s personal tracker credentials. Defaults to a dry run showing the title and description that would be sent; call again with dry_run=false to create it. Use get-tracker-options to discover projects, item types and the suggested project. Requires work-items.create.')]
final class CreateAlertsWorkItemTool extends AppSecTool
{
    public const MAX_ALERTS = 50;

    public function __construct(
        private readonly TrackerAccess $trackers,
        private readonly WorkItemService $workItems,
        private readonly DescriptionBuilder $descriptions,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'alert_ids' => $schema->array()->items($schema->integer()->min(1))->min(1)->max(self::MAX_ALERTS)->required()
                ->description('The related alerts to track in one work item (1-' . self::MAX_ALERTS . ').'),
            ...WorkItemFields::schema($schema, $this->trackers),
        ];
    }

    protected function requiredPermissions(): array
    {
        return ['work-items.create'];
    }

    protected function run(Request $request, User $actor): ResponseFactory
    {
        $fields = WorkItemFields::fromRequest($request, $this->trackers);
        $request->validate(['alert_ids' => ['required', 'array', 'max:' . self::MAX_ALERTS]]);
        $events = FindingSelection::resolve($request, 'alert_ids', SecurityEvent::class, 'alert')->load(['softwareSystem', 'container']);

        $this->trackers->ensurePersonalCredentials($fields->tracker, $actor->id);

        if (FindingSelection::isDryRun($request)) {
            $list = array_values($events->all());
            $grouped = count($list) > 1;

            return Response::structured([
                'dry_run' => true,
                'note' => 'Nothing was created. Applying creates one work item and links every listed alert to it.',
                ...$fields->target(),
                'linked_alert_ids' => $events->modelKeys(),
                'title' => $grouped ? $this->descriptions->buildGroupedTitle($list) : $this->descriptions->buildTitle($list[0]),
                'description' => $grouped ? $this->descriptions->buildGrouped($list) : $this->descriptions->buildSingle($list[0]),
            ]);
        }

        $created = $this->workItems->createForEvents(
            eventIds: array_values(array_map('intval', $events->modelKeys())),
            userId: $actor->id,
            trackerId: $fields->tracker->id(),
            projectKey: $fields->projectKey,
            itemType: $fields->itemType,
            labels: $fields->labels,
            priority: $fields->priority,
            assigneeId: $fields->assigneeId,
            parentId: $fields->parentId,
        );

        return Response::structured([
            'dry_run' => false,
            'tracker_id' => $fields->tracker->id(),
            'work_item_id' => $created->id,
            'url' => $created->url,
            'title' => $created->title,
            'linked_alert_ids' => $events->modelKeys(),
        ]);
    }
}
