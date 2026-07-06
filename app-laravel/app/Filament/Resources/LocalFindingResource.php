<?php

namespace App\Filament\Resources;

use App\Filament\Resources\LocalFindingResource\Pages\ListLocalFindings;
use App\Filament\Resources\LocalFindingResource\Pages\ViewLocalFinding;
use App\Filament\Support\LocalFindingOwnerColumns;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\User;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Org-wide Trivy finding explorer (vulnerabilities and secrets), modeled on
 * SecurityEventResource (Alerts) — a list + view pair for data that was
 * previously only visible inline in the Local Findings relation manager.
 */
class LocalFindingResource extends Resource
{
    protected static ?string $model = LocalFinding::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-bug-ant';

    protected static string|\UnitEnum|null $navigationGroup = 'Reader';

    protected static ?int $navigationSort = 14;

    protected static ?string $navigationLabel = 'Local Findings';

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('alerts.view');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([]);
    }

    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->with(['owner', 'softwareAsset', 'softwareSystem', 'correlatedSecurityEvent', 'attachment']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Finding')
                ->schema([
                    Grid::make(3)->schema([
                        TextEntry::make('kind')->label('Kind')->badge()->color(fn (string $state): string => $state === LocalFinding::KIND_SECRET ? 'danger' : 'warning'),
                        TextEntry::make('severity')->label('Severity')->badge()->color(fn (?string $state): string => LocalFinding::severityColor($state))->placeholder('-'),
                        TextEntry::make('rule_id')->label('Rule ID')->placeholder('-'),
                        TextEntry::make('title')->label('Title')->wrap()->columnSpanFull(),
                        TextEntry::make('_location')
                            ->label('Location')
                            ->state(fn (LocalFinding $record): string => $record->start_line !== null
                                ? "{$record->file_path}:{$record->start_line}"
                                : $record->file_path)
                            ->placeholder('-'),
                        TextEntry::make('_package')
                            ->label('Package')
                            ->state(fn (LocalFinding $record): ?string => $record->package_name !== null
                                ? trim("{$record->package_name} {$record->package_version}")
                                : null)
                            ->placeholder('-'),
                        TextEntry::make('softwareAsset.name')
                            ->label('Asset')
                            ->url(fn (LocalFinding $record): ?string => $record->softwareAsset
                                ? SoftwareAssetResource::getUrl('view', ['record' => $record->softwareAsset])
                                : null)
                            ->placeholder('-'),
                        TextEntry::make('softwareSystem.name')
                            ->label('System')
                            ->url(fn (LocalFinding $record): ?string => $record->softwareSystem
                                ? SoftwareSystemResource::getUrl('view', ['record' => $record->softwareSystem])
                                : null)
                            ->placeholder('-'),
                        TextEntry::make('_container')
                            ->label('Container')
                            ->state(fn (LocalFinding $record): ?string => $record->owner instanceof SecurityContainer ? $record->owner->name : null)
                            ->url(fn (LocalFinding $record): ?string => $record->owner instanceof SecurityContainer
                                ? SecurityContainerResource::getUrl('view', ['record' => $record->owner])
                                : null)
                            ->placeholder('-'),
                        TextEntry::make('correlated_security_event_id')
                            ->label('Correlated alert')
                            ->state(fn (LocalFinding $record): string => $record->correlated_security_event_id !== null ? '#' . $record->correlated_security_event_id : '-')
                            ->url(fn (LocalFinding $record): ?string => $record->correlated_security_event_id !== null
                                ? SecurityEventResource::getUrl('view', ['record' => $record->correlated_security_event_id])
                                : null)
                            ->color(fn (LocalFinding $record): string => $record->correlated_security_event_id !== null ? 'primary' : 'gray'),
                        TextEntry::make('first_seen_at')->label('First seen')->dateTime('d M Y H:i')->placeholder('-'),
                        TextEntry::make('last_seen_at')->label('Last seen')->since()->placeholder('-'),
                        TextEntry::make('description')->label('Description')->wrap()->placeholder('-')->columnSpanFull(),
                    ]),
                ]),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('kind')->badge()->sortable()->color(fn (string $state): string => $state === LocalFinding::KIND_SECRET ? 'danger' : 'warning'),
                TextColumn::make('severity')->badge()->sortable()->color(fn (?string $state): string => LocalFinding::severityColor($state))->placeholder('-'),
                TextColumn::make('title')->searchable()->sortable()->wrap()->grow(),
                TextColumn::make('file_path')->label('Location')
                    ->formatStateUsing(fn (LocalFinding $record): string => $record->start_line !== null
                        ? "{$record->file_path}:{$record->start_line}"
                        : $record->file_path)
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
            ->filters([
                SelectFilter::make('kind')
                    ->options([
                        LocalFinding::KIND_VULNERABILITY => 'Vulnerability',
                        LocalFinding::KIND_SECRET => 'Secret',
                    ]),
                SelectFilter::make('severity')
                    ->options(fn (): array => LocalFinding::query()
                        ->whereNotNull('severity')
                        ->distinct()
                        ->orderBy('severity')
                        ->pluck('severity', 'severity')
                        ->all()),
            ])
            ->recordUrl(fn (LocalFinding $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultSort('severity')
            ->paginated([25, 50, 100]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListLocalFindings::route('/'),
            'view' => ViewLocalFinding::route('/{record}'),
        ];
    }
}
