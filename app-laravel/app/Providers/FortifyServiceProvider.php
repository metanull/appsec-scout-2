<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Fortify;

class FortifyServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->configureRateLimiters();
        Fortify::authenticateUsing(function (Request $request): ?User {
            $user = User::query()->where('email', $request->string('email')->lower()->toString())->first();

            if (! $user instanceof User || ! Hash::check($request->string('password')->toString(), $user->password)) {
                return null;
            }

            if ($user->is_disabled) {
                throw ValidationException::withMessages([
                    Fortify::username() => 'Your account is disabled. Contact an administrator.',
                ]);
            }

            return $user;
        });
        Fortify::loginView(fn () => view('auth.login'));
        Fortify::twoFactorChallengeView(fn () => view('auth.two-factor-challenge'));
    }

    private function configureRateLimiters(): void
    {
        RateLimiter::for('login', function (Request $request) {
            $key = Str::transliterate(
                Str::lower($request->input(Fortify::username())) . '|' . $request->ip(),
            );

            return Limit::perMinute(5)->by($key);
        });

        RateLimiter::for('two-factor', function (Request $request) {
            return Limit::perMinute(5)->by((string) $request->session()->get('login.id'));
        });
    }
}
