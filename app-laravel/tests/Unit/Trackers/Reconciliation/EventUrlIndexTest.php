<?php

use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Trackers\Reconciliation\EventUrlIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('indexes event url and version control url values', function () {
    $event = seededEvent([
        'url' => 'https://example.com/alerts/100#fragment',
        'version_control_url' => 'https://example.com/repo/path/',
    ]);

    $index = EventUrlIndex::build([$event]);

    expect($index->findExact('https://example.com/alerts/100'))->toBe([(int) $event->id])
        ->and($index->findExact('https://example.com/repo/path'))->toBe([(int) $event->id]);
});

it('indexes metadata links urls', function () {
    $event = seededEvent([
        'metadata' => [
            'links' => [
                ['url' => 'https://docs.example.com/playbook'],
                ['url' => 'https://portal.example.com/ticket/42'],
            ],
        ],
    ]);

    $index = EventUrlIndex::build([$event]);

    expect($index->findExact('https://docs.example.com/playbook'))->toBe([(int) $event->id])
        ->and($index->findExact('https://portal.example.com/ticket/42'))->toBe([(int) $event->id]);
});

it('synthesizes azdo portal and repo root urls from advsec alert uri', function () {
    $event = seededEvent([
        'source_data' => json_encode([
            'alertUri' => 'https://advsec.dev.azure.com/acme/proj-guid/_apis/Alert/repositories/repo-guid/Alerts/12',
        ], JSON_UNESCAPED_SLASHES),
    ]);

    $index = EventUrlIndex::build([$event]);

    expect($index->findExact('https://advsec.dev.azure.com/acme/proj-guid/_apis/alert/repositories/repo-guid/alerts/12'))->toBe([(int) $event->id])
        ->and($index->findExact('https://dev.azure.com/acme/proj-guid/_git/repo-guid/alerts/12'))->toBe([(int) $event->id])
        ->and($index->findExact('https://dev.azure.com/acme/proj-guid/_git/repo-guid'))->toBe([(int) $event->id]);
});

it('indexes non azdo alert uri as-is without synthesis', function () {
    $event = seededEvent([
        'source_data' => json_encode([
            'alertUri' => 'https://security.example.com/alerts/44',
        ], JSON_UNESCAPED_SLASHES),
    ]);

    $index = EventUrlIndex::build([$event]);

    expect($index->findExact('https://security.example.com/alerts/44'))->toBe([(int) $event->id])
        ->and($index->findExact('https://dev.azure.com/acme/proj-guid/_git/repo-guid/alerts/44'))->toBe([]);
});

it('returns exact and prefix matches', function () {
    $first = seededEvent(['url' => 'https://dev.azure.com/acme/proj/_git/repo/alerts/1']);
    $second = seededEvent(['url' => 'https://dev.azure.com/acme/proj/_git/repo/alerts/2']);
    $third = seededEvent(['url' => 'https://dev.azure.com/acme/proj/_git/other/alerts/3']);

    $index = EventUrlIndex::build([$first, $second, $third]);

    expect($index->findExact('https://dev.azure.com/acme/proj/_git/repo/alerts/1'))->toBe([(int) $first->id])
        ->and($index->findByPrefix('https://dev.azure.com/acme/proj/_git/repo'))->toBe([(int) $first->id, (int) $second->id])
        ->and($index->findAll('https://dev.azure.com/acme/proj/_git/repo'))->toBe([(int) $first->id, (int) $second->id]);
});

it('returns empty list when no urls match', function () {
    $event = seededEvent(['url' => 'https://example.com/a']);

    $index = EventUrlIndex::build([$event]);

    expect($index->findAll('https://example.com/b'))->toBe([]);
});

it('skips malformed and non http urls without throwing', function () {
    $event = seededEvent([
        'url' => 'javascript:alert(1)',
        'version_control_url' => 'not-a-url',
        'metadata' => [
            'links' => [
                ['url' => 'data:text/plain,abc'],
                ['url' => 'https://ok.example.com/path'],
            ],
        ],
    ]);

    $index = EventUrlIndex::build([$event]);

    expect($index->findExact('https://ok.example.com/path'))->toBe([(int) $event->id])
        ->and($index->findExact('javascript:alert(1)'))->toBe([]);
});

function seededEvent(array $overrides = []): SecurityEvent
{
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create();

    return SecurityEvent::factory()->forContainer($container)->create($overrides);
}
