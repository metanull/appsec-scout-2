<?php

use App\Assets\AzDoOwnerLookup;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;

it('resolves both ids when the system and container already exist', function () {
    $system = SoftwareSystem::factory()->create(['source_id' => 'azdo', 'source_system_id' => 'project-001']);
    $container = SecurityContainer::factory()->create([
        'software_system_id' => $system->id,
        'source_container_id' => 'repo-001',
    ]);

    $result = (new AzDoOwnerLookup)->forAzDoRepository('project-001', 'repo-001');

    expect($result)->toBe([
        'software_system_id' => $system->id,
        'security_container_id' => $container->id,
    ]);
});

it('resolves only the system when the container does not exist', function () {
    $system = SoftwareSystem::factory()->create(['source_id' => 'azdo', 'source_system_id' => 'project-001']);

    $result = (new AzDoOwnerLookup)->forAzDoRepository('project-001', 'repo-001');

    expect($result)->toBe([
        'software_system_id' => $system->id,
        'security_container_id' => null,
    ]);
});

it('returns both null when the system does not exist', function () {
    $result = (new AzDoOwnerLookup)->forAzDoRepository('project-001', 'repo-001');

    expect($result)->toBe([
        'software_system_id' => null,
        'security_container_id' => null,
    ]);
});

it('returns both null for a null or empty project id, without querying the container', function () {
    expect((new AzDoOwnerLookup)->forAzDoRepository(null, 'repo-001'))->toBe([
        'software_system_id' => null,
        'security_container_id' => null,
    ]);

    expect((new AzDoOwnerLookup)->forAzDoRepository('', 'repo-001'))->toBe([
        'software_system_id' => null,
        'security_container_id' => null,
    ]);
});

it('returns a null container id for a null or empty repository id', function () {
    $system = SoftwareSystem::factory()->create(['source_id' => 'azdo', 'source_system_id' => 'project-001']);

    expect((new AzDoOwnerLookup)->forAzDoRepository('project-001', null))->toBe([
        'software_system_id' => $system->id,
        'security_container_id' => null,
    ]);

    expect((new AzDoOwnerLookup)->forAzDoRepository('project-001', ''))->toBe([
        'software_system_id' => $system->id,
        'security_container_id' => null,
    ]);
});

it('does not resolve a system from a different source with the same source_system_id', function () {
    SoftwareSystem::factory()->create(['source_id' => 'asoc', 'source_system_id' => 'project-001']);

    $result = (new AzDoOwnerLookup)->forAzDoRepository('project-001', 'repo-001');

    expect($result)->toBe([
        'software_system_id' => null,
        'security_container_id' => null,
    ]);
});

it('does not resolve a container belonging to a different system with the same source_container_id', function () {
    $system = SoftwareSystem::factory()->create(['source_id' => 'azdo', 'source_system_id' => 'project-001']);
    $otherSystem = SoftwareSystem::factory()->create(['source_id' => 'azdo', 'source_system_id' => 'project-002']);
    SecurityContainer::factory()->create([
        'software_system_id' => $otherSystem->id,
        'source_container_id' => 'repo-001',
    ]);

    $result = (new AzDoOwnerLookup)->forAzDoRepository('project-001', 'repo-001');

    expect($result)->toBe([
        'software_system_id' => $system->id,
        'security_container_id' => null,
    ]);
});
