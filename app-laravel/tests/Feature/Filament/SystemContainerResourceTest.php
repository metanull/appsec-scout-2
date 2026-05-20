<?php

use App\Filament\Resources\SecurityContainerResource;
use App\Filament\Resources\SecurityContainerResource\RelationManagers\EventsRelationManager as ContainerEventsRelationManager;
use App\Filament\Resources\SoftwareSystemResource;
use App\Filament\Resources\SoftwareSystemResource\RelationManagers\ContainersRelationManager;
use App\Filament\Resources\SoftwareSystemResource\RelationManagers\EventsRelationManager;
use App\Filament\Resources\SoftwareSystemResource\RelationManagers\LinksRelationManager;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\SoftwareSystemLink;

it('registers relation managers for software systems', function () {
    expect(SoftwareSystemResource::getRelations())
        ->toContain(EventsRelationManager::class, ContainersRelationManager::class, LinksRelationManager::class);
});

it('registers relation managers for security containers', function () {
    expect(SecurityContainerResource::getRelations())
        ->toContain(ContainerEventsRelationManager::class);
});

it('loads related events containers and links for software system view', function () {
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create();
    SecurityEvent::factory()->forSystem($system)->forContainer($container)->create();

    $link = SoftwareSystemLink::factory()->create();
    $system->links()->attach($link->id, ['sort_order' => 1]);

    expect($system->events()->count())->toBe(1)
        ->and($system->containers()->count())->toBe(1)
        ->and($system->links()->count())->toBe(1);
});

it('loads related events for container view', function () {
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create();
    SecurityEvent::factory()->forSystem($system)->forContainer($container)->count(2)->create();

    expect($container->events()->count())->toBe(2);
});
