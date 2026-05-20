<?php

namespace App\Sync;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

final class PushEventStatesJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    /** @param  list<int>  $eventIds */
    public function __construct(public readonly array $eventIds) {}

    public function handle(): void {}
}
