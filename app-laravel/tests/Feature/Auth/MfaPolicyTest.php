<?php

use App\Assets\AttachmentService;
use App\Models\Attachment;
use App\Models\EventAttachment;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Facades\Filament;

beforeEach(function () {
    withoutDependencyTrackPush();
    (new RolePermissionSeeder)->run();
});

it('waives the TOTP wall for an Entra-authenticated session', function () {
    $user = User::factory()->create([
        'email' => 'federated@example.com',
        'password' => null,
        'entra_object_id' => 'oid-mfa-1',
    ]);
    $user->assignRole('Admin');

    $this->actingAs($user)
        ->withSession(['entra.authenticated' => true])
        ->get('/')
        ->assertSuccessful();
});

it('still walls a password-authenticated session without TOTP, even with Entra enabled', function () {
    config(['entra.enabled' => true]);

    $user = User::factory()->create();
    $user->assignRole('Admin');

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
});

it('still walls a linked user with a password when their session is not Entra-authenticated', function () {
    // A linked account keeps its password; only the Entra-authenticated
    // session gets the waiver, never the user record itself.
    $user = User::factory()->create(['entra_object_id' => 'oid-mfa-2']);
    $user->assignRole('Admin');

    $this->actingAs($user)
        ->get('/')
        ->assertRedirect(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
});

it('leaves TOTP-enrolled local users unaffected', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->assignRole('Admin');

    $this->actingAs($user)
        ->get('/')
        ->assertSuccessful();
});

it('walls a password-authenticated session without TOTP from the download routes', function (Closure $url) {
    $user = User::factory()->create();
    $user->assignRole('Reader');

    $this->actingAs($user)
        ->get($url())
        ->assertRedirect(Filament::getSetUpRequiredMultiFactorAuthenticationUrl());
})->with([
    'alert attachment' => fn (): string => route('alerts.attachments.download', ['event' => ($event = SecurityEvent::factory()->create())->id, 'attachment' => eventAttachment($event)->id]),
    'alert source' => fn (): string => route('alerts.source', ['event' => SecurityEvent::factory()->create(), 'version' => 'current']),
    'asset attachment' => fn (): string => route('assets.attachments.download', ['asset' => ($asset = SoftwareAsset::factory()->create())->id, 'attachment' => sbomAttachment($asset)->id]),
    'system attachment' => fn (): string => route('software-systems.attachments.download', ['system' => ($system = SoftwareSystem::factory()->create())->id, 'attachment' => sbomAttachment($system)->id]),
    'container attachment' => fn (): string => route('security-containers.attachments.download', ['container' => ($container = SecurityContainer::factory()->create())->id, 'attachment' => sbomAttachment($container)->id]),
    'container sbom' => fn (): string => route('security-containers.sbom.download', ['container' => SecurityContainer::factory()->create()->id]),
    'asset sbom zip' => fn (): string => route('assets.sbom.download', ['asset' => SoftwareAsset::factory()->create()->id]),
    'system sbom zip' => fn (): string => route('software-systems.sbom.download', ['system' => SoftwareSystem::factory()->create()->id]),
    'asset findings zip' => fn (): string => route('assets.findings.download', ['asset' => SoftwareAsset::factory()->create()->id]),
    'system findings zip' => fn (): string => route('software-systems.findings.download', ['system' => SoftwareSystem::factory()->create()->id]),
    'container findings zip' => fn (): string => route('security-containers.findings.download', ['container' => SecurityContainer::factory()->create()->id]),
]);

it('lets an Entra-authenticated session download without TOTP', function () {
    $user = User::factory()->create(['password' => null, 'entra_object_id' => 'oid-mfa-3']);
    $user->assignRole('Reader');
    $asset = SoftwareAsset::factory()->create();

    $this->actingAs($user)
        ->withSession(['entra.authenticated' => true])
        ->get(route('assets.attachments.download', ['asset' => $asset->id, 'attachment' => sbomAttachment($asset)->id]))
        ->assertOk();
});

it('lets a TOTP-enrolled local session download', function () {
    $user = User::factory()->withTotp()->create();
    $user->assignRole('Reader');
    $asset = SoftwareAsset::factory()->create();

    $this->actingAs($user)
        ->get(route('assets.attachments.download', ['asset' => $asset->id, 'attachment' => sbomAttachment($asset)->id]))
        ->assertOk();
});

function sbomAttachment(SoftwareAsset|SoftwareSystem|SecurityContainer $owner): Attachment
{
    return app(AttachmentService::class)->attachTo($owner, 'sbom', 'application/json', 'sbom.json', '{"bomFormat":"CycloneDX","components":[]}');
}

function eventAttachment(SecurityEvent $event): EventAttachment
{
    return EventAttachment::query()->create([
        'event_id' => $event->id,
        'kind' => 'codesearch-json',
        'mime' => 'application/json',
        'name' => 'codesearch.json',
        'payload' => '{"count":1}',
        'size_bytes' => 12,
        'created_at' => now(),
    ]);
}
