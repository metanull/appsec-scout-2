<?php

use App\Filament\Pages\PackagesPage;
use App\Filament\Pages\Support\PackageListQuery;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
});

/**
 * @param  array<string, mixed>  $attributes
 */
function packageComponent(SecurityContainer $container, string $name, string $version, string $ecosystem = 'npm', array $attributes = []): void
{
    $container->softwareComponents()->create([
        'name' => $name,
        'version' => $version,
        'ecosystem' => $ecosystem,
        'purl' => "pkg:{$ecosystem}/{$name}@{$version}",
        'software_system_id' => $container->software_system_id,
        ...$attributes,
    ]);
}

it('aggregates every component row of a package into one row', function () {
    $systemA = SoftwareSystem::factory()->create();
    $systemB = SoftwareSystem::factory()->create();
    $containerA = SecurityContainer::factory()->forSystem($systemA)->create();
    $containerB = SecurityContainer::factory()->forSystem($systemA)->create();
    $containerC = SecurityContainer::factory()->forSystem($systemB)->create();

    packageComponent($containerA, 'lodash', '4.17.20', attributes: ['last_seen_at' => now()->subDays(3)]);
    packageComponent($containerB, 'lodash', '4.17.21', attributes: ['last_seen_at' => now()->subDay()]);
    packageComponent($containerC, 'lodash', '4.17.21', attributes: ['removed_at' => now()]);

    $page = app(PackageListQuery::class)->paginate(null, null, null, null, 1, 25);

    expect($page->total())->toBe(1);

    $row = $page->items()[0];

    expect($row['name'])->toBe('lodash')
        ->and($row['ecosystem'])->toBe('npm')
        ->and($row['usages_count'])->toBe(3)
        ->and($row['versions_count'])->toBe(2)
        ->and($row['systems_count'])->toBe(2)
        ->and($row['active_usages_count'])->toBe(2)
        ->and($row['latest_seen_at']?->toDateString())->toBe(now()->subDay()->toDateString());
});

it('keeps the same name in different ecosystems as separate packages', function () {
    $container = SecurityContainer::factory()->create();

    packageComponent($container, 'yaml', '1.0.0', 'npm');
    packageComponent($container, 'yaml', '6.0.1', 'pypi');

    expect(app(PackageListQuery::class)->paginate(null, null, null, null, 1, 25)->total())->toBe(2);
});

it('paginates by package rather than by component row', function () {
    $containers = SecurityContainer::factory()->count(4)->create();

    foreach (['alpha', 'bravo', 'charlie'] as $name) {
        foreach ($containers as $container) {
            packageComponent($container, $name, '1.0.0');
        }
    }

    $first = app(PackageListQuery::class)->paginate(null, null, null, null, 1, 2);
    $second = app(PackageListQuery::class)->paginate(null, null, null, null, 2, 2);

    expect($first->total())->toBe(3)
        ->and(array_column($first->items(), 'name'))->toBe(['alpha', 'bravo'])
        ->and(array_column($second->items(), 'name'))->toBe(['charlie']);
});

it('searches, filters and sorts packages', function () {
    $container = SecurityContainer::factory()->create();
    $other = SecurityContainer::factory()->create();

    packageComponent($container, 'Apollo.Client.Core', '2.0.1', 'nuget');
    packageComponent($other, 'Apollo.Client.Core', '3.0.1', 'nuget');
    packageComponent($container, 'Apollo.Helpers.String', '1.0.2', 'nuget', ['removed_at' => now()]);
    packageComponent($container, 'left-pad', '1.3.0', 'npm');

    $query = app(PackageListQuery::class);

    expect(array_column($query->paginate('apollo', null, null, null, 1, 25)->items(), 'name'))
        ->toBe(['Apollo.Client.Core', 'Apollo.Helpers.String'])
        ->and(array_column($query->paginate(null, null, null, ['ecosystem' => ['value' => 'npm']], 1, 25)->items(), 'name'))
        ->toBe(['left-pad'])
        ->and(array_column($query->paginate(null, null, null, ['removed' => ['value' => true]], 1, 25)->items(), 'name'))
        ->toBe(['Apollo.Helpers.String'])
        ->and(array_column($query->paginate(null, 'usages_count', 'desc', null, 1, 25)->items(), 'name')[0])
        ->toBe('Apollo.Client.Core')
        ->and(array_column($query->paginate(null, 'not-a-column', 'desc', null, 1, 25)->items(), 'name')[0])
        ->toBe('left-pad');
});

it('renders the packages page and links each row to the filtered dependencies list', function () {
    $user = User::factory()->create();
    $user->syncRoles(['Reader']);

    packageComponent(SecurityContainer::factory()->create(), 'lodash', '4.17.21');

    $component = Livewire::actingAs($user)
        ->test(PackagesPage::class)
        ->assertOk()
        ->assertSee('lodash');

    $record = $component->instance()->getTableRecords()->first();
    $url = urldecode($component->instance()->getTable()->getRecordUrl($record));

    expect($url)->toContain('filters[package][name]=lodash')
        ->toContain('filters[package][ecosystem]=npm');
});

it('denies the packages page without the alerts.view permission', function () {
    $user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $user->syncRoles([]);

    $this->actingAs($user);

    expect(PackagesPage::canAccess())->toBeFalse();

    $this->get(PackagesPage::getUrl())->assertForbidden();
});
