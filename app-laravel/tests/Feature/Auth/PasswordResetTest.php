<?php

use App\Filament\Pages\Auth\RequestPasswordReset;
use App\Models\User;
use App\Users\UserAdminService;
use Database\Seeders\RolePermissionSeeder;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Pages\PasswordReset\ResetPassword as ResetPasswordPage;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    $this->withoutMiddleware(PreventRequestForgery::class);
});

it('offers a password reset link on the login page', function () {
    $this->get('/login')
        ->assertSuccessful()
        ->assertSee(route('filament.appsec-scout.auth.password-reset.request'));
});

it('renders the request password reset page for guests', function () {
    $this->get(route('filament.appsec-scout.auth.password-reset.request'))
        ->assertSuccessful();
});

it('sends a reset link with a working Filament reset URL for a password user', function () {
    Notification::fake();

    $user = User::factory()->create();

    Livewire::test(RequestPasswordReset::class)
        ->fillForm(['email' => $user->email])
        ->call('request');

    $url = null;

    Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use (&$url): bool {
        $url = $notification->url;

        return true;
    });

    expect($url)->toBeString()
        ->and($url)->toContain('/password-reset/reset')
        ->and($url)->toContain('signature=');

    $this->get($url)->assertSuccessful();
});

it('resets the password end to end through the Filament reset page', function () {
    $user = User::factory()->create(['password' => 'old-password']);
    $token = Password::broker()->createToken($user);

    Livewire::test(ResetPasswordPage::class, ['email' => $user->email, 'token' => $token])
        ->fillForm([
            'password' => 'brand-new-password',
            'passwordConfirmation' => 'brand-new-password',
        ])
        ->call('resetPassword');

    $hash = User::query()->findOrFail($user->id)->getRawOriginal('password');

    expect($hash)->toBeString()
        ->and(Hash::check('brand-new-password', $hash))->toBeTrue();
});

it('does not send a reset link nor create a token for a federated account, while responding identically', function () {
    Notification::fake();

    $user = User::factory()->create([
        'password' => null,
        'entra_object_id' => 'oid-password-reset',
    ]);

    Livewire::test(RequestPasswordReset::class)
        ->fillForm(['email' => $user->email])
        ->call('request')
        ->assertNotified();

    Notification::assertNothingSent();

    expect(DB::table('password_reset_tokens')->where('email', $user->email)->exists())->toBeFalse();
});

it('refuses the admin reset link for a federated user', function () {
    $admin = User::factory()->create();
    $admin->syncRoles(['Admin']);

    $federated = User::factory()->create([
        'password' => null,
        'entra_object_id' => 'oid-admin-reset',
    ]);

    expect(fn () => app(UserAdminService::class)->sendPasswordResetLink($federated, $admin))
        ->toThrow(RuntimeException::class, 'Federated (Entra) users have no password to reset.');
});
