<?php

namespace App\Http\Middleware;

use Closure;
use Filament\Auth\MultiFactor\Http\Middleware\EnsureMultiFactorAuthenticationIsEnabled;
use Illuminate\Http\Request;

/**
 * The panel's required-MFA middleware, with one exemption: a session that was
 * authenticated through the Entra callback already passed the IdP's own MFA
 * (Conditional Access), so the local TOTP wall applies only to
 * password-authenticated sessions. The waiver is keyed on the session marker —
 * never on the user record — so a linked user signing in by password still
 * hits the wall.
 */
class EnsureMultiFactorAuthenticationIsEnabledForLocalSessions extends EnsureMultiFactorAuthenticationIsEnabled
{
    public function handle(Request $request, Closure $next): mixed
    {
        if ($request->session()->get('entra.authenticated') === true) {
            return $next($request);
        }

        return parent::handle($request, $next);
    }
}
