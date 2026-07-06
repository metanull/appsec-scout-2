<?php

use App\Assets\AttachmentService;
use App\Models\Attachment;
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

it('deletes descendant containers and their owned rows when the system is deleted', function () {
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create();

    $sbomPayload = (string) file_get_contents(base_path('tests/Fixtures/Trivy/cyclonedx-sample.json'));
    $attachment = app(AttachmentService::class)->attachTo($container, 'sbom', 'application/json', 'sbom.json', $sbomPayload);
    $componentId = SoftwareComponent::query()->where('owner_id', $container->id)->value('id');

    expect($componentId)->not()->toBeNull();

    $system->delete();

    expect(SecurityContainer::query()->whereKey($container->id)->exists())->toBeFalse()
        ->and(Attachment::query()->whereKey($attachment->id)->exists())->toBeFalse()
        ->and(SoftwareComponent::query()->whereKey($componentId)->exists())->toBeFalse();
});

it('still allows deleting a security container directly', function () {
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create();

    $container->delete();

    expect(SecurityContainer::query()->whereKey($container->id)->exists())->toBeFalse()
        ->and(SoftwareSystem::query()->whereKey($system->id)->exists())->toBeTrue();
});
