<?php

use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\User;
use App\Trackers\Registry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Mcp\Server\Testing\TestResponse;
use Tests\Fakes\FakeTracker;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class)->in('Feature');
uses(TestCase::class)->in('Unit');

/**
 * A user holding exactly the given seeded role (none when null). Requires RolePermissionSeeder.
 *
 * @param  array<string, mixed>  $attributes
 */
function mcpUser(?string $role, array $attributes = []): User
{
    $user = User::factory()->create($attributes);
    $user->syncRoles($role === null ? [] : [$role]);

    return $user;
}

/**
 * The `id` of every row in a paginated MCP list response, in response order.
 *
 * @return list<int>
 */
function mcpItemIds(TestResponse $response): array
{
    $ids = [];

    $response->assertStructuredContent(function (AssertableJson $json) use (&$ids): void {
        $ids = array_column($json->toArray()['items'] ?? [], 'id');
        $json->etc();
    });

    return $ids;
}

function trackerFixtureText(string $path): string
{
    return str_replace("\r\n", "\n", trim(file_get_contents(base_path('tests/Fixtures/Trackers/' . $path))));
}

function bindFakeWorkItemTracker(FakeTracker $tracker): FakeTracker
{
    app()->bind('appsec-scout.tracker.fake', fn () => $tracker);
    app()->tag(['appsec-scout.tracker.fake'], 'appsec-scout.tracker');

    app()->forgetInstance(Registry::class);

    return $tracker;
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

    $system->setRelation('repositoryMappings', collect());
    $container->setRelation('repositoryMappings', collect());

    $event->setRelation('softwareSystem', $system);
    $event->setRelation('container', $container);
    $event->setRelation('workItemLinks', collect());

    return $event;
}
