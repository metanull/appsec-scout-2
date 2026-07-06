<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Resources\SecurityContainerResource;
use App\Filament\Resources\SoftwareAssetResource;
use App\Filament\Resources\SoftwareSystemResource;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;

/**
 * Asset/System/Container columns shared by every place a LocalFinding row is
 * listed, mirroring SoftwareComponentOwnerColumns for dependencies.
 */
final class LocalFindingOwnerColumns
{
    /** @return list<TextColumn> */
    public static function columns(): array
    {
        return [
            TextColumn::make('softwareAsset.name')
                ->label('Asset')
                ->placeholder('-')
                ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                    SoftwareAsset::select('name')->whereColumn('software_assets.id', 'local_findings.software_asset_id'),
                    $direction === 'desc' ? 'desc' : 'asc',
                ))
                ->url(fn (LocalFinding $record): ?string => $record->softwareAsset
                    ? SoftwareAssetResource::getUrl('view', ['record' => $record->softwareAsset])
                    : null),
            TextColumn::make('softwareSystem.name')
                ->label('System')
                ->placeholder('-')
                ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                    SoftwareSystem::select('name')->whereColumn('software_systems.id', 'local_findings.software_system_id'),
                    $direction === 'desc' ? 'desc' : 'asc',
                ))
                ->url(fn (LocalFinding $record): ?string => $record->softwareSystem
                    ? SoftwareSystemResource::getUrl('view', ['record' => $record->softwareSystem])
                    : null),
            TextColumn::make('_container')
                ->label('Container')
                ->state(fn (LocalFinding $record): ?string => $record->owner instanceof SecurityContainer ? $record->owner->name : null)
                ->placeholder('-')
                ->url(fn (LocalFinding $record): ?string => $record->owner instanceof SecurityContainer
                    ? SecurityContainerResource::getUrl('view', ['record' => $record->owner])
                    : null)
                ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                    SecurityContainer::select('name')
                        ->whereColumn('security_containers.id', 'local_findings.owner_id')
                        ->where('local_findings.owner_type', SecurityContainer::class),
                    $direction === 'desc' ? 'desc' : 'asc',
                )),
        ];
    }
}
