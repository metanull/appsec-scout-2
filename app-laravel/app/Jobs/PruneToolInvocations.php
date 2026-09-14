<?php

namespace App\Jobs;

use App\Models\ToolInvocation;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class PruneToolInvocations implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(private readonly int $retainDays = 90) {}

    public function handle(): int
    {
        return ToolInvocation::query()
            ->where('started_at', '<', now()->subDays($this->retainDays))
            ->delete();
    }
}
