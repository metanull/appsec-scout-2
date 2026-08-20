<?php

namespace App\Filament\Pages\Auth;

use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Auth\Pages\Login as BaseLogin;

class Login extends BaseLogin
{
    /** @return array<Action | ActionGroup> */
    protected function getFormActions(): array
    {
        return [
            $this->getAuthenticateFormAction(),
            ...((bool) config('entra.enabled') ? [$this->getEntraFormAction()] : []),
        ];
    }

    protected function getEntraFormAction(): Action
    {
        return Action::make('entra')
            ->label('Sign in with Microsoft')
            ->color('gray')
            ->url(route('entra.redirect'));
    }
}
