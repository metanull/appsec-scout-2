<?php

namespace App\Filament\Exports;

use App\Models\SecurityContainer;
use Filament\Actions\Exports\ExportColumn;
use Illuminate\Database\Eloquent\Builder;

/**
 * Security Containers list export. The alert counts are the list query's own
 * withCount() aggregates, carried into the export with the rest of its query.
 */
class SecurityContainerExporter extends ListExporter
{
    protected static ?string $model = SecurityContainer::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('ID'),
            ExportColumn::make('name'),
            ExportColumn::make('kind'),
            ExportColumn::make('softwareSystem.name')->label('System'),
            ExportColumn::make('open_events_count')->label('Open'),
            ExportColumn::make('critical_events_count')->label('Critical'),
            ExportColumn::make('high_events_count')->label('High'),
            ExportColumn::make('medium_events_count')->label('Medium'),
            ExportColumn::make('last_seen_at')->label('Last seen'),
            ExportColumn::make('removed_at')
                ->label('Removed')
                ->formatStateUsing(fn (mixed $state): string => self::yesNo($state)),
            ExportColumn::make('first_seen_at')->label('First seen'),
            ExportColumn::make('url')->label('URL'),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with('softwareSystem');
    }
}
