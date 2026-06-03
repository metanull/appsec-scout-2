<?php

use App\Filament\Resources\SecurityContainerLinkResource;
use App\Filament\Resources\SecurityContainerLinkResource\RelationManagers\EventsRelationManager;
use App\Filament\Resources\SecurityContainerLinkResource\RelationManagers\MembersRelationManager;
use App\Models\SecurityContainer;
use App\Models\SecurityContainerLink;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('registers virtual container pages and relation managers', function () {
    expect(SecurityContainerLinkResource::getPages())
        ->toHaveKeys(['index', 'create', 'view', 'edit']);

    expect(SecurityContainerLinkResource::getRelations())
        ->toContain(MembersRelationManager::class, EventsRelationManager::class);
});

it('allows reader users to browse but not mutate virtual containers', function () {
    $reader = enrolledVirtualContainerUser(['Reader']);
    $link = SecurityContainerLink::factory()->create(['name' => 'Reader Browse Link']);

    $this->actingAs($reader);

    expect(SecurityContainerLinkResource::canViewAny())->toBeTrue()
        ->and(SecurityContainerLinkResource::canCreate())->toBeFalse()
        ->and(SecurityContainerLinkResource::canEdit($link))->toBeFalse()
        ->and(SecurityContainerLinkResource::canDelete($link))->toBeFalse();

    $this->get(SecurityContainerLinkResource::getUrl('index'))->assertOk();
    $this->get(SecurityContainerLinkResource::getUrl('view', ['record' => $link]))->assertOk();
});

it('allows plan users to create maintain and audit virtual containers', function () {
    $plan = enrolledVirtualContainerUser(['Plan']);
    $this->actingAs($plan);

    expect(SecurityContainerLinkResource::canViewAny())->toBeTrue()
        ->and(SecurityContainerLinkResource::canCreate())->toBeTrue();

    $link = SecurityContainerLink::query()->create([
        'name' => 'Virtual App Cluster',
        'description' => 'Cross-source virtual container group',
    ]);

    $link->update(['description' => 'Updated virtual container group']);

    $container = SecurityContainer::factory()->create();

    $link->addMember($container, 1);
    $link->reorderMember($container, 4);
    $link->removeMember($container);
    $link->delete();

    $actions = DB::table('audit_logs')
        ->whereIn('action', [
            'security_container_link_created',
            'security_container_link_updated',
            'security_container_link_deleted',
            'security_container_link_member_added',
            'security_container_link_member_reordered',
            'security_container_link_member_removed',
        ])
        ->pluck('action')
        ->all();

    expect($actions)
        ->toContain('security_container_link_created')
        ->toContain('security_container_link_updated')
        ->toContain('security_container_link_member_added')
        ->toContain('security_container_link_member_reordered')
        ->toContain('security_container_link_member_removed')
        ->toContain('security_container_link_deleted');
});

function enrolledVirtualContainerUser(array $roles): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);

    $user->syncRoles($roles);

    return $user;
}
