<?php

namespace App\Filament\Resources;

use App\Filament\Resources\SoftwareComponentResource\Pages\ListSoftwareComponents;
use App\Filament\Resources\SoftwareComponentResource\Pages\ViewSoftwareComponent;
use App\Filament\Support\SoftwareComponentOwnerColumns;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareComponent;
use App\Models\SoftwareSystem;
use App\Models\User;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Auth;

/**
 * Org-wide dependency explorer: every row is one (owner, package) pairing
 * parsed from a Trivy SBOM attachment, so searching by package name/version
 * directly answers "where is this dependency used".
 */
class SoftwareComponentResource extends Resource
{
    protected static ?string $model = SoftwareComponent::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-puzzle-piece';

    protected static string|\UnitEnum|null $navigationGroup = 'Reader';

    protected static ?int $navigationSort = 11;

    protected static ?string $navigationLabel = 'Dependencies';

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
        return parent::getEloquentQuery()->with(['owner', 'softwareAsset', 'softwareSystem']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Summary')
                ->schema([
                    TextEntry::make('_used_by')
                        ->label('Used by')
                        ->state(fn (SoftwareComponent $record): string => self::ownerLabel($record))
                        ->url(fn (SoftwareComponent $record): ?string => self::ownerUrl($record))
                        ->columnSpanFull(),
                    TextEntry::make('name')->label('Name')->wrap(),
                    TextEntry::make('version')->label('Version')->placeholder('-'),
                    TextEntry::make('ecosystem')->label('Ecosystem')->badge()->color('gray')->placeholder('-'),
                    TextEntry::make('license')->label('License')->placeholder('-'),
                    TextEntry::make('purl')->label('Package URL')->wrap()->copyable(),
                    TextEntry::make('first_seen_at')->label('First seen')->dateTime('d M Y H:i')->placeholder('-'),
                    TextEntry::make('last_seen_at')->label('Last seen')->since()->placeholder('-'),
                    TextEntry::make('removed_at')
                        ->label('Removed')
                        ->since()
                        ->placeholder('No - still present in the latest scan')
                        ->badge()
                        ->color(fn (SoftwareComponent $record): string => $record->removed_at !== null ? 'danger' : 'success'),
                ])
                ->columns(3),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->wrap()->grow(),
                TextColumn::make('version')->searchable()->sortable()->placeholder('-'),
                TextColumn::make('ecosystem')->badge()->color('gray')->sortable()->placeholder('-'),
                TextColumn::make('license')->placeholder('-')->toggleable(isToggledHiddenByDefault: true),
                ...SoftwareComponentOwnerColumns::columns(),
                TextColumn::make('last_seen_at')->label('Last seen')->since()->placeholder('-'),
                IconColumn::make('removed_at')
                    ->label('Removed')
                    ->boolean()
                    ->getStateUsing(fn (SoftwareComponent $record): bool => $record->removed_at !== null)
                    ->trueColor('danger')
                    ->falseColor('success'),
            ])
            ->filters([
                SelectFilter::make('ecosystem')
                    ->options(fn (): array => SoftwareComponent::query()
                        ->whereNotNull('ecosystem')
                        ->distinct()
                        ->orderBy('ecosystem')
                        ->pluck('ecosystem', 'ecosystem')
                        ->all()),
                TernaryFilter::make('removed_at')
                    ->label('Removed')
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('removed_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('removed_at'),
                        blank: fn (Builder $query): Builder => $query,
                    ),
                self::packageFilter(),
            ])
            // Grouping by component name is replaced by the Packages page, which
            // pages by package; Filament groups page by row and split packages.
            ->groups([
                Group::make('_used_by')
                    ->label('Used by')
                    ->getTitleFromRecordUsing(fn (SoftwareComponent $record): string => self::ownerLabel($record))
                    ->orderQueryUsing(function (Builder $query, string $direction): Builder {
                        $direction = $direction === 'desc' ? 'desc' : 'asc';

                        return $query->orderBy('owner_type', $direction)->orderBy('owner_id', $direction);
                    }),
            ])
            ->recordUrl(fn (SoftwareComponent $record): string => static::getUrl('view', ['record' => $record]))
            ->defaultSort('name')
            ->paginated([25, 50, 100]);
    }

    public static function getRelations(): array
    {
        return [];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListSoftwareComponents::route('/'),
            'view' => ViewSoftwareComponent::route('/{record}'),
        ];
    }

    /**
     * Exact name (and ecosystem) match, so the Packages page can link to every
     * usage of one package without a LIKE search pulling in similarly named ones.
     */
    private static function packageFilter(): Filter
    {
        return Filter::make('package')
            ->schema([
                TextInput::make('name')->label('Package name'),
                TextInput::make('ecosystem')->label('Ecosystem'),
            ])
            ->query(fn (Builder $query, array $data): Builder => self::applyPackageFilter($query, $data))
            ->indicateUsing(function (array $data): ?string {
                if (! filled($data['name'] ?? null)) {
                    return null;
                }

                return filled($data['ecosystem'] ?? null)
                    ? "Package: {$data['name']} ({$data['ecosystem']})"
                    : "Package: {$data['name']}";
            });
    }

    /**
     * @param  Builder<SoftwareComponent>  $query
     * @param  array<string, mixed>  $data
     * @return Builder<SoftwareComponent>
     */
    private static function applyPackageFilter(Builder $query, array $data): Builder
    {
        if (filled($data['name'] ?? null)) {
            $query->where('name', $data['name']);
        }

        if (filled($data['ecosystem'] ?? null)) {
            $query->where('ecosystem', $data['ecosystem']);
        }

        return $query;
    }

    private static function ownerLabel(SoftwareComponent $record): string
    {
        $owner = $record->owner;

        return match (true) {
            $owner instanceof SoftwareAsset => "Asset: {$owner->name}",
            $owner instanceof SoftwareSystem => "System: {$owner->name}",
            $owner instanceof SecurityContainer => "Container: {$owner->name}",
            default => '-',
        };
    }

    private static function ownerUrl(SoftwareComponent $record): ?string
    {
        $owner = $record->owner;

        return match (true) {
            $owner instanceof SoftwareAsset => SoftwareAssetResource::getUrl('view', ['record' => $owner]),
            $owner instanceof SoftwareSystem => SoftwareSystemResource::getUrl('view', ['record' => $owner]),
            $owner instanceof SecurityContainer => SecurityContainerResource::getUrl('view', ['record' => $owner]),
            default => null,
        };
    }
}
