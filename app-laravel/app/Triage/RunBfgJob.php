<?php

namespace App\Triage;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\File;

class RunBfgJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(
        public readonly int $eventId,
        public readonly string $gitUrl,
        public readonly string $secretListPath,
        public readonly int $userId,
    ) {}

    public function handle(BfgService $service): void
    {
        try {
            $service->run($this->gitUrl, $this->secretListPath, $this->eventId, $this->userId);
        } finally {
            File::delete($this->secretListPath);
        }
    }
}
