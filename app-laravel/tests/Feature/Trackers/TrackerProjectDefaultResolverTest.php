<?php

use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Trackers\Defaults\TrackerProjectDefaultResolver;
use App\Trackers\TrackerConfigRepository;

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

it('warns instead of silently resolving nothing when a level has multiple links with no unique default', function () {
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create();

    $event = SecurityEvent::factory()->create([
        'software_system_id' => $system->id,
        'container_id' => $container->id,
    ]);

    // Two container-level links for the same tracker, neither marked default.
    $container->trackerProjectLinks()->create(['tracker_id' => 'jira', 'project_key' => 'ONE', 'is_default' => false]);
    $container->trackerProjectLinks()->create(['tracker_id' => 'jira', 'project_key' => 'TWO', 'is_default' => false]);

    $system->trackerProjectLinks()->create(['tracker_id' => 'jira', 'project_key' => 'SYS', 'project_name' => 'System Project', 'is_default' => true]);

    $result = app(TrackerProjectDefaultResolver::class)->resolveForEvent($event, 'jira');

    // The system-level link still resolves a usable default...
    expect($result->hasDefault())->toBeTrue()
        ->and($result->projectKey)->toBe('SYS')
        // ...but the container-level ambiguity is still surfaced, not silently dropped.
        ->and($result->ambiguityWarning)->not->toBeNull()
        ->and($result->ambiguityWarning)->toContain('container level')
        ->and($result->ambiguityWarning)->toContain('none of them is marked as the default');
});

it('warns when multiple defaults are flagged at the same level and no lower level resolves either', function () {
    $system = SoftwareSystem::factory()->create();

    $event = SecurityEvent::factory()->create([
        'software_system_id' => $system->id,
        'container_id' => null,
    ]);

    $system->trackerProjectLinks()->create(['tracker_id' => 'jira', 'project_key' => 'ONE', 'is_default' => true]);
    $system->trackerProjectLinks()->create(['tracker_id' => 'jira', 'project_key' => 'TWO', 'is_default' => true]);

    $result = app(TrackerProjectDefaultResolver::class)->resolveForEvent($event, 'jira');

    expect($result->hasDefault())->toBeFalse()
        ->and($result->ambiguityWarning)->not->toBeNull()
        ->and($result->ambiguityWarning)->toContain('system level')
        ->and($result->ambiguityWarning)->toContain('more than one of them is marked as the default');
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
