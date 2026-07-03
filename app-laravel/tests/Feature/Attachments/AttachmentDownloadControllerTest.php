<?php

use App\Assets\AttachmentService;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('downloads an attachment for its owning software asset', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $asset = SoftwareAsset::factory()->create();
    $attachment = app(AttachmentService::class)->attachTo($asset, 'sbom', 'application/json', 'sbom.json', '{"components":[]}');

    $this->actingAs($user)
        ->get(route('assets.attachments.download', ['asset' => $asset->id, 'attachment' => $attachment->id]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertSee('components');
});

it('returns 404 when the attachment does not belong to the requested software system', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $systemA = SoftwareSystem::factory()->create();
    $systemB = SoftwareSystem::factory()->create();
    $attachment = app(AttachmentService::class)->attachTo($systemA, 'sbom', 'application/json', 'sbom.json', '{}');

    $this->actingAs($user)
        ->get(route('software-systems.attachments.download', ['system' => $systemB->id, 'attachment' => $attachment->id]))
        ->assertNotFound();
});

it('returns 404 when the attachment does not belong to the requested security container', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $system = SoftwareSystem::factory()->create();
    $containerA = SecurityContainer::factory()->forSystem($system)->create();
    $containerB = SecurityContainer::factory()->forSystem($system)->create();
    $attachment = app(AttachmentService::class)->attachTo($containerA, 'dependency-report', 'text/plain', 'report.txt', 'stale packages');

    $this->actingAs($user)
        ->get(route('security-containers.attachments.download', ['container' => $containerB->id, 'attachment' => $attachment->id]))
        ->assertNotFound();
});

it('requires the alerts.view permission', function () {
    $asset = SoftwareAsset::factory()->create();
    $attachment = app(AttachmentService::class)->attachTo($asset, 'sbom', 'application/json', 'sbom.json', '{}');

    $userWithoutPermission = User::factory()->create();
    $userWithoutPermission->syncRoles([]);

    $this->actingAs($userWithoutPermission)
        ->get(route('assets.attachments.download', ['asset' => $asset->id, 'attachment' => $attachment->id]))
        ->assertForbidden();
});
