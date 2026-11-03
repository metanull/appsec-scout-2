<?php

namespace App\Filament\Exports;

use Filament\Actions\Exports\Exporter;
use Filament\Actions\Exports\Models\Export;
use Illuminate\Support\Number;

/**
 * Base for the list-page exporters (see App\Filament\Support\ListPageExportAction).
 * Each subclass mirrors its list's table: an ExportColumn named like a table
 * column is preselected when that table column is visible, so the export
 * defaults to what the user sees. Dates are written as UTC timestamps.
 */
abstract class ListExporter extends Exporter
{
    public static function getCompletedNotificationBody(Export $export): string
    {
        $body = 'Your export has completed and ' . Number::format($export->successful_rows) . ' ' . str('row')->plural($export->successful_rows) . ' exported.';

        if ($failedRowsCount = $export->getFailedRowsCount()) {
            $body .= ' ' . Number::format($failedRowsCount) . ' ' . str('row')->plural($failedRowsCount) . ' failed to export.';
        }

        return $body;
    }

    protected static function yesNo(mixed $state): string
    {
        return $state ? 'Yes' : 'No';
    }

    protected static function json(mixed $state): string
    {
        return $state === null ? '' : (json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '');
    }
}
