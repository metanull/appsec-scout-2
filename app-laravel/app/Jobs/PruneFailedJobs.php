<?php

namespace App\Jobs;

use App\Models\FailedJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class PruneFailedJobs implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(private readonly int $retainDays = 90) {}

    public function handle(): int
    {
        return FailedJob::query()
            ->where('failed_at', '<', now()->subDays($this->retainDays))
            ->delete();
    }
}
