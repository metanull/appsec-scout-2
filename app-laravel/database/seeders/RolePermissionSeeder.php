<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolePermissionSeeder extends Seeder
{
    private const PERMISSIONS = [
        'alerts.view',
        'alerts.edit',
        'alerts.bulk-edit',
        'work-items.create',
        'work-items.link',
        'work-items.sync',
        'sources.push-state',
        'admin.users',
        'admin.system-pats',
        'admin.queue',
        'admin.audit',
        'admin.errors',
        'admin.integrations',
        'admin.repository-providers',
        'context.curate',
        'exports.create',
    ];

    private const ROLE_PERMISSIONS = [
        'Reader' => [
            'alerts.view',
            'exports.create',
        ],
        'Triage' => [
            'alerts.view',
            'alerts.edit',
            'alerts.bulk-edit',
            'exports.create',
        ],
        'Plan' => [
            'alerts.view',
            'alerts.edit',
            'alerts.bulk-edit',
            'exports.create',
            'work-items.create',
            'work-items.link',
            'admin.repository-providers',
            'context.curate',
        ],
        'Sync' => [
            'alerts.view',
            'alerts.edit',
            'alerts.bulk-edit',
            'exports.create',
            'work-items.create',
            'work-items.link',
            'admin.repository-providers',
            'context.curate',
            'work-items.sync',
            'sources.push-state',
        ],
        'Admin' => [
            'alerts.view',
            'alerts.edit',
            'alerts.bulk-edit',
            'exports.create',
            'work-items.create',
            'work-items.link',
            'admin.repository-providers',
            'context.curate',
            'work-items.sync',
            'sources.push-state',
            'admin.users',
            'admin.system-pats',
            'admin.queue',
            'admin.audit',
            'admin.errors',
            'admin.integrations',
        ],
    ];

    public function run(): void
    {
        $this->createPermissions();
        $this->createRolesWithPermissions();
        app(PermissionRegistrar::class)->clearPermissionsCollection();
    }

    private function createPermissions(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    private function createRolesWithPermissions(): void
    {
        foreach (self::ROLE_PERMISSIONS as $roleName => $permissions) {
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($permissions);
        }
    }
}
