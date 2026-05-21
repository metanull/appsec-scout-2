<?php

use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');
uses(TestCase::class)->in('Unit');

function trackerFixtureText(string $path): string
{
    return str_replace("\r\n", "\n", trim(file_get_contents(base_path('tests/Fixtures/Trackers/' . $path))));
}

/** @return list<string> */
function triageWorkspaceDirectories(): array
{
    $path = storage_path('app/triage');

    if (! is_dir($path)) {
        return [];
    }

    return array_values(File::directories($path));
}

/** @param array<string, mixed> $overrides */
function makeEvent(array $overrides = []): SecurityEvent
{
    $system = new SoftwareSystem(['name' => 'Payments API']);
    $container = new SecurityContainer(['name' => 'payments-service']);

    $event = new SecurityEvent(array_merge([
        'source_id' => 'github',
        'source_event_id' => 'evt-1',
        'title' => 'Example finding',
        'description' => null,
        'severity' => EventSeverity::High,
        'state' => EventState::Open,
        'type' => EventType::Vulnerability,
        'rule_id' => null,
        'url' => null,
        'remediation' => null,
        'file_path' => 'src/Example.php',
        'start_line' => 10,
        'first_seen_at' => Carbon::parse('2026-05-01 08:00:00'),
        'last_seen_at' => Carbon::parse('2026-05-20 09:30:00'),
    ], $overrides));

    $event->setRelation('softwareSystem', $system);
    $event->setRelation('container', $container);

    return $event;
}
