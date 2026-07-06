<?php

use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SoftwareComponent;
use App\Models\SoftwareSystem;

it('rolls up software components and local findings from every child container', function () {
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create();
    $unrelatedContainer = SecurityContainer::factory()->create();

    SoftwareComponent::query()->create([
        'owner_type' => SecurityContainer::class,
        'owner_id' => $container->id,
        'software_system_id' => $system->id,
        'name' => 'Jinja2',
        'purl' => 'pkg:pypi/jinja2@3.1.4',
    ]);
    SoftwareComponent::query()->create([
        'owner_type' => SecurityContainer::class,
        'owner_id' => $unrelatedContainer->id,
        'name' => 'Unrelated',
        'purl' => 'pkg:pypi/unrelated@1.0.0',
    ]);

    LocalFinding::query()->create([
        'owner_type' => SecurityContainer::class,
        'owner_id' => $container->id,
        'software_system_id' => $system->id,
        'kind' => LocalFinding::KIND_VULNERABILITY,
        'rule_id' => 'CVE-2024-56201',
        'title' => 'Jinja sandbox breakout',
        'file_path' => 'requirements.txt',
    ]);

    expect($system->softwareComponents()->pluck('name')->all())->toBe(['Jinja2'])
        ->and($system->localFindings()->pluck('title')->all())->toBe(['Jinja sandbox breakout']);
});
