<?php

use App\Audit\AuditLog;
use App\Filament\Resources\UserResource;
use App\Models\User;
use App\Users\UserAdminService;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    $this->withoutMiddleware(PreventRequestForgery::class);
});

it('creates a user and defaults the role to Reader when none are selected', function () {
    $admin = enrolledAdminForLifecycle();

    $user = app(UserAdminService::class)->create([
        'name' => 'Reader User',
        'email' => 'reader@example.test',
        'password' => 'secret-pass',
        'roles' => [],
    ], $admin);

    expect($user->hasRole('Reader'))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'user.created')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'user.roles_changed')->exists())->toBeTrue();
});

it('updates roles and resets two-factor enrollment with audit rows', function () {
    $admin = enrolledAdminForLifecycle();
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);

    app(UserAdminService::class)->update($user, [
        'name' => $user->name,
        'email' => $user->email,
        'roles' => ['Admin', 'Reader'],
        'is_disabled' => false,
    ], $admin);

    app(UserAdminService::class)->resetTwoFactor($user, $admin);

    $user->refresh();

    expect($user->hasRole('Admin'))->toBeTrue()
        ->and($user->two_factor_secret)->toBeNull()
        ->and($user->two_factor_confirmed_at)->toBeNull()
        ->and(AuditLog::query()->where('action', 'user.roles_changed')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'user.two_factor_reset')->exists())->toBeTrue();
});

it('disables a user, deletes sessions, and blocks subsequent access', function () {
    $admin = enrolledAdminForLifecycle();
    $user = enrolledUserForLifecycle();

    DB::table('sessions')->insert([
        'id' => 'session-one',
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Pest',
        'payload' => 'payload',
        'last_activity' => now()->timestamp,
    ]);

    app(UserAdminService::class)->disable($user, $admin);

    expect(DB::table('sessions')->where('user_id', $user->id)->count())->toBe(0);

    $this->actingAs($user);

    $response = $this->get('/');

    $response->assertRedirect('/user/login');
    $this->assertGuest();
});

it('allows re-enabling a disabled user', function () {
    $admin = enrolledAdminForLifecycle();
    $user = User::factory()->create([
        'is_disabled' => true,
        'disabled_at' => now(),
    ]);

    app(UserAdminService::class)->enable($user, $admin);

    $user->refresh();

    expect($user->is_disabled)->toBeFalse()
        ->and($user->disabled_at)->toBeNull()
        ->and(AuditLog::query()->where('action', 'user.enabled')->exists())->toBeTrue();
});

it('sends a password reset link and records an audit row', function () {
    Notification::fake();

    $admin = enrolledAdminForLifecycle();
    $user = User::factory()->create();

    app(UserAdminService::class)->sendPasswordResetLink($user, $admin);

    Notification::assertSentTo($user, ResetPassword::class);

    expect(AuditLog::query()->where('action', 'user.password_reset_link_sent')->exists())->toBeTrue();
});

it('denies login for disabled users with a clear validation error', function () {
    $user = User::factory()->create([
        'email' => 'disabled@example.test',
        'password' => 'password',
        'is_disabled' => true,
        'disabled_at' => now(),
    ]);

    $response = $this->from('/user/login')->post('/user/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect('/user/login')
        ->assertSessionHasErrors('email');

    $this->assertGuest();
});

it('tracks last_login_at on successful login', function () {
    $user = User::factory()->create([
        'email' => 'login@example.test',
        'password' => 'password',
    ]);

    $response = $this->post('/user/login', [
        'email' => $user->email,
        'password' => 'password',
    ]);

    $response->assertRedirect('/');

    $this->get('/')->assertRedirect(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());

    expect($user->fresh()?->last_login_at)->not->toBeNull();
});

it('bootstraps the first admin and refuses when a user already exists', function () {
    $this->artisan('appsec:bootstrap-admin', [
        '--name' => 'Bootstrap Admin',
        '--email' => 'bootstrap@example.test',
        '--password' => 'secret-pass',
    ])->assertSuccessful();

    $bootstrapped = User::query()->first();

    expect($bootstrapped)->not->toBeNull()
        ->and($bootstrapped?->hasRole('Admin'))->toBeTrue()
        ->and(AuditLog::query()->where('action', 'user.bootstrap_admin')->exists())->toBeTrue();

    $this->artisan('appsec:bootstrap-admin', [
        '--name' => 'Another Admin',
        '--email' => 'another@example.test',
        '--password' => 'secret-pass',
    ])->assertFailed();

    $this->artisan('appsec:bootstrap-admin', [
        '--if-missing' => true,
        '--name' => 'Skipped Admin',
        '--email' => 'skipped@example.test',
        '--password' => 'secret-pass',
    ])->assertSuccessful();
});

it('authorizes the admin user resource only for admins', function () {
    $reader = enrolledUserForLifecycle();
    $this->actingAs($reader);

    expect(UserResource::canViewAny())->toBeFalse();

    $admin = enrolledAdminForLifecycle();
    $this->actingAs($admin);

    expect(UserResource::canViewAny())->toBeTrue();
});

function enrolledAdminForLifecycle(): User
{
    $user = enrolledUserForLifecycle();
    $user->syncRoles(['Admin']);

    return $user;
}

function enrolledUserForLifecycle(): User
{
    return User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
}
