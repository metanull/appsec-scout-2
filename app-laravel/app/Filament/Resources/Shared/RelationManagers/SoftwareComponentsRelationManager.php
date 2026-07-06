<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shared\RelationManagers;

use App\Assets\SbomZipBuilder;
use App\Filament\Resources\SoftwareComponentResource;
use App\Filament\Support\SoftwareComponentOwnerColumns;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareComponent;
use App\Models\SoftwareSystem;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class SoftwareComponentsRelationManager extends RelationManager
{
    protected static string $relationship = 'softwareComponents';

    protected static ?string $title = 'Dependencies';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('alerts.view');
    }

    public function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with(['owner', 'softwareAsset', 'softwareSystem']))
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->wrap()->grow(),
                TextColumn::make('version')->sortable()->placeholder('-'),
                TextColumn::make('ecosystem')->label('Ecosystem')->badge()->color('gray')->placeholder('-'),
                TextColumn::make('license')->placeholder('-'),
                ...SoftwareComponentOwnerColumns::columns(),
                TextColumn::make('last_seen_at')->label('Last seen')->since()->placeholder('-'),
            ])
            ->recordUrl(fn (SoftwareComponent $record): string => SoftwareComponentResource::getUrl('view', ['record' => $record]))
            ->headerActions([
                Action::make('downloadSbom')
                    ->label('Download SBOM')
                    ->icon('heroicon-o-arrow-down-tray')
                    ->visible(fn (): bool => app(SbomZipBuilder::class)->hasSbomAttachment($this->sbomOwner()))
                    ->url(fn (): string => $this->sbomDownloadUrl())
                    ->openUrlInNewTab(),
            ])
            ->defaultSort('name')
            ->emptyStateDescription('No dependencies recorded yet.')
            ->paginated([25, 50, 100]);
    }

    private function sbomOwner(): SoftwareAsset|SoftwareSystem|SecurityContainer
    {
        $owner = $this->getOwnerRecord();

        if ($owner instanceof SoftwareAsset || $owner instanceof SoftwareSystem || $owner instanceof SecurityContainer) {
            return $owner;
        }

        abort(500);
    }

    private function sbomDownloadUrl(): string
    {
        $owner = $this->sbomOwner();

        return match (true) {
            $owner instanceof SecurityContainer => route('security-containers.attachments.download', [
                'container' => $owner->getKey(),
                'attachment' => app(SbomZipBuilder::class)->latestAttachmentId($owner),
            ]),
            $owner instanceof SoftwareSystem => route('software-systems.sbom.download', ['system' => $owner->getKey()]),
            $owner instanceof SoftwareAsset => route('assets.sbom.download', ['asset' => $owner->getKey()]),
        };
    }
}
