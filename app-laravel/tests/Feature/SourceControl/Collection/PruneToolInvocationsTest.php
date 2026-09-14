<?php

use App\Jobs\PruneToolInvocations;
use App\Models\ToolInvocation;

it('prunes tool invocations older than retain days', function () {
    ToolInvocation::factory()->create(['started_at' => now()->subDays(120)]);
    ToolInvocation::factory()->create(['started_at' => now()->subDays(10)]);

    (new PruneToolInvocations(90))->handle();

    expect(ToolInvocation::count())->toBe(1);
});

it('honors a configured retention window read from config', function () {
    config(['static_analysis_collection.tool_invocation_retain_days' => 30]);

    ToolInvocation::factory()->create(['started_at' => now()->subDays(45)]);
    ToolInvocation::factory()->create(['started_at' => now()->subDays(10)]);

    (new PruneToolInvocations((int) config('static_analysis_collection.tool_invocation_retain_days')))->handle();

    expect(ToolInvocation::count())->toBe(1);
});
