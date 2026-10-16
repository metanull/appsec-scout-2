<?php

namespace App\Mcp\Tools\Trackers;

use App\Assets\LocalFindingWorkItemDescriptionBuilder;
use App\Assets\LocalFindingWorkItemService;
use App\Mcp\Support\FindingSelection;
use App\Mcp\Support\TrackerAccess;
use App\Mcp\Tools\AppSecTool;
use App\Models\LocalFinding;
use App\Models\User;
use App\Trackers\WorkItemComment;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('create-local-findings-work-item')]
#[Title('Create a work item for local findings')]
#[Description('Creates ONE tracker work item (Jira issue / GitHub issue) for a selection of related local findings and links every finding to it; several findings produce one grouped ticket with a severity table and per-kind occurrences. Alerts and local findings never share a ticket: use create-alerts-work-item for alerts. The title and description are generated from the findings; put your own write-up in comment, which is posted on the new work item as a comment (if posting it fails, the work item stays created and comment_error says why). Uses the acting user\'s personal tracker credentials. Defaults to a dry run showing the title, description and comment that would be sent; call again with dry_run=false to create it. Requires work-items.create.')]
final class CreateLocalFindingsWorkItemTool extends AppSecTool
{
    public const MAX_FINDINGS = 50;

    public function __construct(
        private readonly TrackerAccess $trackers,
        private readonly LocalFindingWorkItemService $workItems,
        private readonly LocalFindingWorkItemDescriptionBuilder $descriptions,
        private readonly WorkItemComment $comments,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'finding_ids' => $schema->array()->items($schema->integer()->min(1))->min(1)->max(self::MAX_FINDINGS)->required()
                ->description('The related local findings to track in one work item (1-' . self::MAX_FINDINGS . ').'),
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
        $request->validate(['finding_ids' => ['required', 'array', 'max:' . self::MAX_FINDINGS]]);
        $findings = FindingSelection::resolve($request, 'finding_ids', LocalFinding::class, 'local finding')->load(['softwareSystem', 'owner']);

        $this->trackers->ensurePersonalCredentials($fields->tracker, $actor->id);
        $this->comments->validated($fields->comment, $fields->tracker->id());

        if (FindingSelection::isDryRun($request)) {
            $list = array_values($findings->all());
            $grouped = count($list) > 1;

            return Response::structured([
                'dry_run' => true,
                'note' => 'Nothing was created. Applying creates one work item and links every listed finding to it.',
                ...$fields->target(),
                'linked_finding_ids' => $findings->modelKeys(),
                'title' => $grouped ? $this->descriptions->buildGroupedTitle($list) : $this->descriptions->buildTitle($list[0]),
                'description' => $grouped ? $this->descriptions->buildGroupedDescription($list) : $this->descriptions->buildDescription($list[0]),
            ]);
        }

        $created = $this->workItems->createForFindings(
            findingIds: array_values(array_map('intval', $findings->modelKeys())),
            userId: $actor->id,
            trackerId: $fields->tracker->id(),
            projectKey: $fields->projectKey,
            itemType: $fields->itemType,
            labels: $fields->labels,
            priority: $fields->priority,
            assigneeId: $fields->assigneeId,
            parentId: $fields->parentId,
            comment: $fields->comment,
        );

        return Response::structured([
            'dry_run' => false,
            'tracker_id' => $fields->tracker->id(),
            'work_item_id' => $created->workItem->id,
            'url' => $created->workItem->url,
            'title' => $created->workItem->title,
            'comment_posted' => $fields->comment !== null && $created->commentError === null,
            'comment_error' => $created->commentError,
            'linked_finding_ids' => $findings->modelKeys(),
        ]);
    }
}
