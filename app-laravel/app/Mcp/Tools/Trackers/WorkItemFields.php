<?php

namespace App\Mcp\Tools\Trackers;

use App\Mcp\Support\FindingSelection;
use App\Mcp\Support\TrackerAccess;
use App\Trackers\Contracts\Tracker;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\JsonSchema\Types\Type;
use Laravel\Mcp\Request;

/** The work-item inputs shared by the create-*-work-item tools, validated against the tracker's capabilities. */
final class WorkItemFields
{
    /** @param  list<string>  $labels */
    private function __construct(
        public readonly Tracker $tracker,
        public readonly string $projectKey,
        public readonly string $itemType,
        public readonly array $labels,
        public readonly ?string $priority,
        public readonly ?string $assigneeId,
        public readonly ?string $parentId,
    ) {}

    /** @return array<string, Type> */
    public static function schema(JsonSchema $schema, TrackerAccess $trackers): array
    {
        return [
            'tracker_id' => $trackers->trackerIdSchema($schema),
            'project_key' => $schema->string()->min(1)->max(200)->required()->description('Jira project key, or owner/repo for GitHub (see get-tracker-options).'),
            'item_type' => $schema->string()->min(1)->max(100)->required()->description('Jira: Bug, Task, Story or Epic. GitHub: issue.'),
            'labels' => $schema->array()->items($schema->string()->min(1)->max(100))->max(20)->description('Labels (trackers that support them).'),
            'priority' => $schema->string()->max(100)->description('Priority name (Jira only).'),
            'assignee_id' => $schema->string()->max(200)->description('Assignee account id (Jira) or login (GitHub).'),
            'parent_id' => $schema->string()->max(200)->description('Parent issue key (Jira only).'),
            'dry_run' => FindingSelection::dryRunSchema($schema),
        ];
    }

    public static function fromRequest(Request $request, TrackerAccess $trackers): self
    {
        $tracker = $trackers->resolve($request);
        $validated = $request->validate([
            'project_key' => ['required', 'string', 'max:200'],
            'item_type' => ['required', 'string', 'max:100'],
            'labels' => ['nullable', 'array', 'max:20'],
            'labels.*' => ['string', 'min:1', 'max:100'],
            'priority' => ['nullable', 'string', 'max:100'],
            'assignee_id' => ['nullable', 'string', 'max:200'],
            'parent_id' => ['nullable', 'string', 'max:200'],
        ]);

        TrackerAccess::ensureSupported($tracker, $validated);

        return new self(
            $tracker,
            $validated['project_key'],
            $validated['item_type'],
            array_values($validated['labels'] ?? []),
            $validated['priority'] ?? null,
            $validated['assignee_id'] ?? null,
            $validated['parent_id'] ?? null,
        );
    }

    /** @return array<string, mixed> where the work item would go, for dry runs */
    public function target(): array
    {
        return [
            'tracker_id' => $this->tracker->id(),
            'project_key' => $this->projectKey,
            'item_type' => $this->itemType,
            'labels' => $this->labels,
            'priority' => $this->priority,
            'assignee_id' => $this->assigneeId,
            'parent_id' => $this->parentId,
        ];
    }
}
