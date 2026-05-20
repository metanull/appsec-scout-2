<?php

namespace App\Events;

use App\Models\SyncRun;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SyncRunFinished
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly SyncRun $run) {}
}
