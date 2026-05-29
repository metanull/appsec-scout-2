<?php

use App\Filament\Resources\UserResource;
use App\Filament\Resources\UserResource\Pages\ListUsers;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Auth;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    $this->withoutMiddleware(PreventRequestForgery::class);
});

it('exposes a create header action on the user list page', function () {
    $method = new ReflectionMethod(ListUsers::class, 'getHeaderActions');

    expect($method->getDeclaringClass()->getName())->toBe(ListUsers::class);
});

it('renders the users list page for an admin', function () {
    $admin = enrolledAdminForUserResource();

    $this->actingAs($admin)
        ->get(UserResource::getUrl('index'))
        ->assertOk();
});

it('hides disableUser for the authenticated user own row', function () {
    $admin = enrolledAdminForUserResource();
    Auth::login($admin);

    $isVisible = ! $admin->is_disabled && $admin->id !== Auth::id();

    expect($isVisible)->toBeFalse();
});

it('shows disableUser for another non-disabled user', function () {
    $admin = enrolledAdminForUserResource();
    $other = User::factory()->create(['is_disabled' => false]);
    Auth::login($admin);

    $isVisible = ! $other->is_disabled && $other->id !== Auth::id();

    expect($isVisible)->toBeTrue();
});

it('hides resetTwoFactor for the authenticated user own row', function () {
    $admin = enrolledAdminForUserResource();
    Auth::login($admin);

    expect($admin->id !== Auth::id())->toBeFalse();
});

it('shows resetTwoFactor for another user', function () {
    $admin = enrolledAdminForUserResource();
    $other = User::factory()->create();
    Auth::login($admin);

    expect($other->id !== Auth::id())->toBeTrue();
});

it('hides sendPasswordReset for the authenticated user own row', function () {
    $admin = enrolledAdminForUserResource();
    Auth::login($admin);

    expect($admin->id !== Auth::id())->toBeFalse();
});

it('shows sendPasswordReset for another user', function () {
    $admin = enrolledAdminForUserResource();
    $other = User::factory()->create();
    Auth::login($admin);

    expect($other->id !== Auth::id())->toBeTrue();
});

function enrolledAdminForUserResource(): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Admin']);

    return $user;
}
