<?php

namespace App\Triage;

use App\Audit\Recorder;
use App\Credentials\Vault;
use App\Models\SecurityEvent;

class CodesearchService
{
    public function __construct(
        private readonly AttachmentService $attachments,
        private readonly CodesearchClientFactory $clientFactory,
        private readonly Recorder $recorder,
        private readonly Vault $vault,
    ) {}

    public function run(
        string $pat,
        string $searchText,
        ?string $scope = null,
        ?int $attachToEventId = null,
        ?int $createdByUserId = null,
    ): CodesearchRunResult {
        $organization = $this->vault->get('azdo.organization', null)
            ?? throw new \RuntimeException('AzDO organization not configured');

        $client = $this->clientFactory->make($organization, $pat);
        $result = new CodesearchRunResult($client->search($searchText, $this->parseScope($scope)));

        if ($attachToEventId !== null) {
            $event = SecurityEvent::query()->findOrFail($attachToEventId);
            $attachment = $this->attachments->attachToEvent(
                event: $event,
                kind: 'codesearch-json',
                mime: 'application/json',
                name: sprintf('codesearch-%s.json', now()->format('Ymd-His')),
                payload: $result->json(),
                createdByUserId: $createdByUserId,
                createdByCommand: 'triage:codesearch',
            );

            $this->recorder->recordTriageRun(SecurityEvent::class, (string) $event->id, [
                'command' => 'codesearch',
                'attachment_id' => $attachment->id,
                'scope' => $scope,
            ]);
        }

        return $result;
    }

    /** @return array<string, list<string>> */
    private function parseScope(?string $scope): array
    {
        if ($scope === null || trim($scope) === '') {
            return [];
        }

        $parts = explode(':', $scope, 2);

        if (count($parts) !== 2) {
            throw new \InvalidArgumentException('Scope must use the format project:<name> or repo:<name>.');
        }

        [$kind, $value] = [strtolower(trim($parts[0])), trim($parts[1])];

        if ($value === '') {
            throw new \InvalidArgumentException('Scope value cannot be empty.');
        }

        return match ($kind) {
            'project' => ['Project' => [$value]],
            'repo', 'repository' => ['Repository' => [$value]],
            default => throw new \InvalidArgumentException('Scope must use project:<name> or repo:<name>.'),
        };
    }
}
