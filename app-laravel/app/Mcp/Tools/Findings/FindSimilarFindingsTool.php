<?php

namespace App\Mcp\Tools\Findings;

use App\Mcp\Support\FindingPresenter;
use App\Mcp\Support\Format;
use App\Mcp\Tools\AppSecTool;
use App\Models\Enums\EventState;
use App\Models\EventComment;
use App\Models\LocalFinding;
use App\Models\LocalFindingComment;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('find-similar-findings')]
#[Title('Find similar findings')]
#[Description('Finds every other alert and local finding across the whole inventory with the same rule (e.g. the same CVE, secret type or code rule) as a given alert or local finding, or, with match=package, every local finding on the same package (any version). Each comes with its system, container, state and latest comment, so earlier triage decisions on the same issue can be reused and duplicates spotted. Includes resolved and dismissed ones unless include_closed is false. Requires alerts.view.')]
#[IsReadOnly]
final class FindSimilarFindingsTool extends AppSecTool
{
    private const CLOSED = [EventState::Resolved, EventState::Dismissed];

    public function schema(JsonSchema $schema): array
    {
        return [
            'alert_id' => $schema->integer()->min(1)->description('The alert to compare against.'),
            'local_finding_id' => $schema->integer()->min(1)->description('The local finding to compare against.'),
            'match' => $schema->string()->enum(['rule', 'package'])->default('rule')
                ->description('rule: same rule id, alerts and local findings. package: local findings on the same package; only for a vulnerability local finding.'),
            'include_closed' => $schema->boolean()->default(true)->description('Include resolved and dismissed findings, whose decisions are often the most useful.'),
            'limit' => $schema->integer()->min(1)->max(100)->default(50)->description('Maximum alerts and maximum local findings returned (each).'),
        ];
    }

    protected function requiredPermissions(): array
    {
        return ['alerts.view'];
    }

    protected function run(Request $request, User $actor): ResponseFactory
    {
        $validated = $request->validate([
            'alert_id' => ['nullable', 'integer', 'exists:security_events,id', 'prohibits:local_finding_id', 'required_without:local_finding_id'],
            'local_finding_id' => ['nullable', 'integer', 'exists:local_findings,id'],
            'match' => ['nullable', 'in:rule,package'],
            'include_closed' => ['nullable', 'boolean'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], [
            'alert_id.exists' => 'No alert with id :input.',
            'local_finding_id.exists' => 'No local finding with id :input.',
            'alert_id.prohibits' => 'Provide alert_id or local_finding_id, not both.',
            'alert_id.required_without' => 'Provide alert_id or local_finding_id.',
        ]);

        $reference = isset($validated['alert_id'])
            ? SecurityEvent::query()->findOrFail((int) $validated['alert_id'])
            : LocalFinding::query()->findOrFail((int) $validated['local_finding_id']);
        $byPackage = ($validated['match'] ?? 'rule') === 'package';
        $includeClosed = (bool) ($validated['include_closed'] ?? true);
        $limit = (int) ($validated['limit'] ?? 50);

        [$alerts, $findings] = $byPackage
            ? [null, $this->samePackage($reference)]
            : [$this->alertsWithRule($reference), $this->findingsWithRule($reference)];

        if (! $includeClosed) {
            $alerts?->whereNotIn('state', self::closedValues());
            $findings->whereNotIn('status', self::closedValues());
        }

        return Response::structured([
            'reference' => self::reference($reference),
            'summary' => $this->summary($alerts, $findings),
            'alerts' => $alerts === null ? [] : $this->alertRows($alerts, $limit),
            'local_findings' => $this->findingRows($findings, $limit),
        ]);
    }

    /** @return Builder<SecurityEvent> */
    private function alertsWithRule(SecurityEvent|LocalFinding $reference): Builder
    {
        return SecurityEvent::query()
            ->where('rule_id', self::ruleOf($reference))
            ->when($reference instanceof SecurityEvent, fn (Builder $query) => $query->whereKeyNot($reference->id));
    }

    /** @return Builder<LocalFinding> */
    private function findingsWithRule(SecurityEvent|LocalFinding $reference): Builder
    {
        return LocalFinding::query()
            ->where('rule_id', self::ruleOf($reference))
            ->when($reference instanceof LocalFinding, fn (Builder $query) => $query->whereKeyNot($reference->id));
    }

    /** @return Builder<LocalFinding> */
    private function samePackage(SecurityEvent|LocalFinding $reference): Builder
    {
        if (! $reference instanceof LocalFinding || $reference->kind !== LocalFinding::KIND_VULNERABILITY || blank($reference->package_name)) {
            throw new InvalidArgumentException('match=package needs a local finding of kind vulnerability with a package name.');
        }

        return LocalFinding::query()->where('package_name', $reference->package_name)->whereKeyNot($reference->id);
    }

    private static function ruleOf(SecurityEvent|LocalFinding $reference): string
    {
        $rule = trim((string) $reference->rule_id);

        if ($rule === '') {
            throw ValidationException::withMessages(['rule' => 'The reference finding has no rule id, so there is nothing to match on.']);
        }

        return $rule;
    }

    /** @return list<string> */
    private static function closedValues(): array
    {
        return array_map(fn (EventState $state): string => $state->value, self::CLOSED);
    }

    /** @return array<string, mixed> */
    private static function reference(SecurityEvent|LocalFinding $reference): array
    {
        return [
            'kind' => $reference instanceof SecurityEvent ? 'alert' : 'local_finding',
            'id' => $reference->id,
            'rule_id' => $reference->rule_id,
            'package_name' => $reference instanceof LocalFinding ? $reference->package_name : null,
            'package_version' => $reference instanceof LocalFinding ? $reference->package_version : null,
        ];
    }

    /**
     * @param  Builder<SecurityEvent>|null  $alerts
     * @param  Builder<LocalFinding>  $findings
     * @return array<string, mixed>
     */
    private function summary(?Builder $alerts, Builder $findings): array
    {
        $systems = (clone $findings)->whereNotNull('software_system_id')->distinct()->pluck('software_system_id');

        if ($alerts !== null) {
            $systems = $systems->merge((clone $alerts)->distinct()->pluck('software_system_id'));
        }

        return [
            'alerts_by_state' => $alerts === null ? [] : self::countBy($alerts, 'state'),
            'local_findings_by_status' => self::countBy($findings, 'status'),
            'systems' => $systems->unique()->count(),
        ];
    }

    /**
     * @param  Builder<SecurityEvent>|Builder<LocalFinding>  $query
     * @return array<string, int>
     */
    private static function countBy(Builder $query, string $column): array
    {
        return (clone $query)->toBase()
            ->select($column)
            ->selectRaw('count(*) as aggregate')
            ->groupBy($column)
            ->orderBy($column)
            ->pluck('aggregate', $column)
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * @param  Builder<SecurityEvent>  $alerts
     * @return array<int, array<string, mixed>>
     */
    private function alertRows(Builder $alerts, int $limit): array
    {
        $rows = $alerts->with(['softwareSystem:id,name', 'container:id,name'])->orderByDesc('last_seen_at')->orderByDesc('id')->limit($limit)->get();
        $latest = self::latestComments(EventComment::query()->with('author:id,name')->whereIn('event_id', $rows->modelKeys()), 'event_id');

        return $rows->map(fn (SecurityEvent $event): array => [
            'id' => $event->id,
            'title' => $event->title,
            'source_id' => $event->source_id,
            'system' => FindingPresenter::named($event->softwareSystem),
            'container' => FindingPresenter::named($event->container),
            'state' => Format::enum($event->getAttribute('state')),
            'severity' => Format::enum($event->getAttribute('severity')),
            'latest_comment' => $latest->get($event->id),
            'last_seen_at' => Format::date($event->getAttribute('last_seen_at')),
        ])->values()->all();
    }

    /**
     * @param  Builder<LocalFinding>  $findings
     * @return array<int, array<string, mixed>>
     */
    private function findingRows(Builder $findings, int $limit): array
    {
        $rows = $findings->with(['softwareSystem:id,name', 'owner'])->orderByDesc('last_seen_at')->orderByDesc('id')->limit($limit)->get();
        $latest = self::latestComments(LocalFindingComment::query()->with('author:id,name')->whereIn('local_finding_id', $rows->modelKeys()), 'local_finding_id');

        return $rows->map(fn (LocalFinding $finding): array => [
            'id' => $finding->id,
            'title' => $finding->title,
            'kind' => $finding->kind,
            'system' => FindingPresenter::named($finding->softwareSystem),
            'owner' => FindingPresenter::owner($finding->owner),
            'package' => $finding->package_name === null ? null : trim($finding->package_name . ' ' . $finding->package_version),
            'status' => Format::enum($finding->getAttribute('status')),
            'severity' => Format::enum($finding->getAttribute('overridden_severity')) ?? $finding->severity,
            'latest_comment' => $latest->get($finding->id),
            'last_seen_at' => Format::date($finding->getAttribute('last_seen_at')),
        ])->values()->all();
    }

    /**
     * The newest comment of each finding, keyed by finding id.
     *
     * @param  Builder<EventComment>|Builder<LocalFindingComment>  $comments
     * @return Collection<int, array<string, mixed>>
     */
    private static function latestComments(Builder $comments, string $foreignKey): Collection
    {
        return $comments->orderByDesc('created_at')->orderByDesc('id')->get()
            ->unique($foreignKey)
            ->mapWithKeys(fn (EventComment|LocalFindingComment $comment): array => [(int) $comment->getAttribute($foreignKey) => FindingPresenter::comment($comment)]);
    }
}
