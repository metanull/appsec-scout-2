<?php

use App\Filament\Resources\LocalFindingResource\Pages\ViewLocalFinding;
use App\Filament\Resources\SecurityEventResource\Pages\ViewSecurityEvent;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();

    $this->reader = User::factory()->create();
    $this->reader->syncRoles(['Reader']);
});

it('groups the alert detail content into tabs', function () {
    $event = SecurityEvent::factory()->create(['type' => EventType::Vulnerability]);

    Livewire::actingAs($this->reader)
        ->test(ViewSecurityEvent::class, ['record' => $event->getKey()])
        ->assertSchemaComponentVisible('summary')
        ->assertSchemaComponentVisible('remediation')
        ->assertSchemaComponentVisible('raw-evidence')
        ->assertSee('Summary')
        ->assertSee('Remediation')
        ->assertSee('Raw evidence');
});

it('shows the Pending Sync banner only while the alert has pending changes', function () {
    $pending = SecurityEvent::factory()->create([
        'is_dirty' => true,
        'pending_state' => EventState::Dismissed,
    ]);
    $clean = SecurityEvent::factory()->create([
        'is_dirty' => false,
        'pending_state' => null,
        'pending_severity' => null,
    ]);

    Livewire::actingAs($this->reader)
        ->test(ViewSecurityEvent::class, ['record' => $pending->getKey()])
        ->assertSchemaComponentVisible('pending-sync')
        ->assertSee('Local changes not yet pushed to the source.');

    Livewire::actingAs($this->reader)
        ->test(ViewSecurityEvent::class, ['record' => $clean->getKey()])
        ->assertSchemaComponentHidden('pending-sync');
});

it('shows only the type-specific tab matching the alert type', function () {
    $secret = SecurityEvent::factory()->create(['type' => EventType::Secret]);
    $vulnerability = SecurityEvent::factory()->create(['type' => EventType::Vulnerability]);

    Livewire::actingAs($this->reader)
        ->test(ViewSecurityEvent::class, ['record' => $secret->getKey()])
        ->assertSchemaComponentVisible('secret-details')
        ->assertSchemaComponentHidden('code-location')
        ->assertSchemaComponentHidden('dependency-details')
        ->assertSchemaComponentHidden('posture');

    Livewire::actingAs($this->reader)
        ->test(ViewSecurityEvent::class, ['record' => $vulnerability->getKey()])
        ->assertSchemaComponentVisible('code-location')
        ->assertSchemaComponentHidden('secret-details');
});

it('groups the local finding detail content into tabs', function () {
    $container = SecurityContainer::factory()->create();
    $finding = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'github-pat',
        'title' => 'GitHub PAT committed',
        'file_path' => 'config.php',
    ]);

    Livewire::actingAs($this->reader)
        ->test(ViewLocalFinding::class, ['record' => $finding->getKey()])
        ->assertSchemaComponentVisible('finding')
        ->assertSchemaComponentVisible('problem')
        ->assertSchemaComponentVisible('raw-result')
        ->assertSee('Finding')
        ->assertSee('Problem')
        ->assertSee('Raw result');
});
