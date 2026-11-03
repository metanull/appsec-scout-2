<?php

namespace App\Filament\Exports;

use App\Filament\Widgets\Support\DashboardData;
use App\Models\SyncRun;
use Filament\Actions\Exports\ExportColumn;

/** Sync Runs list export. */
class SyncRunExporter extends ListExporter
{
    protected static ?string $model = SyncRun::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('ID'),
            ExportColumn::make('source_id')->label('Source'),
            ExportColumn::make('status'),
            ExportColumn::make('started_at')->label('Started at'),
            ExportColumn::make('finished_at')->label('Finished at'),
            ExportColumn::make('counts_json')
                ->label('Counts')
                ->state(fn (SyncRun $record): string => DashboardData::formatCounts($record)),
            ExportColumn::make('error_message')->label('Error'),
        ];
    }
}
