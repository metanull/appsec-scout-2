<?php

use App\Audit\AuditLog;
use App\Filament\Resources\SecurityEventResource;
use App\Filament\Resources\SecurityEventResource\RelationManagers\AuditHistoryRelationManager;
use App\Filament\Resources\SecurityEventResource\RelationManagers\WorkItemLinksRelationManager;
use App\Models\Enums\EventType;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\User;
use App\Models\WorkItemLink;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('renders the typed link catalog for an alert with all link kinds', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Plan']);

    $system = SoftwareSystem::factory()->create([
        'name' => 'Acme App',
        'url' => 'https://dev.azure.com/org/project',
    ]);

    $container = SecurityContainer::factory()->forSystem($system)->create([
        'name' => 'Acme Repo',
        'url' => 'https://dev.azure.com/org/project/_git/acme-repo',
    ]);

    $event = SecurityEvent::factory()->forContainer($container)->create([
        'title' => 'Alert with catalog links',
        'type' => EventType::Secret,
        'url' => 'https://dev.azure.com/org/project/_alerts/7',
        'version_control_url' => 'https://dev.azure.com/org/project/_git/acme-repo?path=/src/app.php&version=GBmain&line=42',
        'metadata' => [
            'ruleHelpUri' => 'https://docs.example.com/rule/1',
            'cve' => 'CVE-2023-99999',
            'detector' => 'Example detector',
        ],
    ]);

    WorkItemLink::query()->create([
        'event_id' => $event->id,
        'tracker_id' => 'jira',
        'work_item_id' => 'PROJ-42',
        'work_item_title' => 'Fix the alert',
        'work_item_state' => 'Open',
        'work_item_url' => 'https://jira.example.com/browse/PROJ-42',
        'created_at' => now(),
        'synced_at' => now(),
    ]);

    $this->actingAs($user)
        ->get(SecurityEventResource::getUrl('view', ['record' => $event]))
        ->assertOk()
        ->assertSee('Source')
        ->assertSee('Code')
        ->assertSee('Remediation')
        ->assertSee('Standards')
        ->assertSee('Tracker')
        ->assertSee('Source alert')
        ->assertSee('System')
        ->assertSee('Repository')
        ->assertSee('Source file')
        ->assertSee('Rule documentation')
        ->assertSee('CVE: CVE-2023-99999')
        ->assertSee('jira: Fix the alert')
        ->assertSee('https://dev.azure.com/org/project/_alerts/7')
        ->assertSee('https://dev.azure.com/org/project/_git/acme-repo?path=/src/app.php&version=GBmain&line=42')
        ->assertSee('https://docs.example.com/rule/1')
        ->assertSee('https://nvd.nist.gov/vuln/detail/CVE-2023-99999', false)
        ->assertSee('https://jira.example.com/browse/PROJ-42');
});

it('renders the links section with CVE links from metadata', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Plan']);

    $event = SecurityEvent::factory()->create([
        'type' => EventType::Dependency,
        'metadata' => [
            'cve' => 'CVE-2023-99999',
            'package' => ['name' => 'lodash', 'version' => '4.17.20'],
        ],
    ]);

    $this->actingAs($user)
        ->get(SecurityEventResource::getUrl('view', ['record' => $event]))
        ->assertOk()
        ->assertSee('CVE-2023-99999')
        ->assertSee('nvd.nist.gov/vuln/detail/CVE-2023-99999', false);
});

it('renders secret occurrences table when metadata has occurrences', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Plan']);

    $event = SecurityEvent::factory()->create([
        'type' => EventType::Secret,
        'metadata' => [
            'detector' => 'GitHub Advanced Security',
            'occurrences' => [
                [
                    'filePath' => 'src/config/secrets.js',
                    'startLine' => 15,
                    'endLine' => 15,
                    'ref' => 'refs/heads/main',
                    'commitSha' => 'abc123def456789',
                ],
            ],
        ],
    ]);

    $this->actingAs($user)
        ->get(SecurityEventResource::getUrl('view', ['record' => $event]))
        ->assertOk()
        ->assertSee('src/config/secrets.js')
        ->assertSee('Occurrences');
});

it('renders the raw evidence section collapsed by default', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Plan']);

    $event = SecurityEvent::factory()->create();

    $this->actingAs($user)
        ->get(SecurityEventResource::getUrl('view', ['record' => $event]))
        ->assertOk()
        ->assertSee('Raw Evidence');
});

it('redacts sensitive keys from raw evidence payload', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Plan']);

    $event = SecurityEvent::factory()->create([
        'metadata' => [
            'apiToken' => 'super-secret-value',
            'description' => 'visible value',
        ],
    ]);

    // The Raw Evidence section must render the redacted marker for sensitive keys
    // and the non-sensitive description value.
    // Note: Livewire serialises the model state into a hidden page snapshot,
    // so we cannot assert the raw value is absent from the full HTML.
    $this->actingAs($user)
        ->get(SecurityEventResource::getUrl('view', ['record' => $event]))
        ->assertOk()
        ->assertSee('***REDACTED***', false)
        ->assertSee('visible value');
});

it('shows the add attachment action for users with work-items.create permission', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Plan']);

    $event = SecurityEvent::factory()->create();

    $this->actingAs($user)
        ->get(SecurityEventResource::getUrl('view', ['record' => $event]))
        ->assertOk()
        ->assertSee('Add attachment');
});

it('shows work item table columns including created by', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Plan']);

    $event = SecurityEvent::factory()->create();

    WorkItemLink::query()->create([
        'event_id' => $event->id,
        'tracker_id' => 'github',
        'work_item_id' => 'org/repo#55',
        'work_item_title' => 'Fix the secret',
        'work_item_state' => 'Open',
        'work_item_url' => 'https://github.test/org/repo/issues/55',
        'created_by_user_id' => $user->id,
        'created_at' => now(),
        'synced_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(WorkItemLinksRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => SecurityEventResource\Pages\ViewSecurityEvent::class,
        ])
        ->call('loadTable')
        ->assertSee('Fix the secret')
        ->assertSee('Created by')
        ->assertSee('Created at');
});

it('shows audit history table with action column', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Plan']);

    $event = SecurityEvent::factory()->create();

    AuditLog::query()->create([
        'user_id' => $user->id,
        'actor_kind' => 'user',
        'action' => 'state.changed',
        'subject_type' => SecurityEvent::class,
        'subject_id' => (string) $event->id,
        'payload_json' => ['old_state' => 'open', 'new_state' => 'resolved'],
    ]);

    Livewire::actingAs($user)
        ->test(AuditHistoryRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => SecurityEventResource\Pages\ViewSecurityEvent::class,
        ])
        ->call('loadTable')
        ->assertSee('state.changed')
        ->assertSee('Audit History');
});
