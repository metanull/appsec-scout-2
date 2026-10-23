<?php

use App\Filament\Resources\SecurityContainerResource\Pages\ViewSecurityContainer;
use App\Filament\Resources\SoftwareAssetResource\Pages\ViewSoftwareAsset;
use App\Filament\Resources\SoftwareSystemResource\Pages\ViewSoftwareSystem;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Filament\Schemas\Schema;
use Livewire\Livewire;

beforeEach(function () {
    (new RolePermissionSeeder)->run();

    $this->reader = User::factory()->create();
    $this->reader->syncRoles(['Reader']);
});

it('stacks the detail sections in one full-width column', function (string $page, Closure $makeRecord) {
    $record = $makeRecord();

    $schema = Livewire::actingAs($this->reader)
        ->test($page, ['record' => $record->getKey()])
        ->assertOk()
        ->instance()
        ->getSchema('infolist');

    expect($schema)->toBeInstanceOf(Schema::class)
        ->and($schema->getColumns('lg'))->toBe(1);
})->with([
    'software asset' => [ViewSoftwareAsset::class, fn (): SoftwareAsset => SoftwareAsset::factory()->create()],
    'software system' => [ViewSoftwareSystem::class, fn (): SoftwareSystem => SoftwareSystem::factory()->create()],
    'security container' => [ViewSecurityContainer::class, fn (): SecurityContainer => SecurityContainer::factory()->create()],
]);
