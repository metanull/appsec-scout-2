<?php

namespace App\Filament\Pages\Support;

use App\Models\SoftwareComponent;
use Filament\Support\ArrayRecord;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Carbon;
use stdClass;

/**
 * One row per package (name + ecosystem) aggregated from the SBOM component
 * rows, paginated by package rather than by component row. The aggregates are
 * plain standard SQL so they run unchanged on SQLite, MySQL and PostgreSQL.
 */
class PackageListQuery
{
    private const SORTABLE_COLUMNS = [
        'name',
        'ecosystem',
        'versions_count',
        'usages_count',
        'active_usages_count',
        'systems_count',
        'latest_seen_at',
    ];

    /**
     * @param  array<string, mixed>|null  $filters
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    public function paginate(?string $search, ?string $sortColumn, ?string $sortDirection, ?array $filters, int $page, int $perPage): LengthAwarePaginator
    {
        $query = SoftwareComponent::query()
            ->toBase()
            ->select(['name', 'ecosystem'])
            ->selectRaw('COUNT(*) AS usages_count')
            ->selectRaw('COUNT(DISTINCT version) AS versions_count')
            ->selectRaw('COUNT(DISTINCT software_system_id) AS systems_count')
            ->selectRaw('SUM(CASE WHEN removed_at IS NULL THEN 1 ELSE 0 END) AS active_usages_count')
            ->selectRaw('MAX(last_seen_at) AS latest_seen_at')
            ->groupBy('name', 'ecosystem');

        if (filled($search)) {
            $query->whereLike('name', '%' . $search . '%');
        }

        $ecosystem = $filters['ecosystem']['value'] ?? null;

        if (filled($ecosystem)) {
            $query->where('ecosystem', $ecosystem);
        }

        // A blank ternary filter means "no filter"; filter_var() would read it as false.
        $removed = $filters['removed']['value'] ?? null;

        if ($removed !== null && $removed !== '') {
            filter_var($removed, FILTER_VALIDATE_BOOL)
                ? $query->whereNotNull('removed_at')
                : $query->whereNull('removed_at');
        }

        $column = in_array($sortColumn, self::SORTABLE_COLUMNS, true) ? $sortColumn : 'name';
        $query->orderBy($column, $sortDirection === 'desc' ? 'desc' : 'asc');

        if ($column !== 'name') {
            $query->orderBy('name');
        }

        $query->orderBy('ecosystem');

        /** @var LengthAwarePaginator<int, stdClass> $paginator */
        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        /** @var LengthAwarePaginator<int, array<string, mixed>> $records */
        $records = $paginator->through(fn (stdClass $row): array => $this->record($row));

        return $records;
    }

    /** @return array<string, mixed> */
    private function record(stdClass $row): array
    {
        $name = (string) $row->name;
        $ecosystem = $row->ecosystem !== null ? (string) $row->ecosystem : null;

        return [
            ArrayRecord::getKeyName() => md5($name . '|' . ($ecosystem ?? '')),
            'name' => $name,
            'ecosystem' => $ecosystem,
            'usages_count' => (int) $row->usages_count,
            'versions_count' => (int) $row->versions_count,
            'systems_count' => (int) $row->systems_count,
            'active_usages_count' => (int) $row->active_usages_count,
            'latest_seen_at' => $row->latest_seen_at !== null ? Carbon::parse((string) $row->latest_seen_at) : null,
        ];
    }
}
