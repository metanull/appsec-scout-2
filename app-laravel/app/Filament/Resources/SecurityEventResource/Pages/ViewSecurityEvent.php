<?php

namespace App\Filament\Resources\SecurityEventResource\Pages;

use App\Filament\Pages\ProfileIntegrationsPage;
use App\Filament\Resources\SecurityEventResource;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\TrackerProjectLink;
use App\Models\User;
use App\Sync\RefetchEventJob;
use App\Trackers\Reconciliation\ReconciliationService;
use App\Trackers\WorkItemFormOptions;
use App\Trackers\WorkItemService;
use App\Triage\AttachmentService;
use App\Triage\SeverityChanger;
use App\Triage\StateChanger;
use Filament\Actions\Action;
use Filament\Actions\Action as FilamentAction;
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

    /** @return array<Action> */
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
                ->form(fn (): array => app(WorkItemFormOptions::class)->linkSchema([$this->eventRecord()]))
                ->action(fn (array $data): bool => $this->linkExistingWorkItem($data)),
            Action::make('reconcileWorkItems')
                ->label('Find existing work items')
                ->icon('heroicon-o-arrows-pointing-in')
                ->visible(fn (): bool => Gate::allows('work-items.link') || Gate::allows('work-items.sync'))
                ->requiresConfirmation()
                ->modalDescription('Search configured tracker projects and link any existing work items that match this alert.')
                ->action(fn (): bool => $this->dispatchReconcileEvent()),
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
    private function queueCreateWorkItem(array $data): bool
    {
        Gate::authorize('work-items.create');

        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            abort(403);
        }

        $trackerId = (string) $data['tracker'];
        $missing = app(WorkItemFormOptions::class)->missingCredentialLabelsForTracker($trackerId);

        if ($missing !== []) {
            $this->notifyMissingPersonalCredentials($trackerId, $missing);

            return false;
        }

        app(WorkItemService::class)->createForEvents(
            eventIds: [$this->eventRecord()->id],
            userId: $user->id,
            trackerId: $trackerId,
            projectKey: (string) $data['project'],
            itemType: (string) $data['item_type'],
            labels: SecurityEventResource::stringArray($data['labels'] ?? []),
            priority: $this->nullableString($data['priority'] ?? null),
            assigneeId: $this->nullableString($data['assignee_id'] ?? null),
            parentId: $this->nullableString($data['parent_id'] ?? null),
        );

        $this->refreshFormData([]);
        Notification::make()->title('Work item created')->success()->send();

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

        $trackerId = (string) $data['tracker'];
        $missing = app(WorkItemFormOptions::class)->missingCredentialLabelsForTracker($trackerId);

        if ($missing !== []) {
            $this->notifyMissingPersonalCredentials($trackerId, $missing);

            return false;
        }

        app(WorkItemService::class)->linkExisting(
            eventIds: [$this->eventRecord()->id],
            userId: $user->id,
            trackerId: $trackerId,
            workItemId: (string) $data['selected_work_item'],
            projectKey: (string) ($data['project'] ?? ''),
        );

        $this->refreshFormData([]);
        Notification::make()->title('Work item linked')->success()->send();

        return true;
    }

    private function dispatchReconcileEvent(): bool
    {
        if (! Gate::allows('work-items.link') && ! Gate::allows('work-items.sync')) {
            abort(403);
        }

        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            abort(403);
        }

        if (! $this->hasApplicableTrackerMappings($this->eventRecord())) {
            Notification::make()
                ->title('No tracker project mapping for this system or container')
                ->body('Searching every configured tracker project instead of a scoped one — results may be less precise. Add a Tracker Project Link to this alert\'s system or container to narrow this search next time.')
                ->warning()
                ->send();
        }

        $results = app(ReconciliationService::class)->reconcileEvent($this->eventRecord(), $user->id);
        $newLinks = count(array_filter($results, fn ($result): bool => $result->alreadyLinked === false));

        if ($newLinks > 0) {
            Notification::make()
                ->title("{$newLinks} new work item links found")
                ->success()
                ->send();
        } else {
            Notification::make()
                ->title('No new links found. Existing links are up to date.')
                ->info()
                ->send();
        }

        $this->refreshFormData([]);

        return true;
    }

    private function hasApplicableTrackerMappings(SecurityEvent $event): bool
    {
        $softwareSystemId = $event->getAttribute('software_system_id');
        $containerId = $event->getAttribute('container_id');

        $query = TrackerProjectLink::query();

        $query->where(function ($scope) use ($softwareSystemId, $containerId): void {
            if (is_int($softwareSystemId)) {
                $scope->orWhere(function ($inner) use ($softwareSystemId): void {
                    $inner->where('owner_type', SoftwareSystem::class)
                        ->where('owner_id', $softwareSystemId);
                });
            }

            if (is_int($containerId)) {
                $scope->orWhere(function ($inner) use ($containerId): void {
                    $inner->where('owner_type', SecurityContainer::class)
                        ->where('owner_id', $containerId);
                });
            }
        });

        return $query->exists();
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    private function eventRecord(): SecurityEvent
    {
        /** @var SecurityEvent $record */
        $record = $this->getRecord();

        return $record;
    }

    /** @param list<string> $missing */
    private function notifyMissingPersonalCredentials(string $trackerId, array $missing): void
    {
        $fields = implode(', ', $missing);

        Notification::make()
            ->title('Personal tracker credentials required')
            ->body("{$trackerId} is missing personal credentials: {$fields}.")
            ->warning()
            ->actions([
                FilamentAction::make('openProfileIntegrations')
                    ->label('Open profile integrations')
                    ->url(ProfileIntegrationsPage::getUrl()),
            ])
            ->send();
    }
}
