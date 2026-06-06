<?php

use App\Audit\AuditLog;
use App\CuratedLinks\CuratedLinkService;
use App\Filament\Resources\SecurityContainerResource\Pages\ViewSecurityContainer;
use App\Filament\Resources\SecurityEventResource\Pages\ViewSecurityEvent;
use App\Filament\Resources\Shared\RelationManagers\CuratedLinksRelationManager;
use App\Filament\Resources\SoftwareSystemResource\Pages\ViewSoftwareSystem;
use App\Models\CuratedLink;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('stores curated links on supported owners', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Plan']);

    $system = SoftwareSystem::factory()->create([
        'name' => 'Acme App',
    ]);

    $container = SecurityContainer::factory()->forSystem($system)->create([
        'name' => 'Acme Repo',
    ]);

    $event = SecurityEvent::factory()->forContainer($container)->create([
        'title' => 'Alert with curated links',
    ]);

    $eventLink = app(CuratedLinkService::class)->create($event, $user, [
        'label' => 'Team wiki',
        'kind' => 'remediation',
        'url' => 'https://docs.example.com/events/team-wiki',
    ]);

    $systemLink = app(CuratedLinkService::class)->create($system, $user, [
        'label' => 'Service overview',
        'kind' => 'source',
        'url' => 'https://docs.example.com/systems/acme-app',
    ]);

    $containerLink = app(CuratedLinkService::class)->create($container, $user, [
        'label' => 'Repository playbook',
        'kind' => 'code',
        'url' => 'https://docs.example.com/repositories/acme-repo',
    ]);

    expect($event->curatedLinks()->count())->toBe(1)
        ->and($system->curatedLinks()->count())->toBe(1)
        ->and($container->curatedLinks()->count())->toBe(1)
        ->and($eventLink->load(['owner', 'createdBy'])->owner)->toBeInstanceOf(SecurityEvent::class)
        ->and($systemLink->load(['owner', 'createdBy'])->owner)->toBeInstanceOf(SoftwareSystem::class)
        ->and($containerLink->load(['owner', 'createdBy'])->owner)->toBeInstanceOf(SecurityContainer::class)
        ->and($eventLink->createdBy)->toBeInstanceOf(User::class)
        ->and($systemLink->createdBy)->toBeInstanceOf(User::class)
        ->and($containerLink->createdBy)->toBeInstanceOf(User::class);

    expect(AuditLog::query()->where('action', 'curated_link_created')->count())->toBe(3);
});

it('rejects invalid curated links', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Plan']);

    $event = SecurityEvent::factory()->create();

    expect(fn () => app(CuratedLinkService::class)->create($event, $user, [
        'label' => '',
        'kind' => 'source',
        'url' => 'https://docs.example.com/invalid',
    ]))->toThrow(ValidationException::class);

    expect(fn () => app(CuratedLinkService::class)->create($event, $user, [
        'label' => 'Unsafe',
        'kind' => 'source',
        'url' => 'javascript:alert(1)',
    ]))->toThrow(ValidationException::class);

    expect($event->curatedLinks()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'curated_link_created')->exists())->toBeFalse();
});

it('shows curated links on alert system and container pages for readers', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Reader']);

    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create();
    $event = SecurityEvent::factory()->forContainer($container)->create();

    foreach ([
        [$event, ViewSecurityEvent::class],
        [$system, ViewSoftwareSystem::class],
        [$container, ViewSecurityContainer::class],
    ] as [$ownerRecord, $pageClass]) {
        Livewire::actingAs($user)
            ->test(CuratedLinksRelationManager::class, [
                'ownerRecord' => $ownerRecord,
                'pageClass' => $pageClass,
            ])
            ->call('loadTable')
            ->assertSee('Curated links')
            ->assertDontSee('Add curated link');
    }
});

it('allows plan users to edit and delete curated links and records audit entries', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles(['Plan']);

    $event = SecurityEvent::factory()->create();

    $link = app(CuratedLinkService::class)->create($event, $user, [
        'label' => 'Original wiki',
        'kind' => 'source',
        'url' => 'https://docs.example.com/original-wiki',
    ]);

    Livewire::actingAs($user)
        ->test(CuratedLinksRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => ViewSecurityEvent::class,
        ])
        ->call('loadTable')
        ->assertSee('Add curated link')
        ->callTableAction('edit', $link, data: [
            'label' => 'Updated wiki',
            'kind' => 'code',
            'url' => 'https://docs.example.com/updated-wiki',
        ])
        ->mountTableAction('delete', $link);

    // Mounting the delete action should not remove the link (confirmation step)
    expect(CuratedLink::query()->whereKey($link->id)->exists())->toBeTrue();

    Livewire::actingAs($user)
        ->test(CuratedLinksRelationManager::class, [
            'ownerRecord' => $event,
            'pageClass' => ViewSecurityEvent::class,
        ])
        ->call('loadTable')
        ->callTableAction('delete', $link);

    expect(CuratedLink::query()->whereKey($link->id)->exists())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'curated_link_updated')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'curated_link_deleted')->exists())->toBeTrue();
});
