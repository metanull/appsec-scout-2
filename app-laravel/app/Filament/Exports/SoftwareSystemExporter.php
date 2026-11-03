<?php

namespace App\Filament\Exports;

use App\Models\SoftwareSystem;
use Filament\Actions\Exports\ExportColumn;

/**
 * Software Systems list export. The alert counts are the list query's own
 * withCount() aggregates, carried into the export with the rest of its query.
 */
class SoftwareSystemExporter extends ListExporter
{
    protected static ?string $model = SoftwareSystem::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('ID'),
            ExportColumn::make('name'),
            ExportColumn::make('source_id')->label('Source'),
            ExportColumn::make('open_events_count')->label('Open'),
            ExportColumn::make('critical_events_count')->label('Critical'),
            ExportColumn::make('high_events_count')->label('High'),
            ExportColumn::make('medium_events_count')->label('Medium'),
            ExportColumn::make('updated_at')->label('Updated'),
            ExportColumn::make('removed_at')
                ->label('Removed')
                ->formatStateUsing(fn (mixed $state): string => self::yesNo($state)),
            ExportColumn::make('softwareAsset.name')->label('Asset'),
            ExportColumn::make('url')->label('URL'),
        ];
    }
}
