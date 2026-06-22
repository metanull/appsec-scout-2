<?php

use Database\Seeders\RolePermissionSeeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('creates all five roles', function () {
    expect(Role::pluck('name')->toArray())
        ->toContain('Reader', 'Triage', 'Plan', 'Sync', 'Admin');
});

it('creates all permissions', function () {
    $permissionNames = Permission::pluck('name')->toArray();

    foreach ([
        'alerts.view', 'alerts.edit', 'alerts.bulk-edit',
        'work-items.create', 'work-items.link', 'work-items.sync',
        'sources.push-state',
        'admin.users', 'admin.system-pats', 'admin.queue', 'admin.audit', 'admin.errors', 'admin.integrations',
        'triage.run-codesearch',
    ] as $permission) {
        expect($permissionNames)->toContain($permission);
    }
});

it('Reader role has only view permission', function () {
    $reader = Role::findByName('Reader');
    expect($reader->permissions->pluck('name')->toArray())->toEqual(['alerts.view']);
});

it('Triage role inherits Reader permissions cumulatively', function () {
    $triage = Role::findByName('Triage');
    $permissions = $triage->permissions->pluck('name')->toArray();

    expect($permissions)->toContain('alerts.view', 'alerts.edit', 'alerts.bulk-edit');
});

it('Admin role has all permissions', function () {
    $admin = Role::findByName('Admin');
    $adminCount = $admin->permissions->count();
    $totalCount = Permission::count();

    expect($adminCount)->toBe($totalCount);
});

it('seeder is idempotent on re-run', function () {
    (new RolePermissionSeeder)->run();
    (new RolePermissionSeeder)->run();

    expect(Role::count())->toBe(5)
        ->and(Permission::count())->toBe(16);
});
