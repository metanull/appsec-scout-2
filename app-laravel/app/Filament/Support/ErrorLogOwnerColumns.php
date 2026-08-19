<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Resources\SecurityContainerResource;
use App\Filament\Resources\SoftwareSystemResource;
use App\Models\ErrorLog;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;

/**
 * System/Container columns for the Error Log list, mirroring
 * LocalFindingOwnerColumns/SoftwareComponentOwnerColumns. Simpler than
 * those two: software_system_id/security_container_id are plain FKs on
 * ErrorLog, not a polymorphic owner, since a writer that never resolved an
 * owner just leaves them null.
 */
final class ErrorLogOwnerColumns
{
    /** @return list<TextColumn> */
    public static function columns(): array
    {
        return [
            TextColumn::make('softwareSystem.name')
                ->label('System')
                ->placeholder('-')
                ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                    SoftwareSystem::select('name')->whereColumn('software_systems.id', 'error_logs.software_system_id'),
                    $direction === 'desc' ? 'desc' : 'asc',
                ))
                ->url(fn (ErrorLog $record): ?string => $record->softwareSystem
                    ? SoftwareSystemResource::getUrl('view', ['record' => $record->softwareSystem])
                    : null),
            TextColumn::make('securityContainer.name')
                ->label('Container')
                ->placeholder('-')
                ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderBy(
                    SecurityContainer::select('name')->whereColumn('security_containers.id', 'error_logs.security_container_id'),
                    $direction === 'desc' ? 'desc' : 'asc',
                ))
                ->url(fn (ErrorLog $record): ?string => $record->securityContainer
                    ? SecurityContainerResource::getUrl('view', ['record' => $record->securityContainer])
                    : null),
        ];
    }
}
