<?php

namespace Tests\Fakes;

use App\Credentials\CredentialField;
use App\Credentials\Vault;
use App\Trackers\Contracts\Tracker;
use App\Trackers\Dto\CreateWorkItemRequest;
use App\Trackers\Dto\ProjectDto;
use App\Trackers\Dto\ReconciliationCandidateDto;
use App\Trackers\Dto\UpdateWorkItemRequest;
use App\Trackers\Dto\UserDto;
use App\Trackers\Dto\WorkItemDto;
use App\Trackers\TrackerRateLimitExceededException;
use App\Trackers\ValueObjects\TestResult;
use App\Trackers\ValueObjects\TrackerCapabilities;
use Carbon\CarbonImmutable;

final class FakeTracker implements Tracker
{
    public int $createCalls = 0;

    /** @var list<array{key: string, markdown: string}> */
    public array $comments = [];

    private ?string $commentFailure = null;

    public ?CreateWorkItemRequest $latestCreateWorkItemRequest = null;

    public int $getCalls = 0;

    public int $searchCalls = 0;

    public int $reconciliationCalls = 0;

    public int $fetchProjectsCalls = 0;

    /** @var list<ProjectDto> */
    private array $projects = [];

    /** @var list<string> */
    private array $itemTypes = ['Bug'];

    /** @var list<UserDto> */
    private array $assignees = [];

    /** @var array<string, WorkItemDto> */
    private array $workItems = [];

    /**
     * The fake-tracker.token value each reconciliation search ran with, so tests can tell
     * whose credential (system or a user's) a search used.
     *
     * @var list<?string>
     */
    public array $reconciliationTokens = [];

    /** @var list<ReconciliationCandidateDto> */
    private array $reconciliationCandidates = [];

    private bool $reconciliationFails = false;

    private ?CarbonImmutable $reconciliationRateLimitResetsAt = null;

    private bool $connectionOk = true;

    private bool $fetchProjectsFails = false;

    public function __construct(private readonly string $id = 'fake-tracker') {}

    public function id(): string
    {
        return $this->id;
    }

    public function displayName(): string
    {
        return 'Fake Tracker';
    }

    public function capabilities(): TrackerCapabilities
    {
        return new TrackerCapabilities(
            supportsLabels: true,
            supportsPriority: true,
            supportsAssignee: true,
            supportsParent: true,
            supportedItemTypes: ['Bug', 'Task'],
            maxDescriptionBytes: 16384,
        );
    }

    /** @return list<CredentialField> */
    public function credentialFields(): array
    {
        return [
            new CredentialField(key: "{$this->id}.token", label: 'Token', isSecret: true, required: true),
        ];
    }

    public function testConnection(): TestResult
    {
        return $this->connectionOk ? TestResult::success() : TestResult::failure('connection refused');
    }

    /** @return iterable<ProjectDto> */
    public function fetchProjects(): iterable
    {
        $this->fetchProjectsCalls++;

        if ($this->fetchProjectsFails) {
            throw new \RuntimeException('Failed to list projects');
        }

        return $this->projects;
    }

    /** @return iterable<string> */
    public function fetchItemTypes(string $projectKey): iterable
    {
        return $this->itemTypes;
    }

    /** @return iterable<string> */
    public function fetchPriorities(string $projectKey): iterable
    {
        return [];
    }

    /** @return iterable<UserDto> */
    public function fetchAssigneeCandidates(string $projectKey, string $query): iterable
    {
        return array_values(array_filter(
            $this->assignees,
            fn (UserDto $user): bool => str_contains(strtolower($user->displayName), strtolower($query)),
        ));
    }

    public function createWorkItem(CreateWorkItemRequest $request): WorkItemDto
    {
        $this->createCalls++;
        $this->latestCreateWorkItemRequest = $request;

        $workItem = new WorkItemDto(
            id: sprintf('%s#%d', $request->projectKey, count($this->workItems) + 1),
            projectKey: $request->projectKey,
            title: $request->title,
            state: 'Open',
            url: sprintf('https://tracker.test/%s', rawurlencode(sprintf('%s#%d', $request->projectKey, count($this->workItems) + 1))),
            itemType: $request->itemType,
            priority: $request->priority,
            parentId: $request->parentId,
            labels: $request->labels,
            description: $request->description,
        );

        $this->workItems[$workItem->id] = $workItem;

        return $workItem;
    }

    public function getWorkItem(string $workItemKey): ?WorkItemDto
    {
        $this->getCalls++;

        return $this->workItems[$workItemKey] ?? null;
    }

    public function updateWorkItem(string $workItemKey, UpdateWorkItemRequest $request): WorkItemDto
    {
        $current = $this->workItems[$workItemKey] ?? throw new \RuntimeException('Missing work item');

        $updated = new WorkItemDto(
            id: $current->id,
            projectKey: $current->projectKey,
            title: $request->title ?? $current->title,
            state: $request->state ?? $current->state,
            url: $current->url,
            itemType: $current->itemType,
            priority: $request->priority ?? $current->priority,
            assignee: $current->assignee,
            parentId: $request->parentId ?? $current->parentId,
            labels: $request->labels ?? $current->labels,
            description: $request->description ?? $current->description,
        );

        $this->workItems[$workItemKey] = $updated;

        return $updated;
    }

    public function addComment(string $workItemKey, string $markdown): void
    {
        if ($this->commentFailure !== null) {
            throw new \RuntimeException($this->commentFailure);
        }

        $this->comments[] = ['key' => $workItemKey, 'markdown' => $markdown];
    }

    public function withCommentFailure(string $message): self
    {
        $this->commentFailure = $message;

        return $this;
    }

    /** @return iterable<WorkItemDto> */
    public function searchWorkItems(string $projectKey, string $query, int $limit = 20): iterable
    {
        $this->searchCalls++;

        return array_slice(array_values(array_filter(
            $this->workItems,
            fn (WorkItemDto $workItem): bool => $workItem->projectKey === $projectKey
                && str_contains(strtolower($workItem->title), strtolower($query)),
        )), 0, $limit);
    }

    /** @return iterable<ReconciliationCandidateDto> */
    public function reconciliationCandidates(): iterable
    {
        $this->reconciliationCalls++;
        $this->reconciliationTokens[] = app(Vault::class)->get("{$this->id}.token");

        if ($this->reconciliationRateLimitResetsAt !== null) {
            throw new TrackerRateLimitExceededException('Rate limit exceeded', $this->reconciliationRateLimitResetsAt);
        }

        if ($this->reconciliationFails) {
            throw new \RuntimeException('Reconciliation search failed');
        }

        return $this->reconciliationCandidates;
    }

    public function withProjects(ProjectDto ...$projects): self
    {
        $this->projects = $projects;

        return $this;
    }

    public function withItemTypes(string ...$itemTypes): self
    {
        $this->itemTypes = $itemTypes;

        return $this;
    }

    public function withAssignees(UserDto ...$assignees): self
    {
        $this->assignees = $assignees;

        return $this;
    }

    public function withConnectionFailure(): self
    {
        $this->connectionOk = false;

        return $this;
    }

    public function withExistingWorkItem(WorkItemDto $workItem): self
    {
        $this->workItems[$workItem->id] = $workItem;

        return $this;
    }

    public function withReconciliationCandidates(ReconciliationCandidateDto ...$candidates): self
    {
        $this->reconciliationCandidates = $candidates;

        return $this;
    }

    public function withReconciliationFailure(): self
    {
        $this->reconciliationFails = true;

        return $this;
    }

    public function withReconciliationRateLimit(CarbonImmutable $resetsAt): self
    {
        $this->reconciliationRateLimitResetsAt = $resetsAt;

        return $this;
    }

    public function withFetchProjectsFailure(): self
    {
        $this->fetchProjectsFails = true;

        return $this;
    }
}
