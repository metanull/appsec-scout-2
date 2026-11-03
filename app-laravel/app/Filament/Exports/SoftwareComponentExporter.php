<?php

namespace App\Filament\Exports;

use App\Models\SecurityContainer;
use App\Models\SoftwareComponent;
use Filament\Actions\Exports\ExportColumn;
use Illuminate\Database\Eloquent\Builder;

/** Dependencies list export: one row per SBOM component usage. */
class SoftwareComponentExporter extends ListExporter
{
    protected static ?string $model = SoftwareComponent::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('ID'),
            ExportColumn::make('name'),
            ExportColumn::make('version'),
            ExportColumn::make('ecosystem'),
            ExportColumn::make('license'),
            ExportColumn::make('softwareAsset.name')->label('Asset'),
            ExportColumn::make('softwareSystem.name')->label('System'),
            ExportColumn::make('_container')
                ->label('Container')
                ->state(fn (SoftwareComponent $record): ?string => $record->owner instanceof SecurityContainer ? $record->owner->name : null),
            ExportColumn::make('last_seen_at')->label('Last seen'),
            ExportColumn::make('removed_at')
                ->label('Removed')
                ->formatStateUsing(fn (mixed $state): string => self::yesNo($state)),
            ExportColumn::make('purl')->label('Package URL'),
            ExportColumn::make('report_kind')->label('Report kind'),
            ExportColumn::make('first_seen_at')->label('First seen'),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with(['owner', 'softwareAsset', 'softwareSystem']);
    }
}
