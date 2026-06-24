<?php

use App\Filament\Resources\SecurityContainerResource;
use App\Filament\Resources\SecurityEventResource;
use App\Filament\Resources\SoftwareSystemResource;
use App\Models\Enums\EventType;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('renders the alert detail infolist for a user with alerts.view', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Plan']);

    $event = SecurityEvent::factory()->create([
        'title' => 'Test infolist alert',
        'type' => EventType::Secret,
    ]);

    $this->actingAs($user)
        ->get(SecurityEventResource::getUrl('view', ['record' => $event]))
        ->assertOk()
        ->assertSee('Test infolist alert')
        ->assertSee('Alert Summary');
});

it('shows type-specific Secret Details section for secret events', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Plan']);

    $event = SecurityEvent::factory()->create([
        'type' => EventType::Secret,
        'metadata' => ['detector' => 'GitLeaks'],
    ]);

    $this->actingAs($user)
        ->get(SecurityEventResource::getUrl('view', ['record' => $event]))
        ->assertOk()
        ->assertSee('Secret Details')
        ->assertSee('GitLeaks');
});

it('shows type-specific Dependency Details section for dependency events', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Plan']);

    $event = SecurityEvent::factory()->create([
        'type' => EventType::Dependency,
        'metadata' => ['package' => ['name' => 'lodash', 'version' => '4.17.20', 'ecosystem' => 'npm']],
    ]);

    $this->actingAs($user)
        ->get(SecurityEventResource::getUrl('view', ['record' => $event]))
        ->assertOk()
        ->assertSee('Dependency Details')
        ->assertSee('lodash 4.17.20');
});

it('shows Code Location section for vulnerability events', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Plan']);

    $event = SecurityEvent::factory()->create([
        'type' => EventType::Vulnerability,
        'file_path' => 'src/auth/login.php',
        'start_line' => 42,
    ]);

    $this->actingAs($user)
        ->get(SecurityEventResource::getUrl('view', ['record' => $event]))
        ->assertOk()
        ->assertSee('Code Location')
        ->assertSee('src/auth/login.php');
});

it('shows Posture section for misconfiguration events', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Plan']);

    $event = SecurityEvent::factory()->create([
        'type' => EventType::Misconfiguration,
        'metadata' => ['resourceType' => 'S3Bucket'],
    ]);

    $this->actingAs($user)
        ->get(SecurityEventResource::getUrl('view', ['record' => $event]))
        ->assertOk()
        ->assertSee('Posture')
        ->assertSee('S3Bucket');
});

it('shows System and Container as hyperlinks in the Alert Summary section', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Plan']);

    $system = SoftwareSystem::factory()->create(['name' => 'Acme Platform']);
    $container = SecurityContainer::factory()->forSystem($system)->create(['name' => 'api-service']);

    $event = SecurityEvent::factory()->forContainer($container)->create([
        'type' => EventType::Vulnerability,
    ]);

    $systemUrl = SoftwareSystemResource::getUrl('view', ['record' => $system]);
    $containerUrl = SecurityContainerResource::getUrl('view', ['record' => $container]);

    $this->actingAs($user)
        ->get(SecurityEventResource::getUrl('view', ['record' => $event]))
        ->assertOk()
        ->assertSee('Acme Platform')
        ->assertSee('api-service')
        ->assertSee($systemUrl, false)
        ->assertSee($containerUrl, false);
});
