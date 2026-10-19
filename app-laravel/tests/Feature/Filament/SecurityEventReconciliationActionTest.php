<?php

use App\Credentials\Vault;
use App\Filament\Resources\SecurityEventResource;
use App\Filament\Resources\SecurityEventResource\Pages\ViewSecurityEvent;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\WorkItemLink;
use App\Trackers\Dto\ProjectDto;
use App\Trackers\Dto\ReconciliationCandidateDto;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;
use Tests\Fakes\FakeTracker;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    // A system credential alone must never let a user-triggered reconciliation search.
    app(Vault::class)->set('fake-tracker.token', null, 'system-token');
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

it('creates a new work-item link with the operator own tracker credential', function () {
    $plan = reconciliationFilamentUser(['Plan']);
    app(Vault::class)->set('fake-tracker.token', $plan->id, 'user-token');

    $tracker = bindFakeWorkItemTracker((new FakeTracker)->withReconciliationCandidates(
        actionCandidate('APP#500', 'https://tracker.test/APP%23500'),
    ));

    $event = SecurityEvent::factory()->create(['url' => 'https://tracker.test/APP%23500']);

    Livewire::actingAs($plan)
        ->test(ViewSecurityEvent::class, ['record' => $event->getRouteKey()])
        ->callAction('reconcileWorkItems')
        ->assertNotified('1 new work item links found');

    expect($tracker->reconciliationTokens)->toBe(['user-token'])
        ->and(WorkItemLink::query()
            ->where('event_id', $event->id)
            ->where('tracker_id', 'fake-tracker')
            ->where('work_item_id', 'APP#500')
            ->exists())->toBeTrue();
});

it('keeps links unchanged when per-event reconciliation finds no new matches', function () {
    $plan = reconciliationFilamentUser(['Plan']);
    app(Vault::class)->set('fake-tracker.token', $plan->id, 'user-token');

    bindFakeWorkItemTracker((new FakeTracker)->withReconciliationCandidates(
        actionCandidate('APP#404', 'https://tracker.test/APP%23999'),
    ));

    $event = SecurityEvent::factory()->create(['url' => 'https://tracker.test/APP%23404']);

    Livewire::actingAs($plan)
        ->test(ViewSecurityEvent::class, ['record' => $event->getRouteKey()])
        ->callAction('reconcileWorkItems');

    expect(WorkItemLink::query()->where('event_id', $event->id)->count())->toBe(0);
});

it('refuses to search with system credentials when the operator has no tracker credentials', function () {
    $plan = reconciliationFilamentUser(['Plan']);

    $tracker = bindFakeWorkItemTracker((new FakeTracker)->withReconciliationCandidates(
        actionCandidate('APP#500', 'https://tracker.test/APP%23500'),
    ));

    $event = SecurityEvent::factory()->create(['url' => 'https://tracker.test/APP%23500']);

    Livewire::actingAs($plan)
        ->test(ViewSecurityEvent::class, ['record' => $event->getRouteKey()])
        ->callAction('reconcileWorkItems')
        ->assertNotified('Reconciliation failed');

    expect($tracker->reconciliationCalls)->toBe(0)
        ->and(WorkItemLink::query()->where('event_id', $event->id)->count())->toBe(0);
});

it('surfaces a clear notification instead of a raw error when the tracker call fails', function () {
    $plan = reconciliationFilamentUser(['Plan']);
    app(Vault::class)->set('fake-tracker.token', $plan->id, 'user-token');

    bindFakeWorkItemTracker((new FakeTracker)->withReconciliationFailure());

    $event = SecurityEvent::factory()->create(['url' => 'https://tracker.test/APP%23500']);

    Livewire::actingAs($plan)
        ->test(ViewSecurityEvent::class, ['record' => $event->getRouteKey()])
        ->callAction('reconcileWorkItems')
        ->assertNotified('Reconciliation failed');

    expect(WorkItemLink::query()->where('event_id', $event->id)->count())->toBe(0);
});

function actionCandidate(string $workItemId, string $referencedUrl): ReconciliationCandidateDto
{
    return new ReconciliationCandidateDto(
        trackerId: 'fake-tracker',
        workItemId: $workItemId,
        workItemUrl: 'https://tracker.test/' . rawurlencode($workItemId),
        title: 'Issue ' . $workItemId,
        state: 'Open',
        labels: ['security'],
        extractedUrls: [$referencedUrl],
        searchStrategy: 'labels=security',
        project: new ProjectDto(key: 'APP', name: 'APP'),
    );
}

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
