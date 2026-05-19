<?php

namespace App\Jobs;

use App\Models\ErrorLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class PruneErrorLogs implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(private readonly int $retainDays = 90) {}

    public function handle(): void
    {
        ErrorLog::query()
            ->where('occurred_at', '<', now()->subDays($this->retainDays))
            ->delete();
    }
}
