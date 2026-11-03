<?php

namespace App\Filament\Exports;

use App\Models\SecurityEvent;
use Filament\Actions\Exports\ExportColumn;
use Illuminate\Database\Eloquent\Builder;

/**
 * Alerts list export. The code snippet, raw source data and metadata are never
 * exported: a secret alert's snippet can contain the secret itself.
 */
class SecurityEventExporter extends ListExporter
{
    protected static ?string $model = SecurityEvent::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('ID'),
            ExportColumn::make('softwareSystem.softwareAsset.name')->label('Asset'),
            ExportColumn::make('softwareSystem.name')->label('System'),
            ExportColumn::make('container.name')->label('Container'),
            ExportColumn::make('severity'),
            ExportColumn::make('state'),
            ExportColumn::make('is_dirty')
                ->label('Pending sync')
                ->formatStateUsing(fn (mixed $state): string => self::yesNo($state)),
            ExportColumn::make('source_id')->label('Source'),
            ExportColumn::make('type'),
            ExportColumn::make('title'),
            ExportColumn::make('work_item_state')
                ->label('Tracker')
                ->state(function (SecurityEvent $record): ?string {
                    $link = $record->workItemLinks->first();

                    return $link === null ? null : ($link->work_item_state ?? $link->work_item_id);
                }),
            ExportColumn::make('last_seen_at')->label('Last seen'),
            ExportColumn::make('first_seen_at')->label('First seen'),
            ExportColumn::make('source_event_id')->label('Source alert ID'),
            ExportColumn::make('rule_id')->label('Rule'),
            ExportColumn::make('file_path')->label('File path'),
            ExportColumn::make('start_line')->label('Line'),
            ExportColumn::make('branch'),
            ExportColumn::make('url')->label('URL'),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['workItemLinks', 'softwareSystem.softwareAsset', 'container']);
    }
}
