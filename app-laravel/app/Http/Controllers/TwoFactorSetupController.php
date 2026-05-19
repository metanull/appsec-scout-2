<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;

class TwoFactorSetupController extends Controller
{
    public function show(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        if ($user->two_factor_confirmed_at !== null) {
            return redirect()->intended('/');
        }

        if ($user->two_factor_secret === null) {
            app(EnableTwoFactorAuthentication::class)($user);
            $user->refresh();
        }

        return view('auth.two-factor-setup', [
            'qrCodeSvg' => $user->twoFactorQrCodeSvg(),
            'secretKey' => decrypt($user->two_factor_secret),
        ]);
    }

    public function confirm(Request $request): RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        $request->validate(['code' => ['required', 'string']]);

        app(ConfirmTwoFactorAuthentication::class)($user, $request->input('code'));

        return redirect()->route('two-factor.setup.recovery-codes');
    }

    public function recoveryCodes(Request $request): View|RedirectResponse
    {
        $user = $request->user();

        if ($user === null) {
            return redirect()->route('login');
        }

        if ($user->two_factor_confirmed_at === null) {
            return redirect()->route('two-factor.setup');
        }

        return view('auth.two-factor-recovery-codes', [
            'recoveryCodes' => json_decode(decrypt($user->two_factor_recovery_codes), true),
        ]);
    }
}
