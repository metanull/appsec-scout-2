<?php

use App\Filament\Resources\SecurityEventResource;
use App\Filament\Resources\SecurityEventResource\RelationManagers\AttachmentsRelationManager;
use App\Models\EventAttachment;
use App\Models\SecurityEvent;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('renders attachments on the alert detail page', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Plan']);

    $event = SecurityEvent::factory()->create();

    EventAttachment::query()->create([
        'event_id' => $event->id,
        'kind' => 'codesearch-json',
        'mime' => 'application/json',
        'name' => 'codesearch-results.json',
        'payload' => json_encode([]),
        'size_bytes' => 256,
        'created_at' => now(),
        'created_by_user_id' => $user->id,
        'created_by_command' => 'triage:codesearch',
    ]);

    // Ensure the alert detail page renders for this operator.
    $this->actingAs($user)
        ->get(SecurityEventResource::getUrl('view', ['record' => $event]))
        ->assertOk();

    // Attachment row data is inside the relation manager — use Livewire::test
    Livewire::actingAs($user)
        ->test(AttachmentsRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => SecurityEventResource\Pages\ViewSecurityEvent::class,
        ])
        ->call('loadTable')
        ->assertSee('codesearch-results.json');
});

it('downloads attachments with the expected headers', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Plan']);

    $event = SecurityEvent::factory()->create();
    $attachment = EventAttachment::query()->create([
        'event_id' => $event->id,
        'kind' => 'codesearch-json',
        'mime' => 'application/json',
        'name' => 'codesearch.json',
        'payload' => '{"count":1}',
        'size_bytes' => 12,
        'created_at' => now(),
    ]);

    $response = $this->actingAs($user)
        ->get(route('alerts.attachments.download', ['event' => $event->id, 'attachment' => $attachment->id]));

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertHeader('Content-Disposition', 'attachment; filename="codesearch.json"')
        ->assertSee('{"count":1}', false);
});
