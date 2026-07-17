<?php

namespace App\Filament\Resources\SecurityEventResource\Support;

use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\WorkItemLink;
use Illuminate\Database\Eloquent\Builder;

final class SecurityEventTableQuery
{
    /**
     * @param  Builder<SecurityEvent>  $query
     * @param  list<string>  $severities
     * @return Builder<SecurityEvent>
     */
    public static function applySeverities(Builder $query, array $severities): Builder
    {
        return $query->when($severities !== [], fn (Builder $q) => $q->whereIn('severity', $severities));
    }

    /**
     * @param  Builder<SecurityEvent>  $query
     * @param  list<string>  $states
     * @return Builder<SecurityEvent>
     */
    public static function applyStates(Builder $query, array $states): Builder
    {
        return $query->when($states !== [], fn (Builder $q) => $q->whereIn('state', $states));
    }

    /**
     * @param  Builder<SecurityEvent>  $query
     * @param  list<string>  $sources
     * @return Builder<SecurityEvent>
     */
    public static function applySources(Builder $query, array $sources): Builder
    {
        return $query->when($sources !== [], fn (Builder $q) => $q->whereIn('source_id', $sources));
    }

    /**
     * @param  Builder<SecurityEvent>  $query
     * @param  list<string>  $types
     * @return Builder<SecurityEvent>
     */
    public static function applyTypes(Builder $query, array $types): Builder
    {
        return $query->when($types !== [], fn (Builder $q) => $q->whereIn('type', $types));
    }

    /**
     * @param  Builder<SecurityEvent>  $query
     * @return Builder<SecurityEvent>
     */
    public static function applySystem(Builder $query, ?int $systemId): Builder
    {
        return $query->when($systemId !== null, fn (Builder $q) => $q->where('software_system_id', $systemId));
    }

    /**
     * @param  Builder<SecurityEvent>  $query
     * @param  list<string>  $selections
     * @return Builder<SecurityEvent>
     */
    public static function applySystemScopes(Builder $query, array $selections): Builder
    {
        if ($selections === []) {
            return $query;
        }

        $ids = array_values(array_filter(array_map('intval', $selections), fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('software_system_id', $ids);
    }

    /**
     * @param  Builder<SecurityEvent>  $query
     * @param  list<string>  $selections
     * @return Builder<SecurityEvent>
     */
    public static function applyAssetScopes(Builder $query, array $selections): Builder
    {
        if ($selections === []) {
            return $query;
        }

        $ids = array_values(array_filter(array_map('intval', $selections), fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereHas('softwareSystem', function (Builder $q) use ($ids): void {
            /** @var Builder<SoftwareSystem> $q */
            $q->whereIn('software_asset_id', $ids);
        });
    }

    /**
     * @param  Builder<SecurityEvent>  $query
     * @return Builder<SecurityEvent>
     */
    public static function applyContainer(Builder $query, ?int $containerId): Builder
    {
        return $query->when($containerId !== null, fn (Builder $q) => $q->where('container_id', $containerId));
    }

    /**
     * @param  Builder<SecurityEvent>  $query
     * @param  list<string>  $selections
     * @return Builder<SecurityEvent>
     */
    public static function applyContainerScopes(Builder $query, array $selections): Builder
    {
        if ($selections === []) {
            return $query;
        }

        $ids = array_values(array_filter(array_map('intval', $selections), fn (int $id): bool => $id > 0));

        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('container_id', $ids);
    }

    /**
     * @param  Builder<SecurityEvent>  $query
     * @return Builder<SecurityEvent>
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
     * Filter to events linked to a specific tracker work item (by tracker_id + work_item_id).
     *
     * @param  Builder<SecurityEvent>  $query
     * @return Builder<SecurityEvent>
     */
    public static function applyWorkItem(Builder $query, ?string $trackerId, ?string $workItemId): Builder
    {
        if ($trackerId === null || $trackerId === '' || $workItemId === null || $workItemId === '') {
            return $query;
        }

        $tid = $trackerId;
        $wid = $workItemId;

        return $query->whereHas('workItemLinks', function (Builder $q) use ($tid, $wid): void {
            /** @var Builder<WorkItemLink> $q */
            $q->where('tracker_id', $tid)->where('work_item_id', $wid);
        });
    }

    /**
     * @param  Builder<SecurityEvent>  $query
     * @param  list<string>  $tags
     * @return Builder<SecurityEvent>
     */
    public static function applyTags(Builder $query, array $tags): Builder
    {
        if ($tags === []) {
            return $query;
        }

        return $query->where(function (Builder $nested) use ($tags): void {
            foreach ($tags as $tag) {
                $nested->orWhereJsonContains('metadata->tags', $tag);
            }
        });
    }

    /**
     * @param  Builder<SecurityEvent>  $query
     * @return Builder<SecurityEvent>
     */
    public static function applySearch(Builder $query, ?string $search): Builder
    {
        $search = trim((string) $search);

        if ($search === '') {
            return $query;
        }

        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';

        return $query->where(function (Builder $nested) use ($like): void {
            $nested
                ->where('title', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhere('rule_id', 'like', $like)
                ->orWhere('metadata', 'like', $like);
        });
    }
}
