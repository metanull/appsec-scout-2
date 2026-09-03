<?php

use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Trackers\Reconciliation\EventUrlIndex;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('indexes the event url', function () {
    $event = seededEvent([
        'url' => 'https://example.com/alerts/100#fragment',
    ]);

    $index = EventUrlIndex::build([$event]);

    expect($index->findExact('https://example.com/alerts/100'))->toBe([(int) $event->id]);
});

it('does not index the version control url', function () {
    $event = seededEvent([
        'url' => 'https://example.com/alerts/100',
        'version_control_url' => 'https://example.com/repo/path/',
    ]);

    $index = EventUrlIndex::build([$event]);

    expect($index->findAll('https://example.com/repo/path'))->toBe([])
        ->and($index->findAll('https://example.com/alerts/100'))->toBe([(int) $event->id]);
});

it('indexes the source alert web url fact', function () {
    $event = seededEvent([
        'url' => null,
        'metadata' => [
            'source' => ['alert' => ['web_url' => 'https://dev.azure.com/acme/agora/_git/repo/alerts/500']],
        ],
    ]);

    $index = EventUrlIndex::build([$event]);

    expect($index->findAll('https://dev.azure.com/acme/agora/_git/repo/alerts/500'))->toBe([(int) $event->id]);
});

it('indexes only source alert labelled metadata links', function () {
    $event = seededEvent([
        'url' => null,
        'metadata' => [
            'links' => [
                ['label' => 'Source alert', 'url' => 'https://dev.azure.com/acme/agora/_git/repo/alerts/600'],
                ['label' => 'Source file', 'url' => 'https://dev.azure.com/acme/agora/_git/repo/commit/abc'],
                ['label' => 'Rule documentation', 'url' => 'https://docs.example.com/rules/java-ssrf'],
                ['label' => 'CVE: CVE-2020-8203', 'url' => 'https://nvd.nist.gov/vuln/detail/CVE-2020-8203'],
                ['url' => 'https://portal.example.com/ticket/42'],
            ],
        ],
    ]);

    $index = EventUrlIndex::build([$event]);

    expect($index->findAll('https://dev.azure.com/acme/agora/_git/repo/alerts/600'))->toBe([(int) $event->id])
        ->and($index->findAll('https://dev.azure.com/acme/agora/_git/repo/commit/abc'))->toBe([])
        ->and($index->findAll('https://docs.example.com/rules/java-ssrf'))->toBe([])
        ->and($index->findAll('https://nvd.nist.gov/vuln/detail/CVE-2020-8203'))->toBe([])
        ->and($index->findAll('https://portal.example.com/ticket/42'))->toBe([]);
});

it('indexes the advsec alert uri without synthesising a repository root', function () {
    $event = seededEvent([
        'url' => null,
        'source_data' => json_encode([
            'alertUri' => 'https://advsec.dev.azure.com/acme/proj-guid/_apis/Alert/repositories/repo-guid/Alerts/12',
        ], JSON_UNESCAPED_SLASHES),
    ]);

    $index = EventUrlIndex::build([$event]);

    expect($index->findAll('https://advsec.dev.azure.com/acme/proj-guid/_apis/alert/repositories/repo-guid/alerts/12'))->toBe([(int) $event->id])
        ->and($index->findAll('https://dev.azure.com/acme/proj-guid/_git/repo-guid/alerts/12'))->toBe([(int) $event->id])
        ->and($index->findAll('https://dev.azure.com/acme/proj-guid/_git/repo-guid'))->toBe([]);
});

it('indexes non azdo alert uri as-is without synthesis', function () {
    $event = seededEvent([
        'url' => null,
        'source_data' => json_encode([
            'alertUri' => 'https://security.example.com/alerts/44',
        ], JSON_UNESCAPED_SLASHES),
    ]);

    $index = EventUrlIndex::build([$event]);

    expect($index->findExact('https://security.example.com/alerts/44'))->toBe([(int) $event->id])
        ->and($index->findAll('https://dev.azure.com/acme/proj-guid/_git/repo-guid/alerts/44'))->toBe([]);
});

it('does not match container urls to the alerts they contain', function () {
    $first = seededEvent(['url' => 'https://dev.azure.com/acme/proj/_git/repo/alerts/1']);
    $second = seededEvent(['url' => 'https://dev.azure.com/acme/proj/_git/repo/alerts/2']);
    $third = seededEvent(['url' => 'https://dev.azure.com/acme/proj/_git/other/alerts/3']);

    $index = EventUrlIndex::build([$first, $second, $third]);

    expect($index->findExact('https://dev.azure.com/acme/proj/_git/repo/alerts/1'))->toBe([(int) $first->id])
        ->and($index->findAll('https://dev.azure.com/acme/proj/_git/repo'))->toBe([])
        ->and($index->findAll('https://dev.azure.com/acme/proj'))->toBe([]);
});

it('matches a name form candidate url against a guid form event url', function () {
    $event = seededEvent([
        'url' => 'https://dev.azure.com/acme/38ef0ca4-6d0e/_git/7c3a9a75-4ac0/alerts/398',
        'metadata' => [
            'azdo' => [
                'project' => ['id' => '38ef0ca4-6d0e', 'name' => 'Agora'],
                'repository' => ['id' => '7c3a9a75-4ac0', 'name' => 'agora-event-grid-poc'],
            ],
        ],
    ]);

    $index = EventUrlIndex::build([$event]);

    expect($index->findAll('https://dev.azure.com/acme/Agora/_git/agora-event-grid-poc/alerts/398'))->toBe([(int) $event->id]);
});

it('matches a guid form candidate url against a name form event url', function () {
    $event = seededEvent([
        'url' => 'https://dev.azure.com/acme/Agora/_git/agora-event-grid-poc/alerts/398',
        'metadata' => [
            'azdo' => [
                'project' => ['id' => '38ef0ca4-6d0e', 'name' => 'Agora'],
                'repository' => ['id' => '7c3a9a75-4ac0', 'name' => 'agora-event-grid-poc'],
            ],
        ],
    ]);

    $index = EventUrlIndex::build([$event]);

    expect($index->findAll('https://dev.azure.com/acme/38ef0ca4-6d0e/_git/7c3a9a75-4ac0/alerts/398'))->toBe([(int) $event->id])
        ->and($index->findAll('https://advsec.dev.azure.com/acme/38ef0ca4-6d0e/_apis/AdvancedSecurity/repositories/7c3a9a75-4ac0/alerts/398'))->toBe([(int) $event->id]);
});

it('does not match a different alert id under the same repository', function () {
    $event = seededEvent([
        'url' => 'https://dev.azure.com/acme/Agora/_git/agora-event-grid-poc/alerts/398',
        'metadata' => [
            'azdo' => [
                'project' => ['id' => '38ef0ca4-6d0e', 'name' => 'Agora'],
                'repository' => ['id' => '7c3a9a75-4ac0', 'name' => 'agora-event-grid-poc'],
            ],
        ],
    ]);

    $index = EventUrlIndex::build([$event]);

    expect($index->findAll('https://dev.azure.com/acme/Agora/_git/agora-event-grid-poc/alerts/399'))->toBe([]);
});

it('indexes nothing for an asoc event whose url is the shared article url', function () {
    $articleUrl = 'https://cloud.appscan.com/articles/issuetype/sql-injection';

    $event = seededEvent([
        'url' => $articleUrl,
        'metadata' => [
            'asoc' => ['article' => ['url' => $articleUrl]],
            'source' => ['alert' => ['web_url' => $articleUrl]],
            'links' => [
                ['label' => 'Issue article', 'url' => $articleUrl],
            ],
        ],
    ]);

    $index = EventUrlIndex::build([$event]);

    expect($index->findAll($articleUrl))->toBe([]);
});

it('returns empty list when no urls match', function () {
    $event = seededEvent(['url' => 'https://example.com/a']);

    $index = EventUrlIndex::build([$event]);

    expect($index->findAll('https://example.com/b'))->toBe([]);
});

it('skips malformed and non http urls without throwing', function () {
    $event = seededEvent([
        'url' => 'javascript:alert(1)',
        'metadata' => [
            'links' => [
                ['label' => 'Source alert', 'url' => 'data:text/plain,abc'],
                ['label' => 'Source alert', 'url' => 'https://ok.example.com/path'],
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
