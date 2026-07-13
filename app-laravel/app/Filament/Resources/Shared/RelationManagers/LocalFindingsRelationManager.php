<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shared\RelationManagers;

use App\Assets\FindingsZipBuilder;
use App\Filament\Resources\LocalFindingResource;
use App\Filament\Resources\SecurityEventResource;
use App\Filament\Support\LocalFindingOwnerColumns;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class LocalFindingsRelationManager extends RelationManager
{
    protected static string $relationship = 'localFindings';

    protected static ?string $title = 'Local findings';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('alerts.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['owner', 'softwareAsset', 'softwareSystem', 'correlatedSecurityEvent']))
            ->columns([
                TextColumn::make('kind')->badge()->color(fn (string $state): string => match ($state) {
                    LocalFinding::KIND_SECRET => 'danger',
                    LocalFinding::KIND_CODE_QUALITY => 'info',
                    default => 'warning',
                }),
                TextColumn::make('severity')->badge()->color(fn (?string $state): string => LocalFinding::severityColor($state))->placeholder('-'),
                TextColumn::make('title')->searchable()->wrap()->grow(),
                TextColumn::make('file_path')->label('Location')
                    ->formatStateUsing(fn (LocalFinding $record): string => $record->start_line !== null
                        ? "{$record->file_path}:{$record->start_line}"
                        : $record->file_path)
                    ->wrap()
                    ->searchable(),
                TextColumn::make('package_name')->label('Package')
                    ->formatStateUsing(fn (?string $state, LocalFinding $record): ?string => $state !== null
                        ? trim("{$state} {$record->package_version}")
                        : null)
                    ->placeholder('-'),
                ...LocalFindingOwnerColumns::columns(),
                TextColumn::make('correlated_security_event_id')
                    ->label('Correlated alert')
                    ->state(fn (LocalFinding $record): string => $record->correlated_security_event_id !== null ? '#' . $record->correlated_security_event_id : '-')
                    ->url(fn (LocalFinding $record): ?string => $record->correlated_security_event_id !== null
                        ? SecurityEventResource::getUrl('view', ['record' => $record->correlated_security_event_id])
                        : null)
                    ->color(fn (LocalFinding $record): string => $record->correlated_security_event_id !== null ? 'primary' : 'gray'),
                TextColumn::make('last_seen_at')->label('Last seen')->since()->placeholder('-'),
            ])
            ->recordUrl(fn (LocalFinding $record): string => LocalFindingResource::getUrl('view', ['record' => $record]))
            ->headerActions([
                Action::make('downloadFindings')
                    ->label('Download findings')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (): bool => app(FindingsZipBuilder::class)->hasFindingAttachment($this->findingsOwner()))
                    ->url(fn (): string => $this->findingsDownloadUrl())
                    ->openUrlInNewTab(),
            ])
            ->defaultSort('severity')
            ->emptyStateDescription('No local findings recorded yet.')
            ->paginated([25, 50, 100]);
    }

    private function findingsOwner(): SoftwareAsset|SoftwareSystem|SecurityContainer
    {
        $owner = $this->getOwnerRecord();

        if ($owner instanceof SoftwareAsset || $owner instanceof SoftwareSystem || $owner instanceof SecurityContainer) {
            return $owner;
        }

        abort(500);
    }

    private function findingsDownloadUrl(): string
    {
        $owner = $this->findingsOwner();

        return match (true) {
            $owner instanceof SecurityContainer => route('security-containers.findings.download', ['container' => $owner->getKey()]),
            $owner instanceof SoftwareSystem => route('software-systems.findings.download', ['system' => $owner->getKey()]),
            $owner instanceof SoftwareAsset => route('assets.findings.download', ['asset' => $owner->getKey()]),
        };
    }
}
