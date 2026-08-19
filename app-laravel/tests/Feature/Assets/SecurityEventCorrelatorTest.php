<?php

use App\Assets\SecurityEventCorrelator;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareAsset;
use App\Models\SoftwareSystem;
use Illuminate\Support\Facades\DB;

it('correlates a vulnerability finding to a matching dependency alert by package and version', function () {
    $system = SoftwareSystem::factory()->create();
    $container = SecurityContainer::factory()->forSystem($system)->create();

    $event = SecurityEvent::factory()->forContainer($container)->create([
        'type' => EventType::Dependency,
        'severity' => EventSeverity::High,
        'state' => EventState::Open,
        'metadata' => ['package' => ['name' => 'Jinja2', 'version' => '3.1.4', 'ecosystem' => 'pip']],
    ]);

    $finding = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY,
        'rule_id' => 'CVE-2024-56201',
        'title' => 'Jinja sandbox breakout',
        'severity' => 'MEDIUM',
        'file_path' => 'requirements.txt',
        'start_line' => 8,
        'package_name' => 'Jinja2',
        'package_version' => '3.1.4',
        'metadata' => [],
    ]);

    app(SecurityEventCorrelator::class)->correlate($finding);

    expect($finding->fresh()->correlated_security_event_id)->toBe($event->id)
        ->and($finding->fresh()->correlation_method)->toBe('package_version');
});

it('does not correlate a vulnerability finding when the package name or version differs', function () {
    $container = SecurityContainer::factory()->create();

    SecurityEvent::factory()->forContainer($container)->create([
        'type' => EventType::Dependency,
        'metadata' => ['package' => ['name' => 'Jinja2', 'version' => '3.1.5']],
    ]);

    $finding = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY,
        'rule_id' => 'CVE-2024-56201',
        'title' => 'Jinja sandbox breakout',
        'file_path' => 'requirements.txt',
        'package_name' => 'Jinja2',
        'package_version' => '3.1.4',
    ]);

    app(SecurityEventCorrelator::class)->correlate($finding);

    expect($finding->fresh()->correlated_security_event_id)->toBeNull();
});

it('correlates a secret finding to a matching secret alert by file path and line proximity', function () {
    $container = SecurityContainer::factory()->create();

    $event = SecurityEvent::factory()->forContainer($container)->create([
        'type' => EventType::Secret,
        'file_path' => 'config.php',
        'start_line' => 4,
    ]);

    $finding = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'github-pat',
        'title' => 'GitHub Personal Access Token',
        'file_path' => 'config.php',
        'start_line' => 3,
    ]);

    app(SecurityEventCorrelator::class)->correlate($finding);

    expect($finding->fresh()->correlated_security_event_id)->toBe($event->id)
        ->and($finding->fresh()->correlation_method)->toBe('file_line');
});

it('does not correlate a secret finding on a different file path', function () {
    $container = SecurityContainer::factory()->create();

    SecurityEvent::factory()->forContainer($container)->create([
        'type' => EventType::Secret,
        'file_path' => 'other.php',
        'start_line' => 3,
    ]);

    $finding = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'github-pat',
        'title' => 'GitHub Personal Access Token',
        'file_path' => 'config.php',
        'start_line' => 3,
    ]);

    app(SecurityEventCorrelator::class)->correlate($finding);

    expect($finding->fresh()->correlated_security_event_id)->toBeNull();
});

it('correlateBatch produces the same results as calling correlate() once per finding', function () {
    $container = SecurityContainer::factory()->create();

    $dependencyEvent = SecurityEvent::factory()->forContainer($container)->create([
        'type' => EventType::Dependency,
        'metadata' => ['package' => ['name' => 'Jinja2', 'version' => '3.1.4']],
    ]);
    $secretEvent = SecurityEvent::factory()->forContainer($container)->create([
        'type' => EventType::Secret,
        'file_path' => 'config.php',
        'start_line' => 4,
    ]);

    $matchingVulnerability = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY,
        'rule_id' => 'CVE-2024-56201',
        'title' => 'Jinja sandbox breakout',
        'file_path' => 'requirements.txt',
        'package_name' => 'Jinja2',
        'package_version' => '3.1.4',
    ]);
    $nonMatchingVulnerability = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY,
        'rule_id' => 'CVE-2024-99999',
        'title' => 'Unrelated CVE',
        'file_path' => 'requirements.txt',
        'package_name' => 'OtherPackage',
        'package_version' => '1.0.0',
    ]);
    $matchingSecret = $container->localFindings()->create([
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'github-pat',
        'title' => 'GitHub Personal Access Token',
        'file_path' => 'config.php',
        'start_line' => 3,
    ]);

    app(SecurityEventCorrelator::class)->correlateBatch(
        [$matchingVulnerability, $nonMatchingVulnerability, $matchingSecret],
        $container,
    );

    expect($matchingVulnerability->fresh()->correlated_security_event_id)->toBe($dependencyEvent->id)
        ->and($matchingVulnerability->fresh()->correlation_method)->toBe('package_version')
        ->and($nonMatchingVulnerability->fresh()->correlated_security_event_id)->toBeNull()
        ->and($matchingSecret->fresh()->correlated_security_event_id)->toBe($secretEvent->id)
        ->and($matchingSecret->fresh()->correlation_method)->toBe('file_line');
});

it('correlateBatch matches via a SoftwareAsset owner using pre-resolved softwareSystems ids, same as correlate()', function () {
    $asset = SoftwareAsset::factory()->create();
    $system = SoftwareSystem::factory()->create(['software_asset_id' => $asset->id]);

    $event = SecurityEvent::factory()->forSystem($system)->create([
        'type' => EventType::Dependency,
        'metadata' => ['package' => ['name' => 'Jinja2', 'version' => '3.1.4']],
    ]);

    $finding = LocalFinding::query()->create([
        'owner_type' => SoftwareAsset::class,
        'owner_id' => $asset->id,
        'software_asset_id' => $asset->id,
        'kind' => LocalFinding::KIND_VULNERABILITY,
        'rule_id' => 'CVE-2024-56201',
        'title' => 'Jinja sandbox breakout',
        'file_path' => 'requirements.txt',
        'package_name' => 'Jinja2',
        'package_version' => '3.1.4',
    ]);

    app(SecurityEventCorrelator::class)->correlateBatch([$finding], $asset);

    expect($finding->fresh()->correlated_security_event_id)->toBe($event->id)
        ->and($finding->fresh()->correlation_method)->toBe('package_version');
});

it('correlateBatch issues a small, constant number of queries regardless of how many findings are in the batch', function () {
    $container = SecurityContainer::factory()->create();

    SecurityEvent::factory()->forContainer($container)->create([
        'type' => EventType::Dependency,
        'metadata' => ['package' => ['name' => 'Jinja2', 'version' => '3.1.4']],
    ]);

    $findings = collect(range(1, 5))->map(fn (int $i) => $container->localFindings()->create([
        'kind' => LocalFinding::KIND_VULNERABILITY,
        'rule_id' => "CVE-2024-{$i}",
        'title' => 'Jinja sandbox breakout',
        'file_path' => 'requirements.txt',
        'package_name' => 'Jinja2',
        'package_version' => '3.1.4',
    ]));

    DB::enableQueryLog();
    app(SecurityEventCorrelator::class)->correlateBatch($findings, $container);
    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    // One-per-finding correlation would issue at least one candidate-fetch query per finding
    // (5) plus one save() per match (5) — 10 or more. The batch path fetches candidates once
    // and writes once, so it must stay well under that regardless of how many findings match.
    $correlatedCount = LocalFinding::query()
        ->whereIn('id', $findings->pluck('id'))
        ->whereNotNull('correlated_security_event_id')
        ->count();

    expect($queryCount)->toBeLessThan(5)
        ->and($correlatedCount)->toBe(5);
});
