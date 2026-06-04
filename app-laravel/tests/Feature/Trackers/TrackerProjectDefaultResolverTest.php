<?php

use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\SoftwareSystemLink;
use App\Models\TrackerProjectLink;
use App\Trackers\Defaults\TrackerProjectDefaultContext;
use App\Trackers\Defaults\TrackerProjectDefaultResolver;
use App\Trackers\TrackerConfigRepository;

it('prefers explicit virtual container context over physical mappings', function () {
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create();

    $event = SecurityEvent::factory()->create([
        'software_system_id' => $system->id,
        'container_id' => $container->id,
    ]);

    $system->trackerProjectLinks()->create([
        'tracker_id' => 'jira',
        'project_key' => 'SYS',
        'project_name' => 'System Project',
        'is_default' => true,
    ]);

    $container->trackerProjectLinks()->create([
        'tracker_id' => 'jira',
        'project_key' => 'CONT',
        'project_name' => 'Container Project',
        'is_default' => true,
    ]);

    $context = TrackerProjectDefaultContext::forVirtualContainer('jira', 'VCON', 'Virtual Container Project');

    $result = app(TrackerProjectDefaultResolver::class)->resolveForEvent($event, 'jira', $context);

    expect($result->hasDefault())->toBeTrue()
        ->and($result->projectKey)->toBe('VCON')
        ->and($result->source)->toBe('virtual_container_context');
});

it('prefers physical container mappings before physical system mappings', function () {
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create();

    $event = SecurityEvent::factory()->create([
        'software_system_id' => $system->id,
        'container_id' => $container->id,
    ]);

    $system->trackerProjectLinks()->create([
        'tracker_id' => 'jira',
        'project_key' => 'SYS',
        'project_name' => 'System Project',
        'is_default' => true,
    ]);

    $container->trackerProjectLinks()->create([
        'tracker_id' => 'jira',
        'project_key' => 'CONT',
        'project_name' => 'Container Project',
        'is_default' => true,
    ]);

    $result = app(TrackerProjectDefaultResolver::class)->resolveForEvent($event, 'jira');

    expect($result->hasDefault())->toBeTrue()
        ->and($result->projectKey)->toBe('CONT')
        ->and($result->source)->toBe('container_mapping');
});

it('uses physical system mapping when no container mapping exists', function () {
    $system = SoftwareSystem::factory()->create();

    $event = SecurityEvent::factory()->create([
        'software_system_id' => $system->id,
        'container_id' => null,
    ]);

    $system->trackerProjectLinks()->create([
        'tracker_id' => 'jira',
        'project_key' => 'SYS',
        'project_name' => 'System Project',
        'is_default' => true,
    ]);

    $result = app(TrackerProjectDefaultResolver::class)->resolveForEvent($event, 'jira');

    expect($result->hasDefault())->toBeTrue()
        ->and($result->projectKey)->toBe('SYS')
        ->and($result->source)->toBe('system_mapping');
});

it('uses virtual system context mapping when physical mappings are absent', function () {
    $system = SoftwareSystem::factory()->create();

    $event = SecurityEvent::factory()->create([
        'software_system_id' => $system->id,
        'container_id' => null,
    ]);

    $virtualSystem = SoftwareSystemLink::factory()->create();
    $virtualSystem->members()->attach($system->id, ['sort_order' => 1]);

    TrackerProjectLink::query()->create([
        'owner_type' => SoftwareSystemLink::class,
        'owner_id' => $virtualSystem->id,
        'tracker_id' => 'jira',
        'project_key' => 'VIRT',
        'project_name' => 'Virtual Project',
        'is_default' => true,
    ]);

    $result = app(TrackerProjectDefaultResolver::class)->resolveForEvent($event, 'jira');

    expect($result->hasDefault())->toBeTrue()
        ->and($result->projectKey)->toBe('VIRT')
        ->and($result->source)->toBe('virtual_system_context');
});

it('uses tracker fallback only when configured for that tracker', function () {
    app(TrackerConfigRepository::class)->setJiraDefaultProjectKey('FALLBACK');

    $system = SoftwareSystem::factory()->create();

    $event = SecurityEvent::factory()->create([
        'software_system_id' => $system->id,
        'container_id' => null,
    ]);

    $jiraResult = app(TrackerProjectDefaultResolver::class)->resolveForEvent($event, 'jira');
    $githubResult = app(TrackerProjectDefaultResolver::class)->resolveForEvent($event, 'github');

    expect($jiraResult->hasDefault())->toBeTrue()
        ->and($jiraResult->projectKey)->toBe('FALLBACK')
        ->and($jiraResult->source)->toBe('tracker_fallback')
        ->and($githubResult->hasDefault())->toBeFalse();
});

it('returns grouped default only when all selected alerts resolve to the same project', function () {
    $systemA = SoftwareSystem::factory()->create();
    $systemB = SoftwareSystem::factory()->create();

    $eventA = SecurityEvent::factory()->create(['software_system_id' => $systemA->id, 'container_id' => null]);
    $eventB = SecurityEvent::factory()->create(['software_system_id' => $systemB->id, 'container_id' => null]);

    $systemA->trackerProjectLinks()->create([
        'tracker_id' => 'jira',
        'project_key' => 'APP',
        'project_name' => 'Application',
        'is_default' => true,
    ]);

    $systemB->trackerProjectLinks()->create([
        'tracker_id' => 'jira',
        'project_key' => 'APP',
        'project_name' => 'Application',
        'is_default' => true,
    ]);

    $result = app(TrackerProjectDefaultResolver::class)->resolveForEvents([$eventA, $eventB], 'jira');

    expect($result->hasDefault())->toBeTrue()
        ->and($result->projectKey)->toBe('APP')
        ->and($result->reasonText)->toContain('All selected alerts resolved');
});

it('returns no grouped default for conflicting alert defaults', function () {
    $systemA = SoftwareSystem::factory()->create();
    $systemB = SoftwareSystem::factory()->create();

    $eventA = SecurityEvent::factory()->create(['software_system_id' => $systemA->id, 'container_id' => null]);
    $eventB = SecurityEvent::factory()->create(['software_system_id' => $systemB->id, 'container_id' => null]);

    $systemA->trackerProjectLinks()->create([
        'tracker_id' => 'jira',
        'project_key' => 'APP',
        'project_name' => 'Application',
        'is_default' => true,
    ]);

    $systemB->trackerProjectLinks()->create([
        'tracker_id' => 'jira',
        'project_key' => 'OPS',
        'project_name' => 'Operations',
        'is_default' => true,
    ]);

    $result = app(TrackerProjectDefaultResolver::class)->resolveForEvents([$eventA, $eventB], 'jira');

    expect($result->hasDefault())->toBeFalse()
        ->and($result->reasonText)->toContain('different projects');
});
