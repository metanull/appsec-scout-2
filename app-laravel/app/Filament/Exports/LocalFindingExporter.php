<?php

namespace App\Filament\Exports;

use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use Filament\Actions\Exports\ExportColumn;
use Illuminate\Database\Eloquent\Builder;

/**
 * Local Findings list export. The raw SARIF result and finding metadata are
 * never exported: a secret finding's raw result can contain the secret itself.
 */
class LocalFindingExporter extends ListExporter
{
    protected static ?string $model = LocalFinding::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('ID'),
            ExportColumn::make('softwareAsset.name')->label('Asset'),
            ExportColumn::make('softwareSystem.name')->label('System'),
            ExportColumn::make('_container')
                ->label('Container')
                ->state(fn (LocalFinding $record): ?string => $record->owner instanceof SecurityContainer ? $record->owner->name : null),
            ExportColumn::make('_effective_severity')
                ->label('Severity')
                ->state(fn (LocalFinding $record): string => $record->effectiveSeverityLabel()),
            ExportColumn::make('status'),
            ExportColumn::make('kind'),
            ExportColumn::make('title'),
            ExportColumn::make('file_path')->label('Location'),
            ExportColumn::make('start_line')->label('Line'),
            ExportColumn::make('package_name')->label('Package'),
            ExportColumn::make('package_version')->label('Package version'),
            ExportColumn::make('correlated_security_event_id')->label('Correlated alert'),
            ExportColumn::make('work_item_state')
                ->label('Tracker')
                ->state(function (LocalFinding $record): ?string {
                    $link = $record->workItemLinks->first();

                    return $link === null ? null : ($link->work_item_state ?? $link->work_item_id);
                }),
            ExportColumn::make('last_seen_at')->label('Last seen'),
            ExportColumn::make('first_seen_at')->label('First seen'),
            ExportColumn::make('rule_id')->label('Rule'),
            ExportColumn::make('severity')->label('Scanner severity'),
            ExportColumn::make('report_kind')->label('Report kind'),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['owner', 'softwareAsset', 'softwareSystem', 'workItemLinks']);
    }
}
