<?php

namespace App\Filament\Resources\SecurityEventResource\Pages;

use App\Filament\Resources\SecurityEventResource;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Sources\Dto\EventDto;
use App\Sync\RefetchEventJob;
use App\Trackers\CreateWorkItemJob;
use App\Trackers\ReconcileEventJob;
use App\Trackers\WorkItemFormOptions;
use App\Trackers\WorkItemService;
use App\Triage\AttachmentService;
use App\Triage\RunBfgJob;
use App\Triage\RunCodesearchJob;
use App\Triage\RunTrivyJob;
use App\Triage\SeverityChanger;
use App\Triage\StateChanger;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Livewire\WithFileUploads;

class ViewSecurityEvent extends ViewRecord
{
    use WithFileUploads;

    protected static string $resource = SecurityEventResource::class;

    /** @return array<Action|ActionGroup> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('changeState')
                ->label('Change state')
                ->icon('heroicon-o-pencil-square')
                ->visible(fn (): bool => Gate::allows('alerts.edit'))
                ->form(SecurityEventResource::stateChangeForm())
                ->action(fn (array $data): bool => $this->changeState(
                    EventState::from((string) $data['new_state']),
                    (string) $data['comment'],
                )),
            Action::make('changeSeverity')
                ->label('Change severity')
                ->icon('heroicon-o-adjustments-horizontal')
                ->visible(fn (): bool => Gate::allows('alerts.edit'))
                ->form(SecurityEventResource::severityChangeForm())
                ->action(fn (array $data): bool => $this->changeSeverity(
                    EventSeverity::from((string) $data['new_severity']),
                    (string) $data['comment'],
                )),
            Action::make('reloadFromSource')
                ->label('Reload from source')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (): bool => Gate::allows('work-items.sync'))
                ->requiresConfirmation()
                ->modalDescription(fn (): string => $this->eventRecord()->is_dirty
                    ? 'Local pending changes will be preserved; upstream metadata will be refreshed.'
                    : 'Reload this alert from its source now?')
                ->action(fn () => $this->reloadFromSource()),
            Action::make('createWorkItem')
                ->label('Create work item')
                ->icon('heroicon-o-ticket')
                ->visible(fn (): bool => Gate::allows('work-items.create'))
                ->form(fn (): array => app(WorkItemFormOptions::class)->createSchema([$this->eventRecord()]))
                ->action(fn (array $data): bool => $this->queueCreateWorkItem($data)),
            Action::make('linkExisting')
                ->label('Link existing')
                ->icon('heroicon-o-link')
                ->visible(fn (): bool => Gate::allows('work-items.link'))
                ->form(fn (): array => app(WorkItemFormOptions::class)->linkSchema())
                ->action(fn (array $data): bool => $this->linkExistingWorkItem($data)),
            Action::make('reconcileWorkItems')
                ->label('Reconcile work items')
                ->icon('heroicon-o-arrows-pointing-in')
                ->visible(fn (): bool => Gate::allows('work-items.link'))
                ->requiresConfirmation()
                ->modalDescription('Queue a reconciliation job to find and create missing links for this alert.')
                ->action(fn (): bool => $this->dispatchReconcileEvent()),
            ActionGroup::make([
                Action::make('runTrivy')
                    ->label('Run Trivy')
                    ->icon('heroicon-o-bug-ant')
                    ->visible(fn (): bool => Gate::allows('triage.run-trivy'))
                    ->form([
                        TextInput::make('git_url')
                            ->label('Repository URL')
                            ->default(fn (): ?string => $this->repositoryUrl())
                            ->required(),
                    ])
                    ->action(fn (array $data): bool => $this->dispatchTrivy($data)),
                Action::make('runBfg')
                    ->label('Run BFG')
                    ->icon('heroicon-o-wrench-screwdriver')
                    ->visible(fn (): bool => Gate::allows('triage.run-bfg'))
                    ->form([
                        TextInput::make('git_url')
                            ->label('Repository URL')
                            ->default(fn (): ?string => $this->repositoryUrl())
                            ->required(),
                        FileUpload::make('secret_list_file')
                            ->label('Secret list')
                            ->disk('local')
                            ->directory('triage-uploads')
                            ->required(),
                    ])
                    ->action(fn (array $data): bool => $this->dispatchBfg($data)),
                Action::make('runCodesearch')
                    ->label('Run Code Search')
                    ->icon('heroicon-o-magnifying-glass')
                    ->visible(fn (): bool => Gate::allows('triage.run-codesearch'))
                    ->form([
                        TextInput::make('query')
                            ->label('Query')
                            ->required(),
                        TextInput::make('scope')
                            ->label('Scope')
                            ->helperText('Optional. Use project:<name> or repo:<name>.'),
                    ])
                    ->action(fn (array $data): bool => $this->dispatchCodesearch($data)),
            ])
                ->label('Run triage')
                ->icon('heroicon-o-play'),
            Action::make('addAttachment')
                ->label('Add attachment')
                ->icon('heroicon-o-paper-clip')
                ->visible(fn (): bool => Gate::allows('work-items.create'))
                ->form([
                    FileUpload::make('attachment_file')
                        ->label('File')
                        ->disk('local')
                        ->directory('attachments-upload')
                        ->required(),
                    TextInput::make('attachment_name')
                        ->label('Name')
                        ->maxLength(255),
                ])
                ->action(fn (array $data): bool => $this->uploadAttachment($data)),
        ];
    }

    public function changeState(EventState $newState, string $comment): bool
    {
        Gate::authorize('alerts.edit');

        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            abort(403);
        }

        app(StateChanger::class)->change($this->eventRecord(), $user, $newState, $comment);
        $this->refreshFormData([]);

        Notification::make()->title('State change queued for sync review')->success()->send();

        return true;
    }

    public function changeSeverity(EventSeverity $newSeverity, string $comment): bool
    {
        Gate::authorize('alerts.edit');

        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            abort(403);
        }

        app(SeverityChanger::class)->change($this->eventRecord(), $user, $newSeverity, $comment);
        $this->refreshFormData([]);

        Notification::make()->title('Severity change queued for sync review')->success()->send();

        return true;
    }

    private function reloadFromSource(): void
    {
        RefetchEventJob::dispatch($this->eventRecord()->id);

        Notification::make()->title('Alert refresh queued')->success()->send();
    }

    /** @param array<string, mixed> $data */
    private function uploadAttachment(array $data): bool
    {
        Gate::authorize('work-items.create');

        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            abort(403);
        }

        $relativePath = (string) $data['attachment_file'];
        $fullPath = storage_path('app/' . $relativePath);

        if (! file_exists($fullPath)) {
            Notification::make()->title('Uploaded file not found')->danger()->send();

            return false;
        }

        $fileContent = file_get_contents($fullPath);

        if ($fileContent === false) {
            Notification::make()->title('Could not read uploaded file')->danger()->send();

            return false;
        }

        $name = is_string($data['attachment_name'] ?? null) && trim((string) $data['attachment_name']) !== ''
            ? trim((string) $data['attachment_name'])
            : basename($relativePath);

        $mime = mime_content_type($fullPath) ?: 'application/octet-stream';

        app(AttachmentService::class)->attachToEvent(
            event: $this->eventRecord(),
            kind: 'manual',
            mime: $mime,
            name: $name,
            payload: $fileContent,
            createdByUserId: $user->id,
        );

        @unlink($fullPath);

        Notification::make()->title('Attachment added')->success()->send();

        return true;
    }

    /** @param array<string, mixed> $data */
    private function dispatchTrivy(array $data): bool
    {
        Gate::authorize('triage.run-trivy');

        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            abort(403);
        }

        RunTrivyJob::dispatch($this->eventRecord()->id, (string) $data['git_url'], $user->id);

        Notification::make()->title('Trivy run queued')->success()->send();

        return true;
    }

    /** @param array<string, mixed> $data */
    private function dispatchBfg(array $data): bool
    {
        Gate::authorize('triage.run-bfg');

        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            abort(403);
        }

        $secretListPath = storage_path('app/' . (string) $data['secret_list_file']);

        RunBfgJob::dispatch($this->eventRecord()->id, (string) $data['git_url'], $secretListPath, $user->id);

        Notification::make()->title('BFG run queued')->success()->send();

        return true;
    }

    /** @param array<string, mixed> $data */
    private function dispatchCodesearch(array $data): bool
    {
        Gate::authorize('triage.run-codesearch');

        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            abort(403);
        }

        RunCodesearchJob::dispatch(
            $this->eventRecord()->id,
            (string) $data['query'],
            $this->nullableString($data['scope'] ?? null),
            $user->id,
        );

        Notification::make()->title('Code search queued')->success()->send();

        return true;
    }

    /** @param array<string, mixed> $data */
    private function queueCreateWorkItem(array $data): bool
    {
        Gate::authorize('work-items.create');

        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            abort(403);
        }

        CreateWorkItemJob::dispatch(
            eventIds: [$this->eventRecord()->id],
            userId: $user->id,
            trackerId: (string) $data['tracker'],
            projectKey: (string) $data['project'],
            itemType: (string) $data['item_type'],
            labels: SecurityEventResource::stringArray($data['labels'] ?? []),
            priority: $this->nullableString($data['priority'] ?? null),
            assigneeId: $this->nullableString($data['assignee_id'] ?? null),
            parentId: $this->nullableString($data['parent_id'] ?? null),
        );

        Notification::make()->title('Work item creation queued')->success()->send();

        return true;
    }

    /** @param array<string, mixed> $data */
    private function linkExistingWorkItem(array $data): bool
    {
        Gate::authorize('work-items.link');

        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            abort(403);
        }

        app(WorkItemService::class)->linkExisting(
            eventIds: [$this->eventRecord()->id],
            userId: $user->id,
            trackerId: (string) $data['tracker'],
            workItemId: (string) $data['selected_work_item'],
            projectKey: (string) ($data['project'] ?? ''),
        );

        $this->refreshFormData([]);
        Notification::make()->title('Work item linked')->success()->send();

        return true;
    }

    private function dispatchReconcileEvent(): bool
    {
        Gate::authorize('work-items.link');

        ReconcileEventJob::dispatch($this->eventRecord()->id);

        Notification::make()->title('Work item reconciliation queued for this alert')->success()->send();

        return true;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function repositoryUrl(): ?string
    {
        /** @var array<string, mixed>|null $metadata */
        $metadata = $this->eventRecord()->getAttribute('metadata');

        if ($metadata === null) {
            return null;
        }

        $value = $metadata['repository_url'] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function eventRecord(): SecurityEvent
    {
        /** @var SecurityEvent $record */
        $record = $this->getRecord();

        return $record;
    }

    private function applyEventDto(SecurityEvent $record, EventDto $dto): void
    {
        $rawMetadata = $record->getRawOriginal('metadata');
        $metadata = [];

        if (is_string($rawMetadata) && $rawMetadata !== '') {
            /** @var mixed $decoded */
            $decoded = json_decode($rawMetadata, true);
            if (is_array($decoded)) {
                $metadata = $decoded;
            }
        }

        $incomingMetadata = is_array($dto->metadata) ? $dto->metadata : [];

        if (array_key_exists('local', $metadata)) {
            $incomingMetadata['local'] = $metadata['local'];
        }

        $record->fill([
            'title' => $dto->title,
            'description' => $dto->description,
            'severity' => $dto->severity,
            'state' => $dto->state,
            'type' => $dto->type,
            'rule_id' => $dto->ruleId,
            'fingerprint' => $dto->fingerprint,
            'url' => $dto->url,
            'remediation' => $dto->remediation ?? $record->remediation,
            'file_path' => $dto->filePath,
            'start_line' => $dto->startLine,
            'end_line' => $dto->endLine,
            'snippet' => $dto->snippet,
            'commit_sha' => $dto->commitSha,
            'branch' => $dto->branch,
            'version_control_url' => $dto->versionControlUrl,
            'source_data' => $dto->sourceData,
            'metadata' => $incomingMetadata,
            'first_seen_at' => $dto->firstSeenAt ?? $record->first_seen_at,
            'last_seen_at' => $dto->lastSeenAt ?? $record->last_seen_at,
            'synced_at' => now(),
            'updated_at' => now(),
        ]);

        $record->save();
    }
}
