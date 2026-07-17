<?php

use App\Credentials\Vault;
use App\Filament\Resources\LocalFindingResource;
use App\Filament\Resources\LocalFindingResource\Pages\ListLocalFindings;
use App\Filament\Resources\LocalFindingResource\Pages\ViewLocalFinding;
use App\Filament\Resources\LocalFindingResource\RelationManagers\CommentsRelationManager;
use App\Filament\Resources\LocalFindingResource\RelationManagers\WorkItemLinksRelationManager;
use App\Models\Enums\EventType;
use App\Models\LocalFinding;
use App\Models\LocalFindingComment;
use App\Models\LocalFindingWorkItemLink;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Trackers\Dto\ProjectDto;
use App\Trackers\Dto\WorkItemDto;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;
use Tests\Fakes\FakeTracker;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

function localFindingWithSecret(): LocalFinding
{
    $container = SecurityContainer::factory()->create();

    return $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'generic-api-key',
        'title' => 'Hardcoded API key',
        'file_path' => 'config/services.php',
    ]);
}

function localFindingTwoFactorUser(): User
{
    return User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
}

it('hides triage and work item actions from a reader', function () {
    $user = localFindingTwoFactorUser();
    $user->syncRoles(['Reader']);
    $finding = localFindingWithSecret();

    $this->actingAs($user)
        ->get(LocalFindingResource::getUrl('view', ['record' => $finding]))
        ->assertOk()
        ->assertDontSee('Change status')
        ->assertDontSee('Change severity')
        ->assertDontSee('Create work item')
        ->assertDontSee('Link existing');
});

it('shows triage and work item actions to a plan-role operator', function () {
    $user = localFindingTwoFactorUser();
    $user->syncRoles(['Plan']);
    $finding = localFindingWithSecret();

    $this->actingAs($user)
        ->get(LocalFindingResource::getUrl('view', ['record' => $finding]))
        ->assertOk()
        ->assertSee('Change status')
        ->assertSee('Change severity')
        ->assertSee('Create work item')
        ->assertSee('Link existing');
});

it('hides the unlink correlation action when the finding has no correlated alert', function () {
    $user = localFindingTwoFactorUser();
    $user->syncRoles(['Plan']);
    $finding = localFindingWithSecret();

    $this->actingAs($user)
        ->get(LocalFindingResource::getUrl('view', ['record' => $finding]))
        ->assertOk()
        ->assertDontSee('Unlink correlation');
});

it('shows the unlink correlation action to a triage-role operator when the finding is correlated', function () {
    $user = localFindingTwoFactorUser();
    $user->syncRoles(['Triage']);
    $finding = localFindingWithSecret();
    $event = SecurityEvent::factory()->create(['type' => EventType::Secret]);
    $finding->forceFill([
        'correlated_security_event_id' => $event->id,
        'correlation_method' => 'file_line',
    ])->save();

    $this->actingAs($user)
        ->get(LocalFindingResource::getUrl('view', ['record' => $finding]))
        ->assertOk()
        ->assertSee('Unlink correlation');
});

it('clears the correlation when the unlink correlation action is invoked', function () {
    $user = localFindingTwoFactorUser();
    $user->syncRoles(['Triage']);
    $finding = localFindingWithSecret();
    $event = SecurityEvent::factory()->create(['type' => EventType::Secret]);
    $finding->forceFill([
        'correlated_security_event_id' => $event->id,
        'correlation_method' => 'file_line',
    ])->save();

    Livewire::actingAs($user)
        ->test(ViewLocalFinding::class, ['record' => $finding->getRouteKey()])
        ->callAction('unlinkCorrelation');

    expect($finding->fresh()->correlated_security_event_id)->toBeNull()
        ->and($finding->fresh()->correlation_method)->toBeNull();
});

it('renders comments on the local finding comments relation manager', function () {
    $user = localFindingTwoFactorUser();
    $user->syncRoles(['Triage']);
    $finding = localFindingWithSecret();

    LocalFindingComment::query()->create([
        'local_finding_id' => $finding->id,
        'body' => 'Investigated and confirmed local-only usage.',
        'author_user_id' => $user->id,
        'created_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(CommentsRelationManager::class, [
            'ownerRecord' => $finding,
            'pageClass' => ViewLocalFinding::class,
        ])
        ->call('loadTable')
        ->assertSee('Investigated and confirmed local-only usage.');
});

it('hides the grouped work-item bulk actions from a reader', function () {
    $user = localFindingTwoFactorUser();
    $user->syncRoles(['Reader']);

    Livewire::actingAs($user)
        ->test(ListLocalFindings::class)
        ->assertTableBulkActionHidden('createGroupedWorkItem')
        ->assertTableBulkActionHidden('linkExistingWorkItemBulk');
});

it('shows the grouped work-item bulk actions to a plan-role operator', function () {
    $user = localFindingTwoFactorUser();
    $user->syncRoles(['Plan']);

    Livewire::actingAs($user)
        ->test(ListLocalFindings::class)
        ->assertTableBulkActionVisible('createGroupedWorkItem')
        ->assertTableBulkActionVisible('linkExistingWorkItemBulk');
});

it('creates one grouped work item for the selected findings via the bulk action', function () {
    $tracker = bindFakeWorkItemTracker((new FakeTracker)->withProjects(new ProjectDto(key: 'APP', name: 'Application')));
    $user = localFindingTwoFactorUser();
    $user->syncRoles(['Plan']);
    app(Vault::class)->set('fake-tracker.token', $user->id, 'user-token');

    $container = SecurityContainer::factory()->create();
    $findingOne = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET, 'rule_id' => 'generic-api-key', 'title' => 'Hardcoded API key one', 'file_path' => 'config/services.php',
    ]);
    $findingTwo = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET, 'rule_id' => 'generic-api-key', 'title' => 'Hardcoded API key two', 'file_path' => 'config/database.php',
    ]);

    Livewire::actingAs($user)
        ->test(ListLocalFindings::class)
        ->callTableBulkAction('createGroupedWorkItem', [$findingOne, $findingTwo], data: [
            'tracker' => 'fake-tracker',
            'project' => 'APP',
            'item_type' => 'Bug',
        ]);

    $links = LocalFindingWorkItemLink::query()->get();

    expect($tracker->createCalls)->toBe(1)
        ->and($links)->toHaveCount(2)
        ->and($links->pluck('work_item_id')->unique())->toHaveCount(1);
});

it('links all selected findings to an existing work item via the bulk action', function () {
    bindFakeWorkItemTracker((new FakeTracker)
        ->withProjects(new ProjectDto(key: 'APP', name: 'Application'))
        ->withExistingWorkItem(new WorkItemDto(id: 'APP#7', projectKey: 'APP', title: 'Existing tracker item', state: 'Open', url: 'https://tracker.test/APP%237')));
    $user = localFindingTwoFactorUser();
    $user->syncRoles(['Plan']);
    app(Vault::class)->set('fake-tracker.token', $user->id, 'user-token');

    $container = SecurityContainer::factory()->create();
    $findingOne = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET, 'rule_id' => 'generic-api-key', 'title' => 'Hardcoded API key one', 'file_path' => 'config/services.php',
    ]);
    $findingTwo = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET, 'rule_id' => 'generic-api-key', 'title' => 'Hardcoded API key two', 'file_path' => 'config/database.php',
    ]);

    Livewire::actingAs($user)
        ->test(ListLocalFindings::class)
        ->callTableBulkAction('linkExistingWorkItemBulk', [$findingOne, $findingTwo], data: [
            'tracker' => 'fake-tracker',
            'project' => 'APP',
            'selected_work_item' => 'APP#7',
        ]);

    expect(LocalFindingWorkItemLink::query()->where('work_item_id', 'APP#7')->count())->toBe(2);
});

it('rejects the whole bulk link batch when one finding is already linked', function () {
    bindFakeWorkItemTracker((new FakeTracker)
        ->withProjects(new ProjectDto(key: 'APP', name: 'Application'))
        ->withExistingWorkItem(new WorkItemDto(id: 'APP#7', projectKey: 'APP', title: 'Existing tracker item', state: 'Open', url: 'https://tracker.test/APP%237')));
    $user = localFindingTwoFactorUser();
    $user->syncRoles(['Plan']);
    app(Vault::class)->set('fake-tracker.token', $user->id, 'user-token');

    $container = SecurityContainer::factory()->create();
    $findingOne = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET, 'rule_id' => 'generic-api-key', 'title' => 'Hardcoded API key one', 'file_path' => 'config/services.php',
    ]);
    $findingTwo = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET, 'rule_id' => 'generic-api-key', 'title' => 'Hardcoded API key two', 'file_path' => 'config/database.php',
    ]);

    LocalFindingWorkItemLink::query()->create([
        'local_finding_id' => $findingOne->id,
        'tracker_id' => 'fake-tracker',
        'work_item_id' => 'APP#7',
        'work_item_title' => 'Existing tracker item',
    ]);

    Livewire::actingAs($user)
        ->test(ListLocalFindings::class)
        ->callTableBulkAction('linkExistingWorkItemBulk', [$findingOne, $findingTwo], data: [
            'tracker' => 'fake-tracker',
            'project' => 'APP',
            'selected_work_item' => 'APP#7',
        ])
        ->assertNotified('One or more selected findings are already linked to this work item.');

    expect(LocalFindingWorkItemLink::query()->where('local_finding_id', $findingTwo->id)->exists())->toBeFalse()
        ->and(LocalFindingWorkItemLink::query()->count())->toBe(1);
});

it('renders linked work items on the local finding work items relation manager', function () {
    $user = localFindingTwoFactorUser();
    $user->syncRoles(['Plan']);
    $finding = localFindingWithSecret();

    LocalFindingWorkItemLink::query()->create([
        'local_finding_id' => $finding->id,
        'tracker_id' => 'github',
        'work_item_id' => 'octo/app#101',
        'work_item_title' => 'Rotate leaked API key',
        'work_item_state' => 'Open',
        'created_by_user_id' => $user->id,
        'created_at' => now(),
        'synced_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(WorkItemLinksRelationManager::class, [
            'ownerRecord' => $finding,
            'pageClass' => ViewLocalFinding::class,
        ])
        ->call('loadTable')
        ->assertSee('Rotate leaked API key')
        ->assertSee('Unlink');
});
