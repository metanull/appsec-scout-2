<?php

namespace App\Filament\Exports;

use App\Audit\AuditLog;
use Filament\Actions\Exports\ExportColumn;
use Illuminate\Database\Eloquent\Builder;

/** Audit Log list export; the payload is written as JSON. */
class AuditLogExporter extends ListExporter
{
    protected static ?string $model = AuditLog::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('ID'),
            ExportColumn::make('created_at')->label('Timestamp'),
            ExportColumn::make('actor_kind')->label('Actor kind'),
            ExportColumn::make('action'),
            ExportColumn::make('user.name')->label('User'),
            ExportColumn::make('subject_type')->label('Subject type'),
            ExportColumn::make('subject_id')->label('Subject ID'),
            ExportColumn::make('ip')->label('IP'),
            ExportColumn::make('payload_json')
                ->label('Payload')
                ->state(fn (AuditLog $record): string => self::json($record->payload_json)),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with('user');
    }
}
