<?php

use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\InferenceSuggestionStatus;
use App\Models\InferenceSuggestion;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// ---------------------------------------------------------------------------
// scopeOpen
// ---------------------------------------------------------------------------

it('scopeOpen returns only open events', function () {
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create();

    SecurityEvent::factory()->forSystem($system)->forContainer($container)->create(['state' => EventState::Open]);
    SecurityEvent::factory()->forSystem($system)->forContainer($container)->create(['state' => EventState::Resolved]);
    SecurityEvent::factory()->forSystem($system)->forContainer($container)->create(['state' => EventState::Dismissed]);

    expect(SecurityEvent::query()->open()->count())->toBe(1);
});

it('container open_events_count uses only open events', function () {
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create();

    SecurityEvent::factory()->forSystem($system)->forContainer($container)->create(['state' => EventState::Open]);
    SecurityEvent::factory()->forSystem($system)->forContainer($container)->create(['state' => EventState::Open]);
    SecurityEvent::factory()->forSystem($system)->forContainer($container)->create(['state' => EventState::Resolved]);

    $count = $container->events()->open()->count();

    expect($count)->toBe(2);
});

// ---------------------------------------------------------------------------
// scopeWithSeverity
// ---------------------------------------------------------------------------

it('scopeWithSeverity returns only events matching the given severity', function () {
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create();

    SecurityEvent::factory()->forSystem($system)->forContainer($container)->create(['severity' => EventSeverity::Critical]);
    SecurityEvent::factory()->forSystem($system)->forContainer($container)->create(['severity' => EventSeverity::High]);
    SecurityEvent::factory()->forSystem($system)->forContainer($container)->create(['severity' => EventSeverity::Medium]);

    expect(SecurityEvent::query()->withSeverity(EventSeverity::Critical)->count())->toBe(1)
        ->and(SecurityEvent::query()->withSeverity(EventSeverity::High)->count())->toBe(1)
        ->and(SecurityEvent::query()->withSeverity(EventSeverity::Medium)->count())->toBe(1)
        ->and(SecurityEvent::query()->withSeverity(EventSeverity::Low)->count())->toBe(0);
});

it('software system severity counts use only matching severities', function () {
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create();

    SecurityEvent::factory()->forSystem($system)->forContainer($container)->create(['severity' => EventSeverity::Critical, 'state' => EventState::Open]);
    SecurityEvent::factory()->forSystem($system)->forContainer($container)->create(['severity' => EventSeverity::Critical, 'state' => EventState::Resolved]);
    SecurityEvent::factory()->forSystem($system)->forContainer($container)->create(['severity' => EventSeverity::High, 'state' => EventState::Open]);
    SecurityEvent::factory()->forSystem($system)->forContainer($container)->create(['severity' => EventSeverity::Medium, 'state' => EventState::Open]);

    expect($system->events()->open()->count())->toBe(3)
        ->and($system->events()->withSeverity(EventSeverity::Critical)->count())->toBe(2)
        ->and($system->events()->withSeverity(EventSeverity::High)->count())->toBe(1)
        ->and($system->events()->withSeverity(EventSeverity::Medium)->count())->toBe(1)
        ->and($system->events()->withSeverity(EventSeverity::Low)->count())->toBe(0);
});

// ---------------------------------------------------------------------------
// scopeBySeverityPriority
// ---------------------------------------------------------------------------

it('bySeverityPriority orders critical before high before medium before low before informational', function () {
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create();

    $low = SecurityEvent::factory()->forSystem($system)->forContainer($container)->create(['severity' => EventSeverity::Low]);
    $critical = SecurityEvent::factory()->forSystem($system)->forContainer($container)->create(['severity' => EventSeverity::Critical]);
    $informational = SecurityEvent::factory()->forSystem($system)->forContainer($container)->create(['severity' => EventSeverity::Informational]);
    $medium = SecurityEvent::factory()->forSystem($system)->forContainer($container)->create(['severity' => EventSeverity::Medium]);
    $high = SecurityEvent::factory()->forSystem($system)->forContainer($container)->create(['severity' => EventSeverity::High]);

    $ordered = SecurityEvent::query()->bySeverityPriority()->pluck('id')->all();

    $criticalPos = array_search($critical->id, $ordered);
    $highPos = array_search($high->id, $ordered);
    $mediumPos = array_search($medium->id, $ordered);
    $lowPos = array_search($low->id, $ordered);
    $informationalPos = array_search($informational->id, $ordered);

    expect($criticalPos)->toBeLessThan($highPos)
        ->and($highPos)->toBeLessThan($mediumPos)
        ->and($mediumPos)->toBeLessThan($lowPos)
        ->and($lowPos)->toBeLessThan($informationalPos);
});

// ---------------------------------------------------------------------------
// InferenceSuggestion::scopePendingFirst
// ---------------------------------------------------------------------------

it('pendingFirst places pending suggestions before non-pending suggestions', function () {
    $system = SoftwareSystem::factory()->create();

    $accepted = InferenceSuggestion::factory()->forSubject($system)->forTarget(null)->create([
        'suggestion_type' => InferenceSuggestion::TYPE_REPOSITORY_MAPPING,
        'proposed_action' => InferenceSuggestion::ACTION_CREATE_REPOSITORY_MAPPING,
        'status' => InferenceSuggestionStatus::Accepted,
    ]);

    $pending = InferenceSuggestion::factory()->forSubject($system)->forTarget(null)->create([
        'suggestion_type' => InferenceSuggestion::TYPE_REPOSITORY_MAPPING,
        'proposed_action' => InferenceSuggestion::ACTION_CREATE_REPOSITORY_MAPPING,
        'status' => InferenceSuggestionStatus::Pending,
    ]);

    $rejected = InferenceSuggestion::factory()->forSubject($system)->forTarget(null)->create([
        'suggestion_type' => InferenceSuggestion::TYPE_TRACKER_PROJECT_MAPPING,
        'proposed_action' => InferenceSuggestion::ACTION_CREATE_TRACKER_PROJECT_LINK,
        'status' => InferenceSuggestionStatus::Rejected,
    ]);

    $ordered = InferenceSuggestion::query()->pendingFirst()->pluck('id')->all();

    $pendingPos = array_search($pending->id, $ordered);
    $acceptedPos = array_search($accepted->id, $ordered);
    $rejectedPos = array_search($rejected->id, $ordered);

    expect($pendingPos)->toBeLessThan($acceptedPos)
        ->and($pendingPos)->toBeLessThan($rejectedPos);
});

it('pendingFirst orders multiple pending suggestions by newest first', function () {
    $system = SoftwareSystem::factory()->create();

    $olderPending = InferenceSuggestion::factory()->forSubject($system)->forTarget(null)->create([
        'suggestion_type' => InferenceSuggestion::TYPE_REPOSITORY_MAPPING,
        'proposed_action' => InferenceSuggestion::ACTION_CREATE_REPOSITORY_MAPPING,
        'status' => InferenceSuggestionStatus::Pending,
        'created_at' => now()->subDays(2),
    ]);

    $newerPending = InferenceSuggestion::factory()->forSubject($system)->forTarget(null)->create([
        'suggestion_type' => InferenceSuggestion::TYPE_TRACKER_PROJECT_MAPPING,
        'proposed_action' => InferenceSuggestion::ACTION_CREATE_TRACKER_PROJECT_LINK,
        'status' => InferenceSuggestionStatus::Pending,
        'created_at' => now()->subDay(),
    ]);

    $ordered = InferenceSuggestion::query()->pendingFirst()->pluck('id')->all();

    $newerPos = array_search($newerPending->id, $ordered);
    $olderPos = array_search($olderPending->id, $ordered);

    expect($newerPos)->toBeLessThan($olderPos);
});
