<?php

namespace App\Http\Controllers\Auth;

use App\Audit\Recorder;
use App\Auth\Entra\IdTokenClaims;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use RuntimeException;
use SocialiteProviders\Manager\OAuth2\User as OAuthUser;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirectResponse;

class EntraLoginController
{
    /** openid/profile/email yield the id_token (oid + App Roles); User.Read backs the provider's Graph profile call. */
    private const array SCOPES = ['openid', 'profile', 'email', 'User.Read'];

    public function __construct(private readonly Recorder $auditRecorder) {}

    public function redirect(): SymfonyRedirectResponse
    {
        abort_unless((bool) config('entra.enabled'), 404);

        $provider = Socialite::driver('entra');

        if (! $provider instanceof AbstractProvider) {
            throw new RuntimeException('The entra Socialite driver is not an OAuth2 provider.');
        }

        return $provider->scopes(self::SCOPES)->redirect();
    }

    public function callback(Request $request): RedirectResponse
    {
        abort_unless((bool) config('entra.enabled'), 404);

        if (! $request->filled('code')) {
            return $this->reject('Microsoft sign-in was cancelled or failed. Please try again.');
        }

        try {
            $oauthUser = Socialite::driver('entra')->user();
        } catch (InvalidStateException) {
            return $this->reject('Microsoft sign-in could not be validated. Please try again.');
        }

        if (! $oauthUser instanceof OAuthUser) {
            throw new RuntimeException('The entra Socialite driver returned an unexpected user type.');
        }

        $claims = IdTokenClaims::fromJwt($this->idTokenFrom($oauthUser));
        $email = Str::lower(trim((string) $oauthUser->getEmail()));

        if ($email === '') {
            throw new RuntimeException('Entra sign-in returned no email address (userPrincipalName).');
        }

        $user = $this->resolveUser($claims->objectId, $email, trim((string) $oauthUser->getName()));

        if ($user->is_disabled) {
            return $this->reject('Your account is disabled. Contact an administrator.');
        }

        $this->syncRolesFromClaim($user, $claims->roles);

        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('entra.authenticated', true);

        return redirect()->intended('/');
    }

    private function idTokenFrom(OAuthUser $oauthUser): string
    {
        $idToken = $oauthUser->accessTokenResponseBody['id_token'] ?? null;

        if (! is_string($idToken) || $idToken === '') {
            throw new RuntimeException('Entra token response did not include an id_token; check the requested scopes.');
        }

        return $idToken;
    }

    private function resolveUser(string $objectId, string $email, string $name): User
    {
        $user = User::query()->where('entra_object_id', $objectId)->first();

        if ($user instanceof User) {
            return $user;
        }

        $user = User::query()->where('email', $email)->first();

        if ($user instanceof User) {
            $user->forceFill(['entra_object_id' => $objectId])->save();
            $this->auditRecorder->recordSsoLinked('user', (string) $user->id, ['email' => $email]);

            return $user;
        }

        $user = User::query()->create([
            'name' => $name !== '' ? $name : $email,
            'email' => $email,
            'password' => null,
            'entra_object_id' => $objectId,
        ]);

        $this->auditRecorder->recordSsoProvisioned('user', (string) $user->id, ['email' => $email]);

        return $user;
    }

    /**
     * Federated access is governed by Entra App Roles: on every login the
     * user's Spatie roles are replaced by the claim (unknown names ignored,
     * empty claim clears all roles).
     *
     * @param  list<string>  $claimRoles
     */
    private function syncRolesFromClaim(User $user, array $claimRoles): void
    {
        /** @var list<string> $known */
        $known = Role::query()->where('guard_name', 'web')->pluck('name')->all();
        $target = array_values(array_intersect($known, $claimRoles));
        sort($target);

        $current = $user->getRoleNames()->sort()->values()->all();

        if ($target === $current) {
            return;
        }

        $user->syncRoles($target);
        $this->auditRecorder->recordRolesSyncedFromIdp('user', (string) $user->id, [
            'before' => $current,
            'after' => $target,
        ]);
    }

    private function reject(string $message): RedirectResponse
    {
        Notification::make()->title($message)->danger()->send();

        return redirect()->to(Filament::getDefaultPanel()->getLoginUrl() ?? '/');
    }
}
