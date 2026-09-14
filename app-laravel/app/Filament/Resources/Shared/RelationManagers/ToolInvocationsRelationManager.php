<?php

declare(strict_types=1);

namespace App\Filament\Resources\Shared\RelationManagers;

use App\Models\ToolInvocation;
use App\Models\User;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

class ToolInvocationsRelationManager extends RelationManager
{
    protected static bool $isLazy = false;

    protected static string $relationship = 'toolInvocations';

    protected static ?string $title = 'Tool Invocations';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        $user = Auth::user();

        return $user instanceof User && $user->can('admin.queue');
    }

    public function table(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('securityContainer.name')
                    ->label('Repository')
                    ->searchable()
                    ->sortable()
                    ->placeholder('-'),
                TextColumn::make('tool')
                    ->label('Tool')
                    ->badge()
                    ->color('gray')
                    ->sortable(),
                TextColumn::make('outcome')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'ran_with_findings' => 'warning',
                        'ran_clean' => 'success',
                        'skipped_no_toolchain', 'skipped_unchanged' => 'gray',
                        'failed' => 'danger',
                        default => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('tool_version')
                    ->label('Version')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('exit_code')
                    ->placeholder('-')
                    ->toggleable(isToggledHiddenByDefault: true),
                TextColumn::make('started_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('duration_seconds')
                    ->label('Duration')
                    ->formatStateUsing(fn (?int $state): string => $state === null ? '-' : $state . 's')
                    ->toggleable(),
            ])
            ->filters([
                SelectFilter::make('tool')
                    ->options(fn (): array => ToolInvocation::query()->distinct()->pluck('tool', 'tool')->all()),
                SelectFilter::make('outcome')
                    ->options([
                        'ran_with_findings' => 'Ran — findings',
                        'ran_clean' => 'Ran — clean',
                        'skipped_no_toolchain' => 'Skipped — no toolchain',
                        'skipped_unchanged' => 'Skipped — unchanged',
                        'failed' => 'Failed',
                    ]),
            ])
            ->defaultSort('started_at', 'desc')
            ->paginated([25, 50, 100])
            ->emptyStateDescription('No tool invocations recorded for this run.');
    }
}
