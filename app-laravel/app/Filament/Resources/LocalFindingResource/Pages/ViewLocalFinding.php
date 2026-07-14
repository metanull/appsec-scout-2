<?php

namespace App\Filament\Resources\LocalFindingResource\Pages;

use App\Assets\LocalFindingSeverityChanger;
use App\Assets\LocalFindingStatusChanger;
use App\Assets\LocalFindingWorkItemService;
use App\Filament\Pages\ProfileIntegrationsPage;
use App\Filament\Resources\LocalFindingResource;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\LocalFinding;
use App\Models\User;
use App\Trackers\WorkItemFormOptions;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;

class ViewLocalFinding extends ViewRecord
{
    protected static string $resource = LocalFindingResource::class;

    /** @return array<Action> */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('changeStatus')
                ->label('Change status')
                ->icon('heroicon-o-pencil-square')
                ->visible(fn (): bool => Gate::allows('alerts.edit'))
                ->form(LocalFindingResource::statusChangeForm())
                ->action(fn (array $data): bool => $this->changeStatus(
                    EventState::from((string) $data['new_status']),
                    (string) $data['comment'],
                )),
            Action::make('changeSeverity')
                ->label('Change severity')
                ->icon('heroicon-o-adjustments-horizontal')
                ->visible(fn (): bool => Gate::allows('alerts.edit'))
                ->form(LocalFindingResource::severityChangeForm())
                ->action(fn (array $data): bool => $this->changeSeverity(
                    EventSeverity::from((string) $data['new_severity']),
                    (string) $data['comment'],
                )),
            Action::make('createWorkItem')
                ->label('Create work item')
                ->icon('heroicon-o-ticket')
                ->visible(fn (): bool => Gate::allows('work-items.create'))
                ->form(fn (): array => app(WorkItemFormOptions::class)->createSchema())
                ->action(fn (array $data): bool => $this->queueCreateWorkItem($data)),
            Action::make('linkExisting')
                ->label('Link existing')
                ->icon('heroicon-o-link')
                ->visible(fn (): bool => Gate::allows('work-items.link'))
                ->form(fn (): array => app(WorkItemFormOptions::class)->linkSchema())
                ->action(fn (array $data): bool => $this->linkExistingWorkItem($data)),
        ];
    }

    public function changeStatus(EventState $newStatus, string $comment): bool
    {
        Gate::authorize('alerts.edit');

        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            abort(403);
        }

        app(LocalFindingStatusChanger::class)->change($this->findingRecord(), $user, $newStatus, $comment);
        $this->refreshFormData([]);

        Notification::make()->title('Status changed')->success()->send();

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

        app(LocalFindingSeverityChanger::class)->change($this->findingRecord(), $user, $newSeverity, $comment);
        $this->refreshFormData([]);

        Notification::make()->title('Severity changed')->success()->send();

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

        app(LocalFindingWorkItemService::class)->createForFinding(
            finding: $this->findingRecord(),
            userId: $user->id,
            trackerId: $trackerId,
            projectKey: (string) $data['project'],
            itemType: (string) $data['item_type'],
            labels: $this->stringArray($data['labels'] ?? []),
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

        try {
            app(LocalFindingWorkItemService::class)->linkExisting(
                finding: $this->findingRecord(),
                userId: $user->id,
                trackerId: $trackerId,
                workItemId: (string) $data['selected_work_item'],
                projectKey: (string) ($data['project'] ?? ''),
            );
        } catch (\RuntimeException $exception) {
            Notification::make()->title($exception->getMessage())->danger()->send();

            return false;
        }

        $this->refreshFormData([]);
        Notification::make()->title('Work item linked')->success()->send();

        return true;
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        return trim($value);
    }

    /** @return list<string> */
    private function stringArray(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter($value, fn (mixed $item): bool => is_string($item) && $item !== ''));
    }

    private function findingRecord(): LocalFinding
    {
        /** @var LocalFinding $record */
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
                Action::make('openProfileIntegrations')
                    ->label('Open profile integrations')
                    ->url(ProfileIntegrationsPage::getUrl()),
            ])
            ->send();
    }
}
