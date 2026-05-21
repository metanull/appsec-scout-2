<?php

namespace App\Triage;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class RunTrivyJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(
        public readonly int $eventId,
        public readonly string $gitUrl,
        public readonly int $userId,
    ) {}

    public function handle(TrivyService $service): void
    {
        $service->run($this->gitUrl, $this->eventId, $this->userId);
    }
}
