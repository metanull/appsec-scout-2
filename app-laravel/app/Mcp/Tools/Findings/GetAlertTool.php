<?php

namespace App\Mcp\Tools\Findings;

use App\Mcp\Support\FindingPresenter;
use App\Mcp\Support\Format;
use App\Mcp\Tools\AppSecTool;
use App\Models\EventComment;
use App\Models\LocalFinding;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\WorkItemLink;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-alert')]
#[Title('Get alert')]
#[Description('Returns one alert in full: description, remediation, source URL, code location, metadata, pending (not yet pushed) triage changes, the most recent comments, linked work items, and the ids of local findings correlated to it. include_raw adds the raw source payload, which can be large. Requires alerts.view.')]
#[IsReadOnly]
final class GetAlertTool extends AppSecTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'alert_id' => $schema->integer()->min(1)->required()->description('The alert id (from list-alerts).'),
            'include_raw' => $schema->boolean()->default(false)->description('Add the raw source payload (source_data).'),
        ];
    }

    protected function requiredPermissions(): array
    {
        return ['alerts.view'];
    }

    protected function run(Request $request, User $actor): ResponseFactory
    {
        $validated = $request->validate([
            'alert_id' => ['required', 'integer', 'exists:security_events,id'],
            'include_raw' => ['nullable', 'boolean'],
        ], ['alert_id.exists' => 'No alert with id :input.']);

        $event = SecurityEvent::query()
            ->with([
                'softwareSystem:id,name',
                'container:id,name',
                'workItemLinks',
                'comments' => fn ($comments) => $comments->with('author:id,name')
                    ->latest('created_at')->latest('id')->limit(FindingPresenter::RECENT_COMMENTS),
            ])
            ->withCount('workItemLinks')
            ->findOrFail((int) $validated['alert_id']);

        $payload = [
            ...ListAlertsTool::row($event),
            'description' => $event->description,
            'remediation' => $event->remediation,
            'url' => $event->url,
            'location' => [
                'file_path' => $event->file_path,
                'start_line' => $event->start_line,
                'end_line' => $event->end_line,
                'snippet' => $event->snippet,
                'branch' => $event->branch,
                'commit_sha' => $event->commit_sha,
                'version_control_url' => $event->version_control_url,
            ],
            'first_seen_at' => Format::date($event->getAttribute('first_seen_at')),
            'metadata' => Format::arrayAttribute($event->getAttribute('metadata')),
            'pending' => [
                'state' => Format::enum($event->getAttribute('pending_state')),
                'severity' => Format::enum($event->getAttribute('pending_severity')),
                'comment' => $event->pending_comment,
                'is_dirty' => (bool) $event->is_dirty,
            ],
            'comments' => $event->comments->map(fn (EventComment $comment): array => FindingPresenter::comment($comment))->values()->all(),
            'work_items' => $event->workItemLinks->map(fn (WorkItemLink $link): array => FindingPresenter::workItem($link))->values()->all(),
            'correlated_local_finding_ids' => LocalFinding::query()
                ->where('correlated_security_event_id', $event->id)
                ->orderBy('id')
                ->pluck('id')
                ->all(),
        ];

        if ($validated['include_raw'] ?? false) {
            $payload['source_data'] = $event->source_data;
        }

        return Response::structured($payload);
    }
}
