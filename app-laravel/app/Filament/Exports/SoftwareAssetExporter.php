<?php

namespace App\Filament\Exports;

use App\Models\SoftwareAsset;
use Filament\Actions\Exports\ExportColumn;

/**
 * Software Assets list export. The counts are the resource query's own
 * withCount() aggregates, carried into the export with the rest of its query.
 */
class SoftwareAssetExporter extends ListExporter
{
    protected static ?string $model = SoftwareAsset::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('ID'),
            ExportColumn::make('name'),
            ExportColumn::make('software_systems_count')->label('Systems'),
            ExportColumn::make('open_events_count')->label('Open'),
            ExportColumn::make('critical_events_count')->label('Critical'),
            ExportColumn::make('high_events_count')->label('High'),
            ExportColumn::make('attachments_count')->label('Attachments'),
            ExportColumn::make('updated_at')->label('Updated'),
        ];
    }
}
