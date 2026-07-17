<?php

namespace App\Filament\Widgets\Support;

use App\Models\User;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Support\Facades\Auth;

/**
 * Base for the table half of a breakdown pair. Renders the shared aggregate as
 * a static (unpaginated) table whose first column is a badge linking each
 * bucket to the pre-filtered list page.
 *
 * @phpstan-type BreakdownRow array{key: string, label: string, count: int, color: string|array<array-key, string>}
 */
abstract class BreakdownTableWidget extends TableWidget
{
    protected int|string|array $columnSpan = 2;

    public static function canView(): bool
    {
        $user = Auth::user();

        return $user instanceof User ? $user->can('alerts.view') : false;
    }

    /**
     * @return list<BreakdownRow>
     */
    abstract protected function rows(): array;

    /**
     * @param  BreakdownRow  $row
     */
    abstract protected function rowUrl(array $row): ?string;

    abstract protected function labelColumnHeading(): string;

    protected function emptyStateNote(): string
    {
        return 'No data.';
    }

    protected function tableDescription(): ?string
    {
        return null;
    }

    public function table(Table $table): Table
    {
        $records = [];
        foreach ($this->rows() as $row) {
            $records[] = $row + ['url' => $this->rowUrl($row)];
        }

        return $table
            ->records(fn () => collect($records))
            ->description($this->tableDescription())
            ->columns([
                TextColumn::make('label')
                    ->label($this->labelColumnHeading())
                    ->badge()
                    ->color(fn ($record) => data_get($record, 'color'))
                    ->url(fn ($record): ?string => is_string($url = data_get($record, 'url')) ? $url : null),
                TextColumn::make('count')
                    ->label('Count')
                    ->alignEnd(),
            ])
            ->paginated(false)
            ->emptyStateDescription($this->emptyStateNote());
    }
}
