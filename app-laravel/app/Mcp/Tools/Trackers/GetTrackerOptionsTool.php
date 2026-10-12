<?php

namespace App\Mcp\Tools\Trackers;

use App\Mcp\Support\FindingSelection;
use App\Mcp\Support\TrackerAccess;
use App\Mcp\Tools\AppSecTool;
use App\Models\LocalFinding;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Trackers\Contracts\Tracker;
use App\Trackers\Defaults\TrackerProjectDefaultResolution;
use App\Trackers\Defaults\TrackerProjectDefaultResolver;
use App\Trackers\Dto\ProjectDto;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-tracker-options')]
#[Title('Get tracker options')]
#[Description('Discovers what a work item can be created with on a tracker (Jira, GitHub), using the acting user\'s personal tracker credentials: the tracker\'s capabilities, the projects visible to the user, and with project_key the item types and priorities. With alert_ids or finding_ids it also suggests the project the selection is usually tracked in. Requires work-items.create.')]
#[IsReadOnly]
final class GetTrackerOptionsTool extends AppSecTool
{
    public function __construct(
        private readonly TrackerAccess $trackers,
        private readonly TrackerProjectDefaultResolver $defaults,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'tracker_id' => $this->trackers->trackerIdSchema($schema),
            'project_key' => $schema->string()->max(200)->description('A project key (Jira) or owner/repo (GitHub) to list item types and priorities for.'),
            'alert_ids' => $schema->array()->items($schema->integer()->min(1))->max(FindingSelection::MAX_IDS)->description('Alerts to suggest a project for.'),
            'finding_ids' => $schema->array()->items($schema->integer()->min(1))->max(FindingSelection::MAX_IDS)->description('Local findings to suggest a project for.'),
        ];
    }

    protected function requiredPermissions(): array
    {
        return ['work-items.create'];
    }

    protected function run(Request $request, User $actor): ResponseFactory
    {
        $validated = $request->validate(['project_key' => ['nullable', 'string', 'max:200']]);
        $tracker = $this->trackers->resolve($request);
        $suggestion = $this->suggestion($request, $tracker);

        $this->trackers->ensurePersonalCredentials($tracker, $actor->id);

        $projectKey = $validated['project_key'] ?? null;
        $discovered = $this->trackers->asUser($tracker, $actor->id, fn (Tracker $tracker): array => [
            'projects' => array_map(fn (ProjectDto $project): array => [
                'key' => $project->key,
                'name' => $project->name,
                'url' => $project->url,
            ], [...$tracker->fetchProjects()]),
            'item_types' => $projectKey === null ? null : [...$tracker->fetchItemTypes($projectKey)],
            'priorities' => $projectKey === null || ! $tracker->capabilities()->supportsPriority ? null : [...$tracker->fetchPriorities($projectKey)],
        ]);

        return Response::structured([
            'tracker' => ['id' => $tracker->id(), 'name' => $tracker->displayName()],
            'capabilities' => TrackerAccess::capabilities($tracker),
            ...$discovered,
            'suggested_project' => $suggestion === null || ! $suggestion->hasDefault() ? null : [
                'project_key' => $suggestion->projectKey,
                'project_name' => $suggestion->projectName,
                'reason' => $suggestion->reasonText,
            ],
            'suggestion_warning' => $suggestion?->ambiguityWarning,
        ]);
    }

    private function suggestion(Request $request, Tracker $tracker): ?TrackerProjectDefaultResolution
    {
        $hasAlerts = $request->get('alert_ids') !== null;
        $hasFindings = $request->get('finding_ids') !== null;

        if ($hasAlerts && $hasFindings) {
            throw ValidationException::withMessages(['alert_ids' => 'Pass alert_ids or finding_ids, not both: alerts and local findings never share a work item.']);
        }

        return match (true) {
            $hasAlerts => $this->defaults->resolveForEvents(
                FindingSelection::resolve($request, 'alert_ids', SecurityEvent::class, 'alert')->load(['softwareSystem', 'container']),
                $tracker->id(),
            ),
            $hasFindings => $this->defaults->resolveForFindings(
                FindingSelection::resolve($request, 'finding_ids', LocalFinding::class, 'local finding')->load(['softwareSystem', 'owner']),
                $tracker->id(),
            ),
            default => null,
        };
    }
}
