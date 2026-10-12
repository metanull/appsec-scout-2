<?php

namespace App\Mcp\Tools\Findings;

use App\Filament\Resources\LocalFindingResource\Support\LocalFindingTableQuery;
use App\Filament\Resources\SecurityEventResource\Support\SecurityEventTableQuery;
use App\Mcp\Support\Format;
use App\Mcp\Support\Scope;
use App\Mcp\Tools\AppSecTool;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\LocalFinding;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('get-triage-summary')]
#[Title('Get triage summary')]
#[Description('Summarizes what needs triage in one asset, system or container across every source: alert counts by severity, type and source, alerts with changes pending push to the source, local-finding counts by effective severity and kind, and what is not yet tracked in a work item. For an asset, also ranks its systems by critical and high counts. Start here before drilling down with list-alerts / list-local-findings. state defaults to the untriaged and in-progress states. Requires alerts.view.')]
#[IsReadOnly]
final class GetTriageSummaryTool extends AppSecTool
{
    private const DEFAULT_STATES = [EventState::Open, EventState::Acknowledged, EventState::InProgress];

    private const UNKNOWN = 'unknown';

    public function schema(JsonSchema $schema): array
    {
        return [
            ...Scope::schema($schema),
            'state' => $schema->array()->items($schema->string()->enum(Format::enumValues(EventState::class)))
                ->description('States counted (alert state / local-finding status). Omitted: open, acknowledged, in_progress. Empty array: every state.'),
        ];
    }

    protected function requiredPermissions(): array
    {
        return ['alerts.view'];
    }

    protected function run(Request $request, User $actor): ResponseFactory
    {
        $validated = $request->validate([
            'state' => ['sometimes', 'array'],
            'state.*' => [Rule::enum(EventState::class)],
        ]);

        $scope = Scope::fromRequest($request);
        $described = $scope->describe();
        $states = array_values($validated['state'] ?? array_map(fn (EventState $state): string => $state->value, self::DEFAULT_STATES));

        $alerts = $this->alertQuery($scope, $states);
        $findings = $this->localFindingQuery($scope, $states);

        $payload = [
            'scope' => $described,
            'states' => $states,
            'alerts' => [
                'total' => (clone $alerts)->count(),
                'by_severity' => self::fill(Format::enumValues(EventSeverity::class), self::countBy($alerts, 'severity')),
                'by_type' => self::fill(Format::enumValues(EventType::class), self::countBy($alerts, 'type')),
                'by_source' => self::countBy($alerts, 'source_id'),
                'pending_sync' => (clone $alerts)->where('is_dirty', true)->count(),
                'without_work_item' => SecurityEventTableQuery::applyHasWorkItem(clone $alerts, false)->count(),
            ],
            'local_findings' => [
                'total' => (clone $findings)->count(),
                'by_severity' => self::severityBuckets(self::countByEffectiveSeverity($findings)),
                'by_kind' => self::fill([LocalFinding::KIND_VULNERABILITY, LocalFinding::KIND_SECRET, LocalFinding::KIND_CODE_QUALITY], self::countBy($findings, 'kind')),
                'correlated_to_alert' => (clone $findings)->whereNotNull('correlated_security_event_id')->count(),
                'without_work_item' => LocalFindingTableQuery::applyHasWorkItem(clone $findings, false)->count(),
            ],
        ];

        if ($scope->asset !== null) {
            $payload['systems'] = $this->systemBreakdown($scope, $alerts, $findings);
        }

        return Response::structured($payload);
    }

    /**
     * @param  list<string>  $states
     * @return Builder<SecurityEvent>
     */
    private function alertQuery(Scope $scope, array $states): Builder
    {
        $query = SecurityEvent::query();

        SecurityEventTableQuery::applyStates($query, $states);
        SecurityEventTableQuery::applyAssetScopes($query, $scope->assetIds());
        SecurityEventTableQuery::applySystemScopes($query, $scope->systemIds());
        SecurityEventTableQuery::applyContainerScopes($query, $scope->containerIds());

        return $query;
    }

    /**
     * @param  list<string>  $states
     * @return Builder<LocalFinding>
     */
    private function localFindingQuery(Scope $scope, array $states): Builder
    {
        $query = LocalFinding::query();

        LocalFindingTableQuery::applyStatuses($query, $states);
        LocalFindingTableQuery::applyAssetScopes($query, $scope->assetIds());
        LocalFindingTableQuery::applySystemScopes($query, $scope->systemIds());
        LocalFindingTableQuery::applyContainerScopes($query, $scope->containerIds());

        return $query;
    }

    /**
     * The asset's systems with their open-work counts, the most critical first.
     *
     * @param  Builder<SecurityEvent>  $alerts
     * @param  Builder<LocalFinding>  $findings
     * @return array<int, array<string, mixed>>
     */
    private function systemBreakdown(Scope $scope, Builder $alerts, Builder $findings): array
    {
        $alertCounts = (clone $alerts)->toBase()
            ->select(['software_system_id', 'severity'])
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy('software_system_id', 'severity')
            ->get();

        $findingCounts = (clone $findings)->toBase()
            ->select('software_system_id')
            ->selectRaw(self::effectiveSeveritySql() . ' as bucket, COUNT(*) as aggregate')
            ->groupBy('software_system_id')
            ->groupByRaw(self::effectiveSeveritySql())
            ->get();

        $rows = $scope->systems()->map(function (SoftwareSystem $system) use ($alertCounts, $findingCounts): array {
            $alertsOf = $alertCounts->where('software_system_id', $system->id);
            $findingsOf = $findingCounts->where('software_system_id', $system->id);
            $count = fn (string $severity): int => (int) $alertsOf->where('severity', $severity)->sum('aggregate')
                + (int) $findingsOf->where('bucket', $severity)->sum('aggregate');

            return [
                'id' => $system->id,
                'name' => $system->name,
                'source_id' => $system->source_id,
                'alerts_total' => (int) $alertsOf->sum('aggregate'),
                'local_findings_total' => (int) $findingsOf->sum('aggregate'),
                'critical' => $count(EventSeverity::Critical->value),
                'high' => $count(EventSeverity::High->value),
            ];
        });

        return $rows
            ->sortBy([['critical', 'desc'], ['high', 'desc'], ['name', 'asc']])
            ->values()
            ->all();
    }

    /**
     * @param  Builder<covariant \Illuminate\Database\Eloquent\Model>  $query
     * @return array<string, int>
     */
    private static function countBy(Builder $query, string $column): array
    {
        return (clone $query)->toBase()
            ->select($column)
            ->selectRaw('COUNT(*) as aggregate')
            ->groupBy($column)
            ->pluck('aggregate', $column)
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * @param  Builder<LocalFinding>  $query
     * @return array<string, int>
     */
    private static function countByEffectiveSeverity(Builder $query): array
    {
        return (clone $query)->toBase()
            ->selectRaw(self::effectiveSeveritySql() . ' as bucket, COUNT(*) as aggregate')
            ->groupByRaw(self::effectiveSeveritySql())
            ->pluck('aggregate', 'bucket')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * Fold raw effective severities (scanner values may be anything) into the five levels plus
     * "unknown".
     *
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private static function severityBuckets(array $counts): array
    {
        $levels = Format::enumValues(EventSeverity::class);
        $buckets = array_fill_keys([...$levels, self::UNKNOWN], 0);

        foreach ($counts as $severity => $count) {
            $buckets[in_array($severity, $levels, true) ? $severity : self::UNKNOWN] += $count;
        }

        return $buckets;
    }

    /**
     * @param  list<string>  $keys
     * @param  array<string, int>  $counts
     * @return array<string, int>
     */
    private static function fill(array $keys, array $counts): array
    {
        return array_replace(array_fill_keys($keys, 0), $counts);
    }

    /**
     * Same expression LocalFindingTableQuery filters on: the override, else the scanner value, lowercased.
     *
     * @return literal-string
     */
    private static function effectiveSeveritySql(): string
    {
        return 'LOWER(COALESCE(overridden_severity, severity))';
    }
}
