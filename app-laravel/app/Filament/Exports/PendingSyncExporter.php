<?php

namespace App\Filament\Exports;

use App\Models\SecurityEvent;
use Filament\Actions\Exports\ExportColumn;

/** Pending Sync list export: each alert's current and staged state/severity. */
class PendingSyncExporter extends ListExporter
{
    protected static ?string $model = SecurityEvent::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('ID'),
            ExportColumn::make('source_id')->label('Source'),
            ExportColumn::make('title'),
            ExportColumn::make('state')->label('Current state'),
            ExportColumn::make('pending_state')->label('Pending state'),
            ExportColumn::make('pending_dismissal_reason')->label('Dismissal reason'),
            ExportColumn::make('severity')->label('Current severity'),
            ExportColumn::make('pending_severity')->label('Pending severity'),
            ExportColumn::make('pending_comment')->label('Comment'),
            ExportColumn::make('updated_at')->label('Last edited'),
            ExportColumn::make('source_event_id')->label('Source alert ID'),
        ];
    }
}
