<?php

use App\Models\User;
use App\Users\UserAdminService;
use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('gives a new user without roles the Reader role', function () {
    $user = User::factory()->create();

    expect($user->fresh()->getRoleNames()->all())->toBe(['Reader']);
});

it('replaces a user\'s roles when an admin updates them', function () {
    $actor = User::factory()->create();
    $user = User::factory()->create();

    $updated = app(UserAdminService::class)->update($user, [
        'name' => $user->name,
        'email' => $user->email,
        'roles' => ['Triage', 'Plan'],
    ], $actor);

    expect($updated->getRoleNames()->sort()->values()->all())->toBe(['Plan', 'Triage'])
        ->and($updated->hasRole('Reader'))->toBeFalse()
        ->and($updated->hasAnyRole(['Plan', 'Admin']))->toBeTrue();
});

it('keeps every role\'s permissions unchanged when the seeder runs again', function () {
    $snapshot = fn (): array => Role::with('permissions')->get()
        ->mapWithKeys(fn (Role $role): array => [$role->name => $role->permissions->pluck('name')->sort()->values()->all()])
        ->all();

    $before = $snapshot();

    (new RolePermissionSeeder)->run();
    (new RolePermissionSeeder)->run();

    expect($snapshot())->toBe($before);
});

it('answers permission checks after the permission cache is reset', function () {
    $admin = User::factory()->create();
    $admin->syncRoles(['Admin']);
    $reader = User::factory()->create();
    $reader->syncRoles(['Reader']);

    $this->artisan('permission:cache-reset')->assertSuccessful();

    expect($admin->fresh()->can('admin.users'))->toBeTrue()
        ->and($reader->fresh()->can('alerts.view'))->toBeTrue()
        ->and($reader->fresh()->can('admin.users'))->toBeFalse();
});
