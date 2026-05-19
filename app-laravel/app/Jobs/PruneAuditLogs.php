<?php

namespace App\Jobs;

use App\Audit\AuditLog;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class PruneAuditLogs implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(private readonly int $retainDays = 365) {}

    public function handle(): void
    {
        AuditLog::query()
            ->where('created_at', '<', now()->subDays($this->retainDays))
            ->delete();
    }
}
