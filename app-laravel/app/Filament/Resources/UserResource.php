<?php

namespace App\Filament\Resources;

use App\Filament\Resources\UserResource\Pages\CreateUser;
use App\Filament\Resources\UserResource\Pages\EditUser;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use App\Users\UserAdminService;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Role;

class UserResource extends Resource
{
    protected static ?string $model = User::class;

    protected static string|\BackedEnum|null $navigationIcon = 'heroicon-o-users';

    protected static string|\UnitEnum|null $navigationGroup = 'Admin';

    protected static ?int $navigationSort = 26;

    protected static ?string $navigationLabel = 'Users';

    protected static ?string $slug = 'admin/users';

    public static function canViewAny(): bool
    {
        $user = Auth::user();

        return $user instanceof User ? $user->can('admin.users') : false;
    }

    public static function canCreate(): bool
    {
        return static::canViewAny();
    }

    public static function canEdit(Model $record): bool
    {
        return static::canViewAny();
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(255),
            TextInput::make('email')
                ->required()
                ->email()
                ->maxLength(255)
                ->unique(ignoreRecord: true),
            TextInput::make('password')
                ->password()
                ->required(fn (string $operation): bool => $operation === 'create')
                ->dehydrated(fn (?string $state): bool => filled($state))
                ->minLength(8),
            Select::make('roles')
                ->multiple()
                ->options(fn (): array => Role::query()->orderBy('name')->pluck('name', 'name')->all())
                ->default([]),
            Toggle::make('is_disabled')
                ->label('Disabled')
                ->default(false),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table
            ->modifyQueryUsing(fn (Builder $query) => $query->with('roles'))
            ->columns([
                TextColumn::make('name')->searchable()->sortable(),
                TextColumn::make('email')->searchable()->sortable(),
                TextColumn::make('roles_summary')
                    ->label('Roles')
                    ->state(fn (User $record): string => $record->roles->pluck('name')->join(', ')),
                IconColumn::make('is_disabled')->label('Disabled')->boolean(),
                TextColumn::make('two_factor_confirmed_at')
                    ->label('2FA')
                    ->state(fn (User $record): string => $record->two_factor_confirmed_at !== null ? 'Enabled' : 'Not enrolled'),
                TextColumn::make('last_login_at')
                    ->label('Last login')
                    ->since()
                    ->placeholder('Never'),
            ])
            ->actions([
                EditAction::make(),
                Action::make('resetTwoFactor')
                    ->label('Reset 2FA')
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => $record->id !== Auth::id())
                    ->action(function (User $record): void {
                        $actor = Auth::user();

                        abort_unless($actor instanceof User, 403);

                        app(UserAdminService::class)->resetTwoFactor($record, $actor);

                        Notification::make()->title('Two-factor enrollment reset')->success()->send();
                    }),
                Action::make('sendPasswordReset')
                    ->label('Send password reset')
                    ->visible(fn (User $record): bool => $record->id !== Auth::id())
                    ->action(function (User $record): void {
                        $actor = Auth::user();

                        abort_unless($actor instanceof User, 403);

                        app(UserAdminService::class)->sendPasswordResetLink($record, $actor);

                        Notification::make()->title('Password reset link sent')->success()->send();
                    }),
                Action::make('disableUser')
                    ->label('Disable user')
                    ->color('danger')
                    ->requiresConfirmation()
                    ->visible(fn (User $record): bool => ! $record->is_disabled && $record->id !== Auth::id())
                    ->action(function (User $record): void {
                        $actor = Auth::user();

                        abort_unless($actor instanceof User, 403);

                        app(UserAdminService::class)->disable($record, $actor);

                        Notification::make()->title('User disabled')->success()->send();
                    }),
                Action::make('enableUser')
                    ->label('Enable user')
                    ->visible(fn (User $record): bool => $record->is_disabled)
                    ->action(function (User $record): void {
                        $actor = Auth::user();

                        abort_unless($actor instanceof User, 403);

                        app(UserAdminService::class)->enable($record, $actor);

                        Notification::make()->title('User enabled')->success()->send();
                    }),
            ])
            ->paginated([25, 50, 100]);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListUsers::route('/'),
            'create' => CreateUser::route('/create'),
            'edit' => EditUser::route('/{record}/edit'),
        ];
    }
}
