<?php

namespace App\Mcp\Tools\Triage;

use App\Mcp\Support\FindingSelection;
use App\Mcp\Support\Format;
use App\Mcp\Tools\AppSecTool;
use App\Models\Enums\EventSeverity;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Triage\SeverityChanger;
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

#[Name('change-alert-severity')]
#[Title('Change alert severity')]
#[Description('Changes the severity of one or more alerts, with a justification comment (at least 10 characters) added to each. The change becomes pending: it is pushed back to the source later from the Pending Sync page, not by this tool. Defaults to a dry run that lists what would change; call again with dry_run=false to apply. All ids must exist or nothing changes. Requires alerts.edit, plus alerts.bulk-edit for more than one alert.')]
#[IsIdempotent]
final class ChangeAlertSeverityTool extends AppSecTool
{
    public function __construct(private readonly SeverityChanger $changer) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'alert_ids' => FindingSelection::idsSchema($schema, 'alert'),
            'severity' => $schema->string()->enum(Format::enumValues(EventSeverity::class))->required()->description('The new severity.'),
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

        $events = FindingSelection::resolve($request, 'alert_ids', SecurityEvent::class, 'alert');

        if ($events->count() > 1 && ! $actor->can('alerts.bulk-edit')) {
            return self::permissionDenied('alerts.bulk-edit');
        }

        $severity = EventSeverity::from($validated['severity']);

        if (FindingSelection::isDryRun($request)) {
            return Response::structured([
                'dry_run' => true,
                'note' => 'Nothing changed. Applying records the comment and makes the new severity pending until it is pushed to the source from the Pending Sync page.',
                'changes' => $events->map(fn (SecurityEvent $event): array => [
                    'id' => $event->id,
                    'title' => $event->title,
                    'source_id' => $event->source_id,
                    'severity' => Format::enum($event->getAttribute('severity')),
                    'pending_severity' => Format::enum($event->getAttribute('pending_severity')),
                    'new_pending_severity' => $severity->value,
                ])->values()->all(),
            ]);
        }

        $updated = DB::transaction(fn () => $events->map(
            fn (SecurityEvent $event): SecurityEvent => $this->changer->change($event, $actor, $severity, $validated['comment']),
        ));

        return Response::structured([
            'dry_run' => false,
            'applied' => $updated->map(fn (SecurityEvent $event): array => [
                'id' => $event->id,
                'severity' => Format::enum($event->getAttribute('severity')),
                'pending_severity' => Format::enum($event->getAttribute('pending_severity')),
                'is_dirty' => (bool) $event->is_dirty,
            ])->values()->all(),
        ]);
    }
}
