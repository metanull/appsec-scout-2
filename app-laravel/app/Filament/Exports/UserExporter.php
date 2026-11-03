<?php

namespace App\Filament\Exports;

use App\Models\User;
use Filament\Actions\Exports\ExportColumn;
use Illuminate\Database\Eloquent\Builder;

/** Users list export. Passwords, TOTP secrets and recovery codes are never exported. */
class UserExporter extends ListExporter
{
    protected static ?string $model = User::class;

    public static function getColumns(): array
    {
        return [
            ExportColumn::make('id')->label('ID'),
            ExportColumn::make('name'),
            ExportColumn::make('email'),
            ExportColumn::make('roles_summary')
                ->label('Roles')
                ->state(fn (User $record): string => $record->roles->pluck('name')->join(', ')),
            ExportColumn::make('is_disabled')
                ->label('Disabled')
                ->formatStateUsing(fn (mixed $state): string => self::yesNo($state)),
            ExportColumn::make('two_factor_confirmed_at')
                ->label('2FA')
                ->formatStateUsing(fn (mixed $state): string => $state !== null ? 'Enabled' : 'Not enrolled'),
            ExportColumn::make('last_login_at')->label('Last login'),
            ExportColumn::make('created_at')->label('Created'),
        ];
    }

    public static function modifyQuery(Builder $query): Builder
    {
        return $query->with('roles');
    }
}
