<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Traits\HasRoles;

#[Fillable(['name', 'email', 'password', 'is_disabled', 'last_login_at', 'disabled_at'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable;

    public function canAccessPanel(Panel $panel): bool
    {
        return true;
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'is_disabled' => 'boolean',
            'last_login_at' => 'datetime',
            'disabled_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::created(function (self $user) {
            if (! $user->hasAnyRole() && Role::where('name', 'Reader')->where('guard_name', 'web')->exists()) {
                $user->assignRole('Reader');
            }
        });
    }

    public function getAppAuthenticationSecret(): ?string
    {
        if ($this->two_factor_secret === null) {
            return null;
        }

        return decrypt($this->two_factor_secret);
    }

    public function saveAppAuthenticationSecret(?string $secret): void
    {
        $this->two_factor_secret = $secret !== null ? encrypt($secret) : null;
        $this->two_factor_confirmed_at = $secret !== null ? now() : null;
        $this->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /** @return ?array<string> */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        if ($this->two_factor_recovery_codes === null) {
            return null;
        }

        $decoded = json_decode(decrypt($this->two_factor_recovery_codes), true);

        if (! is_array($decoded)) {
            return null;
        }

        /** @var array<string> $codes */
        $codes = array_values(array_filter($decoded, fn (mixed $value): bool => is_string($value)));

        return $codes;
    }

    /** @param ?array<string> $codes */
    public function saveAppAuthenticationRecoveryCodes(?array $codes): void
    {
        $this->two_factor_recovery_codes = is_array($codes)
            ? encrypt(json_encode(array_values($codes)))
            : null;
        $this->save();
    }
}
