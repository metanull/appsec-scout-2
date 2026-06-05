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
        'inference.review',
        'context.curate',
        'triage.run-trivy',
        'triage.run-bfg',
        'triage.run-codesearch',
    ];

    private const ROLE_PERMISSIONS = [
        'Reader' => ['alerts.view'],
        'Triage' => ['alerts.edit', 'alerts.bulk-edit', 'triage.run-trivy', 'triage.run-bfg', 'triage.run-codesearch'],
        'Plan' => ['work-items.create', 'work-items.link', 'admin.repository-providers', 'inference.review', 'context.curate'],
        'Sync' => ['work-items.sync', 'sources.push-state'],
        'Admin' => [
            'admin.users', 'admin.system-pats', 'admin.queue',
            'admin.audit', 'admin.errors', 'admin.integrations',
        ],
    ];

    public function run(): void
    {
        $this->createPermissions();
        $this->createRolesWithCumulativePermissions();
        app(PermissionRegistrar::class)->clearPermissionsCollection();
    }

    private function createPermissions(): void
    {
        foreach (self::PERMISSIONS as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }

    private function createRolesWithCumulativePermissions(): void
    {
        $accumulated = [];

        foreach (self::ROLE_PERMISSIONS as $roleName => $ownPermissions) {
            $accumulated = array_merge($accumulated, $ownPermissions);
            $role = Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
            $role->syncPermissions($accumulated);
        }
    }
}
