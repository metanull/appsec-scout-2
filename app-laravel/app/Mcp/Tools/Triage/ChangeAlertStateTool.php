<?php

namespace App\Mcp\Tools\Triage;

use App\Mcp\Support\FindingSelection;
use App\Mcp\Support\Format;
use App\Mcp\Tools\AppSecTool;
use App\Models\Enums\EventState;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Triage\StateChanger;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Name('change-alert-state')]
#[Title('Change alert state')]
#[Description('Changes the state of one or more alerts, with a justification comment (at least 10 characters) added to each. The change becomes pending: it is pushed back to the source later from the Pending Sync page, not by this tool. Defaults to a dry run that lists what would change; call again with dry_run=false to apply. All ids must exist or nothing changes. Requires alerts.edit, plus alerts.bulk-edit for more than one alert.')]
#[IsIdempotent]
final class ChangeAlertStateTool extends AppSecTool
{
    public function __construct(private readonly StateChanger $changer) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'alert_ids' => FindingSelection::idsSchema($schema, 'alert'),
            'state' => $schema->string()->enum(Format::enumValues(EventState::class))->required()->description('The new state.'),
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
            'state' => ['required', Rule::enum(EventState::class)],
            'comment' => FindingSelection::commentRules(),
        ]);

        $events = FindingSelection::resolve($request, 'alert_ids', SecurityEvent::class, 'alert');

        if ($events->count() > 1 && ! $actor->can('alerts.bulk-edit')) {
            return self::permissionDenied('alerts.bulk-edit');
        }

        $state = EventState::from($validated['state']);

        if (FindingSelection::isDryRun($request)) {
            return Response::structured([
                'dry_run' => true,
                'note' => 'Nothing changed. Applying records the comment and makes the new state pending until it is pushed to the source from the Pending Sync page.',
                'changes' => $events->map(fn (SecurityEvent $event): array => [
                    'id' => $event->id,
                    'title' => $event->title,
                    'source_id' => $event->source_id,
                    'state' => Format::enum($event->getAttribute('state')),
                    'pending_state' => Format::enum($event->getAttribute('pending_state')),
                    'new_pending_state' => $state->value,
                ])->values()->all(),
            ]);
        }

        $updated = $events->count() === 1
            ? collect([$this->changer->change($events->sole(), $actor, $state, $validated['comment'])])
            : $this->changer->changeMany($events, $actor, $state, $validated['comment']);

        return Response::structured([
            'dry_run' => false,
            'applied' => $updated->sortBy('id')->map(fn (SecurityEvent $event): array => [
                'id' => $event->id,
                'state' => Format::enum($event->getAttribute('state')),
                'pending_state' => Format::enum($event->getAttribute('pending_state')),
                'is_dirty' => (bool) $event->is_dirty,
            ])->values()->all(),
        ]);
    }
}
