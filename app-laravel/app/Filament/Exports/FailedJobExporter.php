<?php

namespace App\Filament\Exports;

use App\Filament\Support\JobPayloadInspector;
use App\Models\FailedJob;
use Filament\Actions\Exports\ExportColumn;

/**
 * Failed Jobs list export: what the list shows, read from the payload by the
 * same inspector. The raw job payload is never exported.
 */
class FailedJobExporter extends ListExporter
{
    protected static ?string $model = FailedJob::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('ID'),
            ExportColumn::make('uuid')->label('UUID'),
            ExportColumn::make('failed_at')->label('Failed at'),
            ExportColumn::make('queue'),
            ExportColumn::make('job')
                ->label('Job')
                ->state(fn (FailedJob $record): string => JobPayloadInspector::jobName($record->payload)),
            ExportColumn::make('exception_summary')
                ->label('Exception')
                ->state(fn (FailedJob $record): string => JobPayloadInspector::exceptionPreview($record->exception)),
            ExportColumn::make('source_tracker')
                ->label('Source / Tracker')
                ->state(fn (FailedJob $record): ?string => JobPayloadInspector::sourceOrTracker($record->payload)),
            ExportColumn::make('repository')
                ->label('Repository')
                ->state(function (FailedJob $record): ?string {
                    $target = JobPayloadInspector::repositoryTarget($record->payload);

                    return $target === null ? null : "{$target['project']} / {$target['repository']}";
                }),
        ];
    }
}
