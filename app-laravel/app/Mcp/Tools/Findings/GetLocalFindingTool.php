<?php

namespace App\Mcp\Tools\Findings;

use App\Mcp\Support\FindingPresenter;
use App\Mcp\Support\Format;
use App\Mcp\Tools\AppSecTool;
use App\Models\LocalFinding;
use App\Models\LocalFindingComment;
use App\Models\LocalFindingWorkItemLink;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-local-finding')]
#[Title('Get local finding')]
#[Description('Returns one local finding in full: description, reported and overridden severity, code location, metadata (scanner message, rule help, tags), the scan report it came from, the source alert it is correlated to, the most recent comments and linked work items. Requires alerts.view.')]
#[IsReadOnly]
final class GetLocalFindingTool extends AppSecTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'finding_id' => $schema->integer()->min(1)->required()->description('The local finding id (from list-local-findings).'),
        ];
    }

    protected function requiredPermissions(): array
    {
        return ['alerts.view'];
    }

    protected function run(Request $request, User $actor): ResponseFactory
    {
        $validated = $request->validate(
            ['finding_id' => ['required', 'integer', 'exists:local_findings,id']],
            ['finding_id.exists' => 'No local finding with id :input.'],
        );

        $finding = LocalFinding::query()
            ->with([
                'owner',
                'softwareSystem:id,name',
                'attachment:id,kind,name,created_at',
                'workItemLinks',
                'comments' => fn ($comments) => $comments->with('author:id,name')
                    ->latest('created_at')->latest('id')->limit(FindingPresenter::RECENT_COMMENTS),
            ])
            ->withCount('workItemLinks')
            ->findOrFail((int) $validated['finding_id']);

        $attachment = $finding->attachment;

        return Response::structured([
            ...ListLocalFindingsTool::row($finding),
            'description' => $finding->description,
            'reported_severity' => $finding->severity,
            'overridden_severity' => Format::enum($finding->getAttribute('overridden_severity')),
            'end_line' => $finding->end_line,
            'first_seen_at' => Format::date($finding->getAttribute('first_seen_at')),
            'metadata' => Format::arrayAttribute($finding->getAttribute('metadata')),
            'attachment' => $attachment === null ? null : [
                'id' => $attachment->id,
                'kind' => $attachment->kind,
                'name' => $attachment->name,
                'created_at' => Format::date($attachment->getAttribute('created_at')),
            ],
            'correlated_alert_id' => $finding->correlated_security_event_id,
            'comments' => $finding->comments->map(fn (LocalFindingComment $comment): array => FindingPresenter::comment($comment))->values()->all(),
            'work_items' => $finding->workItemLinks->map(fn (LocalFindingWorkItemLink $link): array => FindingPresenter::workItem($link))->values()->all(),
        ]);
    }
}
