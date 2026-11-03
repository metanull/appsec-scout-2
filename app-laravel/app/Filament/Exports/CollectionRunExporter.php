<?php

namespace App\Filament\Exports;

use App\Filament\Support\RunCounts;
use App\Models\RepositoryCollectionRun;
use App\Models\StaticAnalysisRun;
use Filament\Actions\Exports\ExportColumn;
use Illuminate\Database\Eloquent\Builder;

/** Collection Runs / Analysis Runs list export (see CollectionRunResource). */
abstract class CollectionRunExporter extends ListExporter
{
    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('ID'),
            ExportColumn::make('source_control_id')->label('Source Control'),
            ExportColumn::make('status'),
            ExportColumn::make('softwareSystem.name')->label('System'),
            ExportColumn::make('started_at')->label('Started at'),
            ExportColumn::make('finished_at')->label('Finished at'),
            ExportColumn::make('batch_id')->label('Batch ID'),
            ExportColumn::make('counts_json')
                ->label('Counts')
                ->state(fn (RepositoryCollectionRun|StaticAnalysisRun $record): string => RunCounts::format($record->counts_json)),
            ExportColumn::make('error_message')->label('Error'),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with('softwareSystem');
    }
}
