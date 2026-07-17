<?php

use App\Audit\AuditLog;
use App\Credentials\Vault;
use App\Filament\Resources\LocalFindingResource;
use App\Filament\Resources\LocalFindingResource\Pages\ListLocalFindings;
use App\Filament\Resources\LocalFindingResource\Pages\ViewLocalFinding;
use App\Filament\Resources\LocalFindingResource\Support\LocalFindingTableQuery;
use App\Filament\Support\UserViewStateStore;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\LocalFinding;
use App\Models\LocalFindingWorkItemLink;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use App\Models\User;
use App\Trackers\Dto\ProjectDto;
use App\Trackers\Dto\WorkItemDto;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;
use Tests\Fakes\FakeTracker;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

it('lets readers view the local finding explorer', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $this->actingAs($user);

    expect(LocalFindingResource::canViewAny())->toBeTrue();
});

it('denies access without the alerts.view permission', function () {
    $user = User::factory()->create();
    $user->syncRoles([]);

    $this->actingAs($user);

    expect(LocalFindingResource::canViewAny())->toBeFalse();
});

it('lists a finding with the asset, system, and container columns', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $asset = SoftwareAsset::factory()->create(['name' => 'Payments Platform']);
    $system = SoftwareSystem::factory()->create(['software_asset_id' => $asset->id, 'name' => 'payments-service']);
    $container = SecurityContainer::factory()->forSystem($system)->create(['name' => 'payments-api']);

    $finding = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY,
        'rule_id' => 'CVE-2024-56201',
        'title' => 'Jinja sandbox breakout',
        'severity' => 'MEDIUM',
        'file_path' => 'requirements.txt',
        'start_line' => 8,
        'package_name' => 'Jinja2',
        'package_version' => '3.1.4',
        'software_system_id' => $system->id,
        'software_asset_id' => $asset->id,
    ]);

    Livewire::actingAs($user)
        ->test(ListLocalFindings::class)
        ->assertCanSeeTableRecords([$finding])
        ->assertSee('Jinja sandbox breakout')
        ->assertCanRenderTableColumn('softwareAsset.name')
        ->assertCanRenderTableColumn('softwareSystem.name')
        ->assertCanRenderTableColumn('_container')
        ->assertTableColumnStateSet('softwareAsset.name', 'Payments Platform', $finding)
        ->assertTableColumnStateSet('softwareSystem.name', 'payments-service', $finding)
        ->assertTableColumnStateSet('_container', 'payments-api', $finding);

    expect(LocalFindingResource::getUrl('view', ['record' => $finding]))->toBeString();
});

it('renders the tracker and first seen columns on the findings list', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $container = SecurityContainer::factory()->create();

    $findingWithLink = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'github-pat',
        'title' => 'Linked finding',
        'file_path' => 'config.php',
        'first_seen_at' => now()->subDays(3),
    ]);
    $findingWithLink->workItemLinks()->create([
        'tracker_id' => 'github',
        'work_item_id' => 'octo/app#7',
        'work_item_title' => 'Rotate the key',
        'work_item_state' => 'In Progress',
        'created_at' => now(),
        'synced_at' => now(),
    ]);

    $findingWithoutLink = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'generic-api-key',
        'title' => 'Unlinked finding',
        'file_path' => 'services.php',
    ]);

    Livewire::actingAs($user)
        ->test(ListLocalFindings::class)
        ->assertCanRenderTableColumn('work_item_state')
        ->assertTableColumnExists('first_seen_at')
        ->assertTableColumnStateSet('work_item_state', 'In Progress', $findingWithLink)
        ->assertTableColumnStateSet('work_item_state', null, $findingWithoutLink);
});

it('shows the finding detail page including the correlated alert link', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    $event = SecurityEvent::factory()->create();
    $container = SecurityContainer::factory()->create();

    $finding = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'github-pat',
        'title' => 'GitHub PAT committed',
        'file_path' => 'config.php',
        'correlated_security_event_id' => $event->id,
    ]);

    Livewire::actingAs($user)
        ->test(ViewLocalFinding::class, ['record' => $finding->getKey()])
        ->assertSee('GitHub PAT committed')
        ->assertSee('#' . $event->id);
});

it('orders findings by effective severity rank by default, respecting overrides', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);
    $container = SecurityContainer::factory()->create();

    $criticalOverride = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r1', 'title' => 'critical override',
        'file_path' => 'a.txt', 'severity' => 'HIGH', 'overridden_severity' => EventSeverity::Critical,
        'last_seen_at' => now(),
    ]);
    $plainHigh = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r2', 'title' => 'plain high',
        'file_path' => 'b.txt', 'severity' => 'HIGH', 'last_seen_at' => now()->subMinute(),
    ]);
    $medium = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r3', 'title' => 'plain medium',
        'file_path' => 'c.txt', 'severity' => 'MEDIUM', 'last_seen_at' => now()->subMinutes(2),
    ]);
    $lowOverride = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r4', 'title' => 'low override',
        'file_path' => 'd.txt', 'severity' => 'CRITICAL', 'overridden_severity' => EventSeverity::Low,
        'last_seen_at' => now()->subMinutes(3),
    ]);
    $low = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r5', 'title' => 'plain low',
        'file_path' => 'e.txt', 'severity' => 'LOW', 'last_seen_at' => now()->subMinutes(4),
    ]);

    Livewire::actingAs($user)
        ->test(ListLocalFindings::class)
        ->assertCanSeeTableRecords([$criticalOverride, $plainHigh, $medium, $lowOverride, $low], inOrder: true);
});

it('sorts findings by last seen and by location on explicit user sort', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);
    $container = SecurityContainer::factory()->create();

    $older = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r1', 'title' => 'older',
        'file_path' => 'zeta.txt', 'severity' => 'LOW', 'last_seen_at' => now()->subDays(5),
    ]);
    $newer = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r2', 'title' => 'newer',
        'file_path' => 'alpha.txt', 'severity' => 'LOW', 'last_seen_at' => now(),
    ]);

    Livewire::actingAs($user)
        ->test(ListLocalFindings::class)
        ->sortTable('last_seen_at', 'asc')
        ->assertCanSeeTableRecords([$older, $newer], inOrder: true)
        ->sortTable('file_path', 'asc')
        ->assertCanSeeTableRecords([$newer, $older], inOrder: true);
});

it('lets an explicit user sort override the default severity ordering', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);
    $container = SecurityContainer::factory()->create();

    $critical = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r1', 'title' => 'zzz title',
        'file_path' => 'a.txt', 'severity' => 'CRITICAL', 'last_seen_at' => now(),
    ]);
    $low = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r2', 'title' => 'aaa title',
        'file_path' => 'b.txt', 'severity' => 'LOW', 'last_seen_at' => now()->subDay(),
    ]);

    Livewire::actingAs($user)
        ->test(ListLocalFindings::class)
        ->sortTable('title', 'asc')
        ->assertCanSeeTableRecords([$low, $critical], inOrder: true);
});

it('filters findings by kind and by status', function () {
    $container = SecurityContainer::factory()->create();
    $vuln = $container->localFindings()->create(['kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r1', 'title' => 'v', 'file_path' => 'a', 'status' => EventState::Open]);
    $secret = $container->localFindings()->create(['kind' => LocalFinding::KIND_SECRET, 'rule_id' => 'r2', 'title' => 's', 'file_path' => 'b', 'status' => EventState::Resolved]);

    expect(LocalFindingTableQuery::applyKinds(LocalFinding::query(), [LocalFinding::KIND_SECRET])->pluck('id')->all())->toBe([$secret->id])
        ->and(LocalFindingTableQuery::applyStatuses(LocalFinding::query(), [EventState::Open->value])->pluck('id')->all())->toBe([$vuln->id])
        ->and(LocalFindingTableQuery::applyKinds(LocalFinding::query(), [])->count())->toBe(2);
});

it('filters findings by effective severity, respecting overrides and unknown', function () {
    $container = SecurityContainer::factory()->create();
    $overridden = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r1', 'title' => 'overridden', 'file_path' => 'a',
        'severity' => 'HIGH', 'overridden_severity' => EventSeverity::Critical,
    ]);
    $plainHigh = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r2', 'title' => 'high', 'file_path' => 'b', 'severity' => 'HIGH',
    ]);
    $unknown = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r3', 'title' => 'unknown', 'file_path' => 'c', 'severity' => 'UNKNOWN',
    ]);

    expect(LocalFindingTableQuery::applyEffectiveSeverities(LocalFinding::query(), ['critical'])->pluck('id')->all())->toBe([$overridden->id])
        ->and(LocalFindingTableQuery::applyEffectiveSeverities(LocalFinding::query(), ['high'])->pluck('id')->all())->toBe([$plainHigh->id])
        ->and(LocalFindingTableQuery::applyEffectiveSeverities(LocalFinding::query(), ['unknown'])->pluck('id')->all())->toBe([$unknown->id])
        ->and(LocalFindingTableQuery::applyEffectiveSeverities(LocalFinding::query(), [])->count())->toBe(3);
});

it('filters findings by asset, system, and container scope', function () {
    $assetA = SoftwareAsset::factory()->create();
    $assetB = SoftwareAsset::factory()->create();
    $systemA = SoftwareSystem::factory()->create(['software_asset_id' => $assetA->id]);
    $containerA = SecurityContainer::factory()->forSystem($systemA)->create();
    $containerB = SecurityContainer::factory()->create();

    $inA = $containerA->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r1', 'title' => 'a', 'file_path' => 'a',
        'software_asset_id' => $assetA->id, 'software_system_id' => $systemA->id,
    ]);
    $containerB->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r2', 'title' => 'b', 'file_path' => 'b',
        'software_asset_id' => $assetB->id,
    ]);

    expect(LocalFindingTableQuery::applyAssetScopes(LocalFinding::query(), [(string) $assetA->id])->pluck('id')->all())->toBe([$inA->id])
        ->and(LocalFindingTableQuery::applySystemScopes(LocalFinding::query(), [(string) $systemA->id])->pluck('id')->all())->toBe([$inA->id])
        ->and(LocalFindingTableQuery::applyContainerScopes(LocalFinding::query(), [(string) $containerA->id])->pluck('id')->all())->toBe([$inA->id])
        ->and(LocalFindingTableQuery::applyAssetScopes(LocalFinding::query(), ['0'])->count())->toBe(0);
});

it('container scope does not match findings owned by a non-container owner', function () {
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->create();

    $containerOwned = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r1', 'title' => 'c', 'file_path' => 'a',
    ]);
    LocalFinding::query()->create([
        'owner_type' => SoftwareSystem::class, 'owner_id' => $container->id,
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r2', 'title' => 's', 'file_path' => 'b',
        'software_system_id' => $system->id,
    ]);

    expect(LocalFindingTableQuery::applyContainerScopes(LocalFinding::query(), [(string) $container->id])->pluck('id')->all())->toBe([$containerOwned->id]);
});

it('filters findings by work item presence and by correlation', function () {
    $event = SecurityEvent::factory()->create();
    $container = SecurityContainer::factory()->create();

    $withWorkItem = $container->localFindings()->create(['kind' => LocalFinding::KIND_SECRET, 'rule_id' => 'r1', 'title' => 'w', 'file_path' => 'a']);
    $withWorkItem->workItemLinks()->create(['tracker_id' => 'github', 'work_item_id' => 'octo/app#1', 'created_at' => now(), 'synced_at' => now()]);

    $correlated = $container->localFindings()->create(['kind' => LocalFinding::KIND_SECRET, 'rule_id' => 'r2', 'title' => 'c', 'file_path' => 'b', 'correlated_security_event_id' => $event->id]);
    $container->localFindings()->create(['kind' => LocalFinding::KIND_SECRET, 'rule_id' => 'r3', 'title' => 'n', 'file_path' => 'c']);

    expect(LocalFindingTableQuery::applyHasWorkItem(LocalFinding::query(), true)->pluck('id')->all())->toBe([$withWorkItem->id])
        ->and(LocalFindingTableQuery::applyHasWorkItem(LocalFinding::query(), false)->count())->toBe(2)
        ->and(LocalFindingTableQuery::applyHasWorkItem(LocalFinding::query(), null)->count())->toBe(3)
        ->and(LocalFindingTableQuery::applyIsCorrelated(LocalFinding::query(), true)->pluck('id')->all())->toBe([$correlated->id])
        ->and(LocalFindingTableQuery::applyIsCorrelated(LocalFinding::query(), false)->count())->toBe(2);
});

it('searches findings across description, rule id, package, location, and metadata with escaped like', function () {
    $container = SecurityContainer::factory()->create();

    $byDescription = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r1', 'title' => 'alpha', 'file_path' => 'f1',
        'description' => 'ZZUNIQUEDESC breakout',
    ]);
    $byRule = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'CVE-2099-0001', 'title' => 'beta', 'file_path' => 'f2',
    ]);
    $byPackage = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r3', 'title' => 'gamma', 'file_path' => 'f3',
        'package_name' => 'left-pad', 'package_version' => '1.3.0',
    ]);
    $byMetadata = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r4', 'title' => 'delta', 'file_path' => 'f4',
        'metadata' => ['cwe' => 'CWE-1337'],
    ]);

    expect(LocalFindingTableQuery::applySearch(LocalFinding::query(), 'ZZUNIQUEDESC')->pluck('id')->all())->toBe([$byDescription->id])
        ->and(LocalFindingTableQuery::applySearch(LocalFinding::query(), 'CVE-2099-0001')->pluck('id')->all())->toBe([$byRule->id])
        ->and(LocalFindingTableQuery::applySearch(LocalFinding::query(), 'left-pad')->pluck('id')->all())->toBe([$byPackage->id])
        ->and(LocalFindingTableQuery::applySearch(LocalFinding::query(), 'CWE-1337')->pluck('id')->all())->toBe([$byMetadata->id])
        ->and(LocalFindingTableQuery::applySearch(LocalFinding::query(), 'f3')->pluck('id')->all())->toBe([$byPackage->id])
        ->and(LocalFindingTableQuery::applySearch(LocalFinding::query(), 'no-such-term')->count())->toBe(0);
});

it('escapes like wildcards in the finding search term', function () {
    $container = SecurityContainer::factory()->create();

    $literal = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r1', 'title' => '100% coverage gap', 'file_path' => 'f1',
    ]);
    $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r2', 'title' => '100 percent done', 'file_path' => 'f2',
    ]);

    expect(LocalFindingTableQuery::applySearch(LocalFinding::query(), '100%')->pluck('id')->all())->toBe([$literal->id]);
});

it('applies the finding search through the list page search box', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);
    $container = SecurityContainer::factory()->create();

    $match = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r1', 'title' => 'searchable finding', 'file_path' => 'f1',
        'package_name' => 'openssl',
    ]);
    $other = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'r2', 'title' => 'unrelated', 'file_path' => 'f2',
    ]);

    Livewire::actingAs($user)
        ->test(ListLocalFindings::class)
        ->set('tableSearch', 'openssl')
        ->assertCanSeeTableRecords([$match])
        ->assertCanNotSeeTableRecords([$other]);
});

it('persists local findings list filters, search, and sort per user and view id', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    Livewire::actingAs($user)
        ->test(ListLocalFindings::class)
        ->set('tableFilters.kind.values', [LocalFinding::KIND_SECRET])
        ->set('tableSearch', 'openssl')
        ->set('tableSort', 'file_path');

    $state = app(UserViewStateStore::class)->load($user->id, 'local-findings:list');

    expect($state['filters']['kind']['values'])->toBe([LocalFinding::KIND_SECRET])
        ->and($state['search'])->toBe('openssl')
        ->and($state['sort'])->toBe('file_path');

    Livewire::actingAs($user)
        ->test(ListLocalFindings::class)
        ->assertSet('tableSearch', 'openssl')
        ->assertSet('tableSort', 'file_path')
        ->assertSet('tableFilters.kind.values', [LocalFinding::KIND_SECRET]);

    $other = User::factory()->create();
    $other->syncRoles(['Reader']);

    Livewire::actingAs($other)
        ->test(ListLocalFindings::class)
        ->assertSet('tableSearch', '');

    expect(app(UserViewStateStore::class)->load($user->id, 'security-events:list'))->toBe([]);
});

it('hides the change status and change severity row actions from a reader on the findings list', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);
    $finding = SecurityContainer::factory()->create()->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'generic-api-key',
        'title' => 'Hardcoded API key',
        'file_path' => 'config/services.php',
    ]);

    Livewire::actingAs($user)
        ->test(ListLocalFindings::class)
        ->assertTableActionHidden('changeStatus', $finding)
        ->assertTableActionHidden('changeSeverity', $finding);
});

it('shows the change status and change severity row actions to a plan-role operator on the findings list', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Plan']);
    $finding = SecurityContainer::factory()->create()->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'generic-api-key',
        'title' => 'Hardcoded API key',
        'file_path' => 'config/services.php',
    ]);

    Livewire::actingAs($user)
        ->test(ListLocalFindings::class)
        ->assertTableActionVisible('changeStatus', $finding)
        ->assertTableActionVisible('changeSeverity', $finding);
});

it('changes the status of a finding via the row action and records an audit entry', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Triage']);
    $finding = SecurityContainer::factory()->create()->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'generic-api-key',
        'title' => 'Hardcoded API key',
        'file_path' => 'config/services.php',
    ]);

    Livewire::actingAs($user)
        ->test(ListLocalFindings::class)
        ->callTableAction('changeStatus', $finding, data: [
            'new_status' => EventState::Resolved->value,
            'comment' => 'Confirmed local-only usage, no risk.',
        ]);

    expect($finding->fresh()->status)->toBe(EventState::Resolved);

    expect(AuditLog::query()
        ->where('subject_type', LocalFinding::class)
        ->where('subject_id', (string) $finding->id)
        ->where('action', 'state_change')
        ->exists())->toBeTrue();
});

it('changes the status of multiple findings via the bulk action', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Triage']);
    $container = SecurityContainer::factory()->create();
    $findingOne = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'generic-api-key',
        'title' => 'Hardcoded API key one',
        'file_path' => 'config/services.php',
    ]);
    $findingTwo = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'generic-api-key',
        'title' => 'Hardcoded API key two',
        'file_path' => 'config/database.php',
    ]);

    Livewire::actingAs($user)
        ->test(ListLocalFindings::class)
        ->callTableBulkAction('changeStatusBulk', [$findingOne, $findingTwo], data: [
            'new_status' => EventState::Dismissed->value,
            'comment' => 'Bulk dismissed as false positives.',
        ]);

    expect($findingOne->fresh()->status)->toBe(EventState::Dismissed)
        ->and($findingTwo->fresh()->status)->toBe(EventState::Dismissed);
});

it('hides the work item row actions from a reader on the findings list', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);
    $finding = SecurityContainer::factory()->create()->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET, 'rule_id' => 'generic-api-key', 'title' => 'Hardcoded API key', 'file_path' => 'config/services.php',
    ]);

    Livewire::actingAs($user)
        ->test(ListLocalFindings::class)
        ->assertTableActionHidden('createWorkItem', $finding)
        ->assertTableActionHidden('linkExistingWorkItem', $finding);
});

it('shows the work item row actions to a plan-role operator on the findings list', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Plan']);
    $finding = SecurityContainer::factory()->create()->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET, 'rule_id' => 'generic-api-key', 'title' => 'Hardcoded API key', 'file_path' => 'config/services.php',
    ]);

    Livewire::actingAs($user)
        ->test(ListLocalFindings::class)
        ->assertTableActionVisible('createWorkItem', $finding)
        ->assertTableActionVisible('linkExistingWorkItem', $finding);
});

it('links an existing work item to a finding via the row action', function () {
    bindFakeWorkItemTracker((new FakeTracker)
        ->withProjects(new ProjectDto(key: 'APP', name: 'Application'))
        ->withExistingWorkItem(new WorkItemDto(
            id: 'APP#7',
            projectKey: 'APP',
            title: 'Existing tracker item',
            state: 'Open',
            url: 'https://tracker.test/APP%237',
        )));

    $user = User::factory()->create();
    $user->syncRoles(['Plan']);
    app(Vault::class)->set('fake-tracker.token', $user->id, 'user-token');

    $finding = SecurityContainer::factory()->create()->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET, 'rule_id' => 'generic-api-key', 'title' => 'Hardcoded API key', 'file_path' => 'config/services.php',
    ]);

    Livewire::actingAs($user)
        ->test(ListLocalFindings::class)
        ->callTableAction('linkExistingWorkItem', $finding, data: [
            'tracker' => 'fake-tracker',
            'project' => 'APP',
            'selected_work_item' => 'APP#7',
        ]);

    expect(LocalFindingWorkItemLink::query()->where('local_finding_id', $finding->id)->where('work_item_id', 'APP#7')->exists())->toBeTrue();
});

it('hides the change status bulk action from a reader on the findings list', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    Livewire::actingAs($user)
        ->test(ListLocalFindings::class)
        ->assertTableBulkActionHidden('changeStatusBulk');
});
