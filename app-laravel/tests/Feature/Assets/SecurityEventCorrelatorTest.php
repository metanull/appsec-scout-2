<?php

use App\Assets\SecurityEventCorrelator;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;

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
