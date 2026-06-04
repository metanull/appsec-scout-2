<?php

namespace App\Trackers\Contracts;

use App\Credentials\CredentialField;
use App\Trackers\Dto\CreateWorkItemRequest;
use App\Trackers\Dto\ProjectDto;
use App\Trackers\Dto\ReconciliationCandidateDto;
use App\Trackers\Dto\UpdateWorkItemRequest;
use App\Trackers\Dto\UserDto;
use App\Trackers\Dto\WorkItemDto;
use App\Trackers\ValueObjects\TestResult;
use App\Trackers\ValueObjects\TrackerCapabilities;

interface Tracker
{
    public function id(): string;

    public function displayName(): string;

    public function capabilities(): TrackerCapabilities;

    /** @return list<CredentialField> */
    public function credentialFields(): array;

    public function testConnection(): TestResult;

    /** @return iterable<ProjectDto> */
    public function fetchProjects(): iterable;

    /** @return iterable<string> */
    public function fetchItemTypes(string $projectKey): iterable;

    /** @return iterable<UserDto> */
    public function fetchAssigneeCandidates(string $projectKey, string $query): iterable;

    public function createWorkItem(CreateWorkItemRequest $request): WorkItemDto;

    public function getWorkItem(string $workItemKey): ?WorkItemDto;

    public function updateWorkItem(string $workItemKey, UpdateWorkItemRequest $request): WorkItemDto;

    /** @return iterable<WorkItemDto> */
    public function searchWorkItems(string $projectKey, string $query, int $limit = 20): iterable;

    /**
     * @return iterable<ReconciliationCandidateDto>
     *
     * @throws \Throwable
     */
    public function reconciliationCandidates(string $projectKey): iterable;
}
