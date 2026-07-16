<?php

namespace App\Triage;

use App\Credentials\Vault;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

class RunCodesearchJob implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(
        public readonly int $eventId,
        public readonly string $query,
        public readonly ?string $scope,
        public readonly int $userId,
    ) {}

    public function handle(CodesearchService $service, Vault $vault): void
    {
        $pat = $vault->get('azdo-repos.pat', null)
            ?? throw new \RuntimeException('AzDO Repos PAT is not configured in system credentials.');
        $organization = $vault->get('azdo-repos.organization', null)
            ?? throw new \RuntimeException('AzDO Repos organization is not configured in system credentials.');

        $service->run($pat, $organization, $this->query, $this->scope, $this->eventId, $this->userId);
    }
}
