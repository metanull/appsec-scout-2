<?php

namespace App\Filament\Pages;

use App\Filament\Pages\Support\PackageListQuery;
use App\Filament\Resources\SoftwareComponentResource;
use App\Models\SoftwareComponent;
use Filament\Pages\Page;
use Filament\Schemas\Components\EmbeddedTable;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Concerns\InteractsWithTable;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;

/**
 * One row per package across every SBOM, paged by package. Each row links to
 * the Dependencies list filtered to that package, where every usage is listed.
 *
 * Backed by an array data source (PackageListQuery) rather than an Eloquent
 * query, since a package is an aggregate of component rows, not a model.
 */
class PackagesPage extends Page implements HasTable
{
    use InteractsWithTable;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-archive-box';

    protected static string|\UnitEnum|null $navigationGroup = 'Reader';

    protected static ?int $navigationSort = 12;

    protected static ?string $navigationLabel = 'Packages';

    protected static ?string $title = 'Packages';

    protected static ?string $slug = 'packages';

    public static function canAccess(): bool
    {
        return Auth::user()?->can('alerts.view') ?? false;
    }

    public function content(Schema $schema): Schema
    {
        return $schema->components([
            EmbeddedTable::make(),
        ]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->records(fn (?string $search, ?string $sortColumn, ?string $sortDirection, ?array $filters, int|string $page, int|string $recordsPerPage): LengthAwarePaginator => app(PackageListQuery::class)->paginate(
                search: $search,
                sortColumn: $sortColumn,
                sortDirection: $sortDirection,
                filters: $filters,
                page: (int) $page,
                perPage: (int) $recordsPerPage,
            ))
            ->columns([
                TextColumn::make('name')->searchable()->sortable()->wrap()->grow(),
                TextColumn::make('ecosystem')->badge()->color('gray')->sortable()->placeholder('-'),
                TextColumn::make('versions_count')->label('Versions')->sortable(),
                TextColumn::make('usages_count')->label('Usages')->sortable(),
                TextColumn::make('active_usages_count')->label('Still present')->sortable(),
                TextColumn::make('systems_count')->label('Systems')->sortable(),
                TextColumn::make('latest_seen_at')->label('Last seen')->since()->sortable()->placeholder('-'),
            ])
            ->filters([
                SelectFilter::make('ecosystem')
                    ->options(fn (): array => SoftwareComponent::query()
                        ->whereNotNull('ecosystem')
                        ->distinct()
                        ->orderBy('ecosystem')
                        ->pluck('ecosystem', 'ecosystem')
                        ->all()),
                TernaryFilter::make('removed')
                    ->label('Removed'),
            ])
            ->recordUrl(fn (array $record): string => self::dependenciesUrl($record))
            ->defaultSort('name')
            ->paginated([25, 50, 100])
            ->emptyStateHeading('No packages found');
    }

    /** @param array<string, mixed> $record */
    public static function dependenciesUrl(array $record): string
    {
        return SoftwareComponentResource::getUrl('index', [
            'filters' => [
                'package' => [
                    'name' => $record['name'],
                    'ecosystem' => $record['ecosystem'],
                ],
            ],
        ]);
    }
}
