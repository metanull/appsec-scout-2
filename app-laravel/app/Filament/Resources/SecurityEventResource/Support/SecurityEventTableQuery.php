<?php

namespace App\Filament\Resources\SecurityEventResource\Support;

use App\Models\SecurityEvent;
use App\Models\SoftwareSystemLinkMember;
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

        $physicalIds = [];
        $virtualIds = [];

        foreach ($selections as $selection) {
            if (str_starts_with($selection, 'physical:')) {
                $physicalIds[] = (int) str_replace('physical:', '', $selection);
            }

            if (str_starts_with($selection, 'virtual:')) {
                $virtualIds[] = (int) str_replace('virtual:', '', $selection);
            }
        }

        $virtualMemberIds = SoftwareSystemLinkMember::query()
            ->whereIn('link_id', $virtualIds)
            ->pluck('software_system_id')
            ->all();

        $ids = array_values(array_unique(array_merge($physicalIds, array_map(fn (mixed $id): int => (int) $id, $virtualMemberIds))));

        if ($ids === []) {
            return $query->whereRaw('1 = 0');
        }

        return $query->whereIn('software_system_id', $ids);
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

        return $query->where(function (Builder $nested) use ($like, $search): void {
            $nested
                ->where('title', 'like', $like)
                ->orWhere('description', 'like', $like)
                ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.cveId')) LIKE ?", [$like])
                ->orWhereRaw("JSON_UNQUOTE(JSON_EXTRACT(metadata, '$.ruleId')) LIKE ?", [$like])
                ->orWhereRaw('MATCH(title, description) AGAINST (? IN BOOLEAN MODE)', [$search]);
        });
    }
}
