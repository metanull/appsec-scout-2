<?php

use App\Filament\Resources\SecurityEventResource;
use App\Models\EventAttachment;
use App\Models\SecurityEvent;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('renders attachments and inline sarif rows on the alert detail page', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Plan']);

    $event = SecurityEvent::factory()->create();

    EventAttachment::query()->create([
        'event_id' => $event->id,
        'kind' => 'trivy-sarif',
        'mime' => 'application/sarif+json',
        'name' => 'trivy-results.sarif',
        'payload' => json_encode([
            'runs' => [[
                'results' => [[
                    'ruleId' => 'TRIVY-001',
                    'level' => 'high',
                    'locations' => [[
                        'physicalLocation' => [
                            'artifactLocation' => ['uri' => 'src/Auth.php'],
                            'region' => ['startLine' => 12, 'snippet' => ['text' => 'secret = env("APP_KEY")']],
                        ],
                    ]],
                ]],
            ]],
        ], JSON_THROW_ON_ERROR),
        'size_bytes' => 256,
        'created_at' => now(),
        'created_by_user_id' => $user->id,
        'created_by_command' => 'triage:trivy',
    ]);

    $this->actingAs($user)
        ->get(SecurityEventResource::getUrl('view', ['record' => $event]))
        ->assertOk()
        ->assertSee('trivy-results.sarif')
        ->assertSee('TRIVY-001')
        ->assertSee('src/Auth.php:12')
        ->assertSee('<span style="color:', false)
        ->assertSee('Run Trivy')
        ->assertSee('Run BFG')
        ->assertSee('Run Code Search');
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
