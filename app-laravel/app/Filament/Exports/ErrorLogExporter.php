<?php

namespace App\Filament\Exports;

use App\Models\ErrorLog;
use Filament\Actions\Exports\ExportColumn;
use Illuminate\Database\Eloquent\Builder;

/** Error Log list export; the context is written as JSON. */
class ErrorLogExporter extends ListExporter
{
    protected static ?string $model = ErrorLog::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('ID'),
            ExportColumn::make('occurred_at')->label('Occurred at'),
            ExportColumn::make('level'),
            ExportColumn::make('channel'),
            ExportColumn::make('softwareSystem.name')->label('System'),
            ExportColumn::make('securityContainer.name')->label('Container'),
            ExportColumn::make('message'),
            ExportColumn::make('context_json')
                ->label('Context')
                ->state(fn (ErrorLog $record): string => self::json($record->context_json)),
            ExportColumn::make('trace'),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['softwareSystem', 'securityContainer']);
    }
}
