<?php

use App\Filament\Resources\SecurityEventResource;
use App\Filament\Resources\SecurityEventResource\Pages\ViewSecurityEvent;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\WorkItemLink;
use App\Trackers\Dto\ReconciliationCandidateDto;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;
use Tests\Fakes\FakeTracker;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('shows per-event reconciliation action to plan users but hides it for readers', function () {
    $reader = reconciliationFilamentUser(['Reader']);
    $plan = reconciliationFilamentUser(['Plan']);
    $event = SecurityEvent::factory()->create();

    $this->actingAs($reader)
        ->get(SecurityEventResource::getUrl('view', ['record' => $event]))
        ->assertOk()
        ->assertDontSee('Find existing work items');

    $this->actingAs($plan)
        ->get(SecurityEventResource::getUrl('view', ['record' => $event]))
        ->assertOk()
        ->assertSee('Find existing work items');
});

it('creates a new work-item link when per-event reconciliation finds matches', function () {
    $plan = reconciliationFilamentUser(['Plan']);

    $tracker = (new FakeTracker)->withReconciliationCandidates('APP', new ReconciliationCandidateDto(
        trackerId: 'fake-tracker',
        workItemId: 'APP#500',
        workItemUrl: 'https://tracker.test/APP%23500',
        title: 'Matched issue',
        state: 'Open',
        labels: ['security'],
        extractedUrls: ['https://tracker.test/APP%23500'],
        searchStrategy: 'project=APP',
    ));
    bindFakeWorkItemTracker($tracker);

    $event = SecurityEvent::factory()->create([
        'url' => 'https://tracker.test/APP%23500',
    ]);

    $event->softwareSystem->trackerProjectLinks()->create([
        'tracker_id' => 'fake-tracker',
        'project_key' => 'APP',
        'project_name' => 'APP',
        'is_default' => false,
        'created_by_user_id' => $plan->id,
        'metadata' => null,
    ]);

    Livewire::actingAs($plan)
        ->test(ViewSecurityEvent::class, ['record' => $event->getRouteKey()])
        ->callAction('reconcileWorkItems');

    expect(WorkItemLink::query()
        ->where('event_id', $event->id)
        ->where('tracker_id', 'fake-tracker')
        ->where('work_item_id', 'APP#500')
        ->exists())->toBeTrue();
});

it('keeps links unchanged when per-event reconciliation finds no new matches', function () {
    $plan = reconciliationFilamentUser(['Plan']);

    $tracker = (new FakeTracker)->withReconciliationCandidates('APP', new ReconciliationCandidateDto(
        trackerId: 'fake-tracker',
        workItemId: 'APP#404',
        workItemUrl: 'https://tracker.test/APP%23404',
        title: 'Unrelated issue',
        state: 'Open',
        labels: ['security'],
        extractedUrls: ['https://tracker.test/APP%23999'],
        searchStrategy: 'project=APP',
    ));
    bindFakeWorkItemTracker($tracker);

    $event = SecurityEvent::factory()->create([
        'url' => 'https://tracker.test/APP%23404',
    ]);

    $event->softwareSystem->trackerProjectLinks()->create([
        'tracker_id' => 'fake-tracker',
        'project_key' => 'APP',
        'project_name' => 'APP',
        'is_default' => false,
        'created_by_user_id' => $plan->id,
        'metadata' => null,
    ]);

    Livewire::actingAs($plan)
        ->test(ViewSecurityEvent::class, ['record' => $event->getRouteKey()])
        ->callAction('reconcileWorkItems');

    expect(WorkItemLink::query()->where('event_id', $event->id)->count())->toBe(0);
});

function reconciliationFilamentUser(array $roles): User
{
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);

    $user->syncRoles($roles);

    return $user;
}
