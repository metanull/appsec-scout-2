<?php

namespace App\Mcp\Tools\Triage;

use App\Assets\LocalFindingSeverityChanger;
use App\Mcp\Support\FindingSelection;
use App\Mcp\Support\Format;
use App\Mcp\Tools\AppSecTool;
use App\Models\Enums\EventSeverity;
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

#[Name('change-local-finding-severity')]
#[Title('Change local finding severity')]
#[Description('Overrides the severity of one or more local findings (the scanner-reported severity is kept), with a justification comment (at least 10 characters) added to each. Applies immediately; re-scans never reset an override. Defaults to a dry run that lists what would change; call again with dry_run=false to apply. All ids must exist or nothing changes. Requires alerts.edit, plus alerts.bulk-edit for more than one finding.')]
#[IsIdempotent]
final class ChangeLocalFindingSeverityTool extends AppSecTool
{
    public function __construct(private readonly LocalFindingSeverityChanger $changer) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'finding_ids' => FindingSelection::idsSchema($schema, 'local finding'),
            'severity' => $schema->string()->enum(Format::enumValues(EventSeverity::class))->required()->description('The overriding severity.'),
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
            'severity' => ['required', Rule::enum(EventSeverity::class)],
            'comment' => FindingSelection::commentRules(),
        ]);

        $findings = FindingSelection::resolve($request, 'finding_ids', LocalFinding::class, 'local finding');

        if ($findings->count() > 1 && ! $actor->can('alerts.bulk-edit')) {
            return self::permissionDenied('alerts.bulk-edit');
        }

        $severity = EventSeverity::from($validated['severity']);

        if (FindingSelection::isDryRun($request)) {
            return Response::structured([
                'dry_run' => true,
                'note' => 'Nothing changed. Applying records the comment and sets the override immediately.',
                'changes' => $findings->map(fn (LocalFinding $finding): array => [
                    'id' => $finding->id,
                    'title' => $finding->title,
                    'reported_severity' => $finding->severity,
                    'overridden_severity' => Format::enum($finding->getAttribute('overridden_severity')),
                    'new_overridden_severity' => $severity->value,
                ])->values()->all(),
            ]);
        }

        $updated = DB::transaction(fn () => $findings->map(
            fn (LocalFinding $finding): LocalFinding => $this->changer->change($finding, $actor, $severity, $validated['comment']),
        ));

        return Response::structured([
            'dry_run' => false,
            'applied' => $updated->map(fn (LocalFinding $finding): array => [
                'id' => $finding->id,
                'reported_severity' => $finding->severity,
                'overridden_severity' => Format::enum($finding->getAttribute('overridden_severity')),
            ])->values()->all(),
        ]);
    }
}
