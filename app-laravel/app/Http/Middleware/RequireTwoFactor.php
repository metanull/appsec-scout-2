<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Symfony\Component\HttpFoundation\Response;

class RequireTwoFactor
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::user();

        if ($user === null) {
            return $next($request);
        }

        if (! $this->usesTwoFactor($user)) {
            return $next($request);
        }

        if ($this->hasTwoFactorEnabled($user) && ! $this->isTwoFactorConfirmed($request)) {
            return redirect()->route('two-factor.login');
        }

        if (! $this->hasTwoFactorEnabled($user)) {
            return redirect()->route('two-factor.setup');
        }

        return $next($request);
    }

    private function usesTwoFactor(mixed $user): bool
    {
        return in_array(TwoFactorAuthenticatable::class, class_uses_recursive($user), true);
    }

    private function hasTwoFactorEnabled(mixed $user): bool
    {
        return $user->two_factor_secret !== null;
    }

    private function isTwoFactorConfirmed(Request $request): bool
    {
        return $request->session()->has('auth.two_factor_confirmed');
    }
}
