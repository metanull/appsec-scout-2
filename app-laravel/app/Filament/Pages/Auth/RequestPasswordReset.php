<?php

namespace App\Filament\Pages\Auth;

use App\Models\User;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset as BaseRequestPasswordReset;
use Filament\Facades\Filament;
use Illuminate\Auth\Events\PasswordResetLinkSent;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Support\Facades\Password;
use SensitiveParameter;

/**
 * Filament's stock request page, with one addition: federated (Entra) accounts have no
 * password hash, so a request for one responds exactly like a successful send — without
 * creating a reset token or sending mail — to avoid leaking the account type.
 */
class RequestPasswordReset extends BaseRequestPasswordReset
{
    public function request(): void
    {
        try {
            $this->rateLimit(2);
        } catch (TooManyRequestsException $exception) {
            $this->getRateLimitedNotification($exception)?->send();

            return;
        }

        $data = $this->form->getState();

        if ($this->isFederatedAccount((string) $data['email'])) {
            $this->getSentNotification(Password::RESET_LINK_SENT)?->send();

            $this->form->fill();

            return;
        }

        $status = Password::broker(Filament::getAuthPasswordBroker())->sendResetLink(
            $this->getCredentialsFromFormData($data),
            function (CanResetPassword $user, #[SensitiveParameter] string $token): void {
                $panel = Filament::getCurrentOrDefaultPanel();

                if (! $user instanceof User || $panel === null || ! $user->canAccessPanel($panel)) {
                    return;
                }

                $notification = app(ResetPasswordNotification::class, ['token' => $token]);
                $notification->url = Filament::getResetPasswordUrl($token, $user);

                $user->notify($notification);

                event(new PasswordResetLinkSent($user));
            },
        );

        if ($status !== Password::RESET_LINK_SENT) {
            $this->getFailureNotification($status)?->send();

            return;
        }

        $this->getSentNotification($status)?->send();

        $this->form->fill();
    }

    private function isFederatedAccount(string $email): bool
    {
        $user = User::query()->where('email', mb_strtolower(trim($email)))->first();

        return $user !== null && $user->getRawOriginal('password') === null;
    }
}
