<?php

namespace App\Filament\Resources\LocalFindingResource\Support;

use App\Models\LocalFinding;
use App\Models\LocalFindingWorkItemLink;
use App\Models\SecurityContainer;
use Illuminate\Database\Eloquent\Builder;

final class LocalFindingTableQuery
{
    private const KNOWN_SEVERITIES = ['critical', 'high', 'medium', 'low', 'informational'];

    /**
     * Portable rank over the effective (override-aware) severity, normalized to
     * lowercase so the scanner-reported (uppercase) and operator-overridden
     * (lowercase) values rank identically on MySQL 8 and SQLite. Unmapped
     * values (e.g. UNKNOWN) rank 0.
     *
     * @return literal-string
     */
    public static function effectiveSeverityRankSql(): string
    {
        return 'CASE LOWER(COALESCE(overridden_severity, severity))'
            . " WHEN 'critical' THEN 5 WHEN 'high' THEN 4 WHEN 'medium' THEN 3 WHEN 'low' THEN 2 WHEN 'informational' THEN 1"
            . ' ELSE 0 END';
    }

    /**
     * @param  Builder<LocalFinding>  $query
     * @param  list<string>  $kinds
     * @return Builder<LocalFinding>
     */
    public static function applyKinds(Builder $query, array $kinds): Builder
    {
        return $query->when($kinds !== [], fn (Builder $q) => $q->whereIn('kind', $kinds));
    }

    /**
     * @param  Builder<LocalFinding>  $query
     * @param  list<string>  $statuses
     * @return Builder<LocalFinding>
     */
    public static function applyStatuses(Builder $query, array $statuses): Builder
    {
        return $query->when($statuses !== [], fn (Builder $q) => $q->whereIn('status', $statuses));
    }

    /**
     * Filter by effective (override-aware) severity. The special value `unknown`
     * matches rank-0 rows: those whose lowercased effective severity is not one
     * of the five EventSeverity values (or is null).
     *
     * @param  Builder<LocalFinding>  $query
     * @param  list<string>  $severities
     * @return Builder<LocalFinding>
     */
    public static function applyEffectiveSeverities(Builder $query, array $severities): Builder
    {
        if ($severities === []) {
            return $query;
        }

        $lowered = array_map('strtolower', $severities);
        $known = array_values(array_intersect($lowered, self::KNOWN_SEVERITIES));
        $includeUnknown = in_array('unknown', $lowered, true);

        $expression = 'LOWER(COALESCE(overridden_severity, severity))';

        return $query->where(function (Builder $nested) use ($known, $includeUnknown, $expression): void {
            if ($known !== []) {
                $placeholders = implode(', ', array_fill(0, count($known), '?'));
                $nested->orWhereRaw("{$expression} IN ({$placeholders})", $known);
            }

            if ($includeUnknown) {
                $nested->orWhere(function (Builder $unknown) use ($expression): void {
                    $placeholders = implode(', ', array_fill(0, count(self::KNOWN_SEVERITIES), '?'));
                    $unknown
                        ->whereRaw("{$expression} IS NULL")
                        ->orWhereRaw("{$expression} NOT IN ({$placeholders})", self::KNOWN_SEVERITIES);
                });
            }
        });
    }

    /**
     * @param  Builder<LocalFinding>  $query
     * @param  list<string>  $selections
     * @return Builder<LocalFinding>
     */
    public static function applyAssetScopes(Builder $query, array $selections): Builder
    {
        $ids = self::parseIds($selections);

        if ($ids === null) {
            return $query;
        }

        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('software_asset_id', $ids);
    }

    /**
     * @param  Builder<LocalFinding>  $query
     * @param  list<string>  $selections
     * @return Builder<LocalFinding>
     */
    public static function applySystemScopes(Builder $query, array $selections): Builder
    {
        $ids = self::parseIds($selections);

        if ($ids === null) {
            return $query;
        }

        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('software_system_id', $ids);
    }

    /**
     * @param  Builder<LocalFinding>  $query
     * @param  list<string>  $selections
     * @return Builder<LocalFinding>
     */
    public static function applyContainerScopes(Builder $query, array $selections): Builder
    {
        $ids = self::parseIds($selections);

        if ($ids === null) {
            return $query;
        }

        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where('owner_type', SecurityContainer::class)->whereIn('owner_id', $ids);
    }

    /**
     * Filter to findings holding a work-item link whose state is one of the
     * given values. The sentinel `__none__` additionally matches links with a
     * null state ("Unknown"). Findings without any link are matched by the
     * `has_work_item` ternary, not this filter.
     *
     * @param  Builder<LocalFinding>  $query
     * @param  list<string>  $states
     * @return Builder<LocalFinding>
     */
    public static function applyWorkItemStates(Builder $query, array $states): Builder
    {
        if ($states === []) {
            return $query;
        }

        $includeNone = in_array('__none__', $states, true);
        $concrete = array_values(array_filter($states, fn (string $state): bool => $state !== '__none__'));

        return $query->whereHas('workItemLinks', function (Builder $relation) use ($concrete, $includeNone): void {
            /** @var Builder<LocalFindingWorkItemLink> $relation */
            $relation->where(function (Builder $inner) use ($concrete, $includeNone): void {
                if ($concrete !== []) {
                    $inner->orWhereIn('work_item_state', $concrete);
                }

                if ($includeNone) {
                    $inner->orWhereNull('work_item_state');
                }
            });
        });
    }

    /**
     * @param  Builder<LocalFinding>  $query
     * @return Builder<LocalFinding>
     */
    public static function applyHasWorkItem(Builder $query, ?bool $hasWorkItem): Builder
    {
        if ($hasWorkItem === null) {
            return $query;
        }

        return $hasWorkItem
            ? $query->whereHas('workItemLinks')
            : $query->whereDoesntHave('workItemLinks');
    }

    /**
     * @param  Builder<LocalFinding>  $query
     * @return Builder<LocalFinding>
     */
    public static function applyIsCorrelated(Builder $query, ?bool $correlated): Builder
    {
        if ($correlated === null) {
            return $query;
        }

        return $correlated
            ? $query->whereNotNull('correlated_security_event_id')
            : $query->whereNull('correlated_security_event_id');
    }

    /**
     * Escaped, portable LIKE search across every text-bearing column, mirroring
     * SecurityEventTableQuery::applySearch.
     *
     * @param  Builder<LocalFinding>  $query
     * @return Builder<LocalFinding>
     */
    public static function applySearch(Builder $query, ?string $search): Builder
    {
        $search = trim((string) $search);

        if ($search === '') {
            return $query;
        }

        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';

        $columns = ['title', 'description', 'rule_id', 'file_path', 'package_name', 'package_version', 'metadata'];

        return $query->where(function (Builder $nested) use ($columns, $like): void {
            foreach ($columns as $column) {
                $nested->orWhereRaw($column . " LIKE ? ESCAPE '\\'", [$like]);
            }
        });
    }

    /**
     * Parse a filter's raw selections into positive int ids. Returns null when
     * no filter is active (empty selection) so callers can pass through.
     *
     * @param  list<string>  $selections
     * @return list<int>|null
     */
    private static function parseIds(array $selections): ?array
    {
        if ($selections === []) {
            return null;
        }

        return array_values(array_filter(array_map('intval', $selections), fn (int $id): bool => $id > 0));
    }
}
