<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Resources\SecurityContainerResource;
use App\Filament\Resources\SoftwareAssetResource;
use App\Filament\Resources\SoftwareSystemResource;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareComponent;
use App\Models\SoftwareSystem;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;

/**
 * Asset/System/Container columns shared by every place a SoftwareComponent
 * (dependency) row is listed, so the three levels of the hierarchy stay
 * visually and behaviorally identical.
 */
final class SoftwareComponentOwnerColumns
{
    /** @return list<TextColumn> */
    public static function columns(): array
    {
        return [
            TextColumn::make('softwareAsset.name')
                ->label('Asset')
                ->placeholder('-')
                ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                    SoftwareAsset::select('name')->whereColumn('software_assets.id', 'software_components.software_asset_id'),
                    $direction === 'desc' ? 'desc' : 'asc',
                ))
                ->url(fn (SoftwareComponent $record): ?string => $record->softwareAsset
                    ? SoftwareAssetResource::getUrl('view', ['record' => $record->softwareAsset])
                    : null),
            TextColumn::make('softwareSystem.name')
                ->label('System')
                ->placeholder('-')
                ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                    SoftwareSystem::select('name')->whereColumn('software_systems.id', 'software_components.software_system_id'),
                    $direction === 'desc' ? 'desc' : 'asc',
                ))
                ->url(fn (SoftwareComponent $record): ?string => $record->softwareSystem
                    ? SoftwareSystemResource::getUrl('view', ['record' => $record->softwareSystem])
                    : null),
            TextColumn::make('_container')
                ->label('Container')
                ->state(fn (SoftwareComponent $record): ?string => $record->owner instanceof SecurityContainer ? $record->owner->name : null)
                ->placeholder('-')
                ->url(fn (SoftwareComponent $record): ?string => $record->owner instanceof SecurityContainer
                    ? SecurityContainerResource::getUrl('view', ['record' => $record->owner])
                    : null)
                ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                    SecurityContainer::select('name')
                        ->whereColumn('security_containers.id', 'software_components.owner_id')
                        ->where('software_components.owner_type', SecurityContainer::class),
                    $direction === 'desc' ? 'desc' : 'asc',
                )),
        ];
    }
}
