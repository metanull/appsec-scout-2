<?php

namespace App\Filament\Exports;

use App\Models\RepositoryProvider;
use Filament\Actions\Exports\ExportColumn;

/** Repository Providers list export. Provider credentials are never exported. */
class RepositoryProviderExporter extends ListExporter
{
    protected static ?string $model = RepositoryProvider::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('ID'),
            ExportColumn::make('provider_type')->label('Type'),
            ExportColumn::make('name'),
            ExportColumn::make('base_url')->label('Base URL'),
            ExportColumn::make('repository_mappings_count')->label('Mappings'),
            ExportColumn::make('updated_at')->label('Updated'),
        ];
    }
}
