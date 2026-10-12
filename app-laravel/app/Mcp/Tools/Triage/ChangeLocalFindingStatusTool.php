<?php

namespace App\Mcp\Tools\Triage;

use App\Assets\LocalFindingStatusChanger;
use App\Mcp\Support\FindingSelection;
use App\Mcp\Support\Format;
use App\Mcp\Tools\AppSecTool;
use App\Models\Enums\EventState;
use App\Models\LocalFinding;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('change-local-finding-status')]
#[Title('Change local finding status')]
#[Description('Changes the status of one or more local findings, with a justification comment (at least 10 characters) added to each. Local findings have no upstream source, so the change applies immediately. Defaults to a dry run that lists what would change; call again with dry_run=false to apply. All ids must exist or nothing changes. Requires alerts.edit, plus alerts.bulk-edit for more than one finding.')]
#[IsIdempotent]
final class ChangeLocalFindingStatusTool extends AppSecTool
{
    public function __construct(private readonly LocalFindingStatusChanger $changer) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'finding_ids' => FindingSelection::idsSchema($schema, 'local finding'),
            'status' => $schema->string()->enum(Format::enumValues(EventState::class))->required()->description('The new status.'),
            'comment' => FindingSelection::commentSchema($schema),
            'dry_run' => FindingSelection::dryRunSchema($schema),
        ];
    }

    protected function requiredPermissions(): array
    {
        return ['alerts.edit'];
    }

    protected function run(Request $request, User $actor): Response|ResponseFactory
    {
        $validated = $request->validate([
            'status' => ['required', Rule::enum(EventState::class)],
            'comment' => FindingSelection::commentRules(),
        ]);

        $findings = FindingSelection::resolve($request, 'finding_ids', LocalFinding::class, 'local finding');

        if ($findings->count() > 1 && ! $actor->can('alerts.bulk-edit')) {
            return self::permissionDenied('alerts.bulk-edit');
        }

        $status = EventState::from($validated['status']);

        if (FindingSelection::isDryRun($request)) {
            return Response::structured([
                'dry_run' => true,
                'note' => 'Nothing changed. Applying records the comment and sets the new status immediately.',
                'changes' => $findings->map(fn (LocalFinding $finding): array => [
                    'id' => $finding->id,
                    'title' => $finding->title,
                    'kind' => $finding->kind,
                    'status' => Format::enum($finding->getAttribute('status')),
                    'new_status' => $status->value,
                ])->values()->all(),
            ]);
        }

        $updated = DB::transaction(fn () => $findings->map(
            fn (LocalFinding $finding): LocalFinding => $this->changer->change($finding, $actor, $status, $validated['comment']),
        ));

        return Response::structured([
            'dry_run' => false,
            'applied' => $updated->map(fn (LocalFinding $finding): array => [
                'id' => $finding->id,
                'status' => Format::enum($finding->getAttribute('status')),
            ])->values()->all(),
        ]);
    }
}
