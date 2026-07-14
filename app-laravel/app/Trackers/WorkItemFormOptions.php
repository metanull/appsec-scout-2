<?php

namespace App\Trackers;

use App\Filament\Pages\ProfileIntegrationsPage;
use App\Integrations\OperatorIntegrationRuntime;
use App\Models\SecurityEvent;
use App\Trackers\Contracts\Tracker;
use App\Trackers\Defaults\TrackerProjectDefaultResolution;
use App\Trackers\Defaults\TrackerProjectDefaultResolver;
use BackedEnum;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

final class WorkItemFormOptions
{
    private bool $loggedMissingRegisteredTrackers = false;

    public function __construct(
        private readonly OperatorIntegrationRuntime $runtime,
        private readonly TrackerProjectDefaultResolver $trackerProjectDefaultResolver,
    ) {}

    /** @return array<string, string> */
    public function trackerOptions(): array
    {
        $options = [];

        foreach ($this->runtime->trackers() as $tracker) {
            $options[$tracker->id()] = $tracker->displayName();
        }

        if ($options === []) {
            $this->logNoRegisteredTrackers();
        }

        return $options;
    }

    /**
     * @param  list<SecurityEvent>  $events
     * @return array<int, Placeholder|Select|TagsInput>
     */
    public function createSchema(array $events = []): array
    {
        $formDefault = $this->resolveTrackerDefault($events);
        $ambiguityWarnings = $this->resolveTrackerAmbiguityWarnings($events);

        return [
            Placeholder::make('enabled_tracker_notice')
                ->label('Tracker availability')
                ->content('No trackers are registered. Configure at least one tracker before creating a work item.')
                ->visible(fn (): bool => $this->trackerOptions() === [])
                ->columnSpanFull(),
            Select::make('tracker')
                ->label('Tracker')
                ->options($this->trackerOptions())
                ->placeholder('Select a tracker')
                ->default($formDefault?->trackerId)
                ->searchable()
                ->preload()
                ->required()
                ->disabled(fn (): bool => $this->trackerOptions() === [])
                ->live()
                ->afterStateUpdated(function (Set $set): void {
                    $set('project', null);
                    $set('item_type', null);
                    $set('assignee_id', null);
                    $set('parent_id', null);
                }),
            Placeholder::make('tracker_default_notice')
                ->label('Default project')
                ->content($this->trackerDefaultNotice($formDefault))
                ->visible($formDefault instanceof TrackerProjectDefaultResolution)
                ->columnSpanFull(),
            Placeholder::make('tracker_ambiguity_notice')
                ->label('Tracker project mapping')
                ->content($this->trackerAmbiguityNotice($ambiguityWarnings))
                ->visible($ambiguityWarnings !== [])
                ->columnSpanFull(),
            Placeholder::make('tracker_credential_notice')
                ->label('Credential setup')
                ->content(fn (Get $get): ?string => $this->trackerCredentialNotice($get->string('tracker', isNullable: true)))
                ->visible(fn (Get $get): bool => $this->trackerCredentialNotice($get->string('tracker', isNullable: true)) !== null)
                ->columnSpanFull(),
            Select::make('project')
                ->label('Project')
                ->required()
                ->searchable()
                ->preload()
                ->default($formDefault?->projectKey)
                ->placeholder('Select a tracker first')
                ->disabled(fn (Get $get): bool => blank($get->string('tracker', isNullable: true)))
                ->helperText(fn (Get $get): ?string => $this->trackerCredentialNotice($get->string('tracker', isNullable: true)))
                ->options(fn (Get $get): array => $this->projectOptions($get->string('tracker', isNullable: true)))
                ->live()
                ->afterStateUpdated(fn (Set $set): mixed => $set('item_type', null)),
            Select::make('item_type')
                ->label('Item type')
                ->required()
                ->placeholder('Select tracker and project first')
                ->disabled(fn (Get $get): bool => blank($get->string('tracker', isNullable: true)) || blank($get->string('project', isNullable: true)))
                ->options(fn (Get $get): array => $this->itemTypeOptions(
                    $get->string('tracker', isNullable: true),
                    $get->string('project', isNullable: true),
                ))
                ->live(),
            Select::make('priority')
                ->label('Priority')
                ->visible(fn (Get $get): bool => $this->trackerSupportsPriority($get->string('tracker', isNullable: true)))
                ->placeholder('Select a priority (optional)')
                ->options(fn (Get $get): array => $this->priorityOptions(
                    $get->string('tracker', isNullable: true),
                    $get->string('project', isNullable: true),
                )),
            TagsInput::make('labels')
                ->label('Labels')
                ->suggestions($this->labelSuggestions($events))
                ->placeholder('security, appsec-scout, source-id, severity, type')
                ->nestedRecursiveRules(['string', 'max:255']),
            Select::make('assignee_id')
                ->label('Assignee')
                ->searchable()
                ->visible(fn (Get $get): bool => $this->trackerSupportsAssignee($get->string('tracker', isNullable: true)))
                ->getSearchResultsUsing(fn (Get $get, string $search): array => $this->assigneeOptions(
                    $get->string('tracker', isNullable: true),
                    $get->string('project', isNullable: true),
                    $search,
                ))
                ->getOptionLabelUsing(fn (Get $get, string $value): string => $this->assigneeLabel(
                    $get->string('tracker', isNullable: true),
                    $get->string('project', isNullable: true),
                    $value,
                )),
            Select::make('parent_id')
                ->label('Parent work item')
                ->searchable()
                ->visible(fn (Get $get): bool => $this->trackerSupportsParent($get->string('tracker', isNullable: true)))
                ->getSearchResultsUsing(fn (Get $get, string $search): array => $this->workItemOptions(
                    $get->string('tracker', isNullable: true),
                    $get->string('project', isNullable: true),
                    $search,
                ))
                ->getOptionLabelUsing(fn (Get $get, string $value): string => $this->workItemLabel(
                    $get->string('tracker', isNullable: true),
                    $value,
                )),
        ];
    }

    /**
     * @param  list<SecurityEvent>  $events
     * @return array<int, Placeholder|Select>
     */
    public function linkSchema(array $events = []): array
    {
        $formDefault = $this->resolveTrackerDefault($events);
        $ambiguityWarnings = $this->resolveTrackerAmbiguityWarnings($events);

        return [
            Placeholder::make('enabled_tracker_notice')
                ->label('Tracker availability')
                ->content('No trackers are registered. Configure at least one tracker before linking work items.')
                ->visible(fn (): bool => $this->trackerOptions() === [])
                ->columnSpanFull(),
            Select::make('tracker')
                ->label('Tracker')
                ->options($this->trackerOptions())
                ->placeholder('Select a tracker')
                ->default($formDefault?->trackerId)
                ->searchable()
                ->preload()
                ->required()
                ->disabled(fn (): bool => $this->trackerOptions() === [])
                ->live()
                ->afterStateUpdated(function (Set $set): void {
                    $set('project', null);
                    $set('selected_work_item', null);
                }),
            Placeholder::make('tracker_default_notice')
                ->label('Default project')
                ->content($this->trackerDefaultNotice($formDefault))
                ->visible($formDefault instanceof TrackerProjectDefaultResolution)
                ->columnSpanFull(),
            Placeholder::make('tracker_ambiguity_notice')
                ->label('Tracker project mapping')
                ->content($this->trackerAmbiguityNotice($ambiguityWarnings))
                ->visible($ambiguityWarnings !== [])
                ->columnSpanFull(),
            Placeholder::make('tracker_credential_notice')
                ->label('Credential setup')
                ->content(fn (Get $get): ?string => $this->trackerCredentialNotice($get->string('tracker', isNullable: true)))
                ->visible(fn (Get $get): bool => $this->trackerCredentialNotice($get->string('tracker', isNullable: true)) !== null)
                ->columnSpanFull(),
            Select::make('project')
                ->label('Project')
                ->required()
                ->searchable()
                ->preload()
                ->default($formDefault?->projectKey)
                ->placeholder('Select a tracker first')
                ->disabled(fn (Get $get): bool => blank($get->string('tracker', isNullable: true)))
                ->helperText(fn (Get $get): ?string => $this->trackerCredentialNotice($get->string('tracker', isNullable: true)))
                ->options(fn (Get $get): array => $this->projectOptions($get->string('tracker', isNullable: true)))
                ->live()
                ->afterStateUpdated(fn (Set $set): mixed => $set('selected_work_item', null)),
            Select::make('selected_work_item')
                ->label('Work item')
                ->required()
                ->searchable()
                ->placeholder('Select tracker and project first')
                ->disabled(fn (Get $get): bool => blank($get->string('tracker', isNullable: true)) || blank($get->string('project', isNullable: true)))
                ->getSearchResultsUsing(fn (Get $get, string $search): array => $this->workItemOptions(
                    $get->string('tracker', isNullable: true),
                    $get->string('project', isNullable: true),
                    $search,
                ))
                ->getOptionLabelUsing(fn (Get $get, string $value): string => $this->workItemLabel(
                    $get->string('tracker', isNullable: true),
                    $value,
                )),
        ];
    }

    /**
     * @param  list<SecurityEvent>  $events
     */
    public function trackerDefaultForEvents(array $events, string $trackerId): ?TrackerProjectDefaultResolution
    {
        $resolution = $this->trackerProjectDefaultResolver->resolveForEvents($events, $trackerId);

        return $resolution->hasDefault() ? $resolution : null;
    }

    /**
     * @param  list<SecurityEvent>  $events
     * @return list<string>
     */
    private function labelSuggestions(array $events): array
    {
        $suggestions = ['security', 'appsec-scout'];

        foreach ($events as $event) {
            $suggestions[] = $event->source_id;
            $suggestions[] = $this->backedEnumValue($event->severity);
            $suggestions[] = $this->backedEnumValue($event->type);
        }

        return array_values(array_unique(array_filter($suggestions)));
    }

    /** @return array<string, string> */
    private function projectOptions(?string $trackerId): array
    {
        $tracker = $this->tracker($trackerId);
        $operatorUserId = $this->credentialOwnerId();

        if (! $tracker instanceof Tracker || $operatorUserId === null || $this->missingCredentialLabels($tracker) !== []) {
            return [];
        }

        /** @var array<string, string> $projects */
        $projects = Cache::remember('trackers:user:' . $operatorUserId . ':' . $trackerId . ':projects', now()->addHour(), function () use ($tracker, $operatorUserId): array {
            return $this->runtime->runTracker($tracker->id(), $operatorUserId, function (Tracker $tracker): array {
                $resolved = [];

                foreach ($tracker->fetchProjects() as $project) {
                    $resolved[$project->key] = $project->name;
                }

                return $resolved;
            });
        });

        return $projects;
    }

    /** @return array<string, string> */
    private function itemTypeOptions(?string $trackerId, ?string $projectKey): array
    {
        $tracker = $this->tracker($trackerId);
        $operatorUserId = $this->credentialOwnerId();

        if (! $tracker instanceof Tracker || $operatorUserId === null || blank($projectKey) || $this->missingCredentialLabels($tracker) !== []) {
            return [];
        }

        $types = Cache::remember('trackers:user:' . $operatorUserId . ':' . $trackerId . ':' . $projectKey . ':item-types', now()->addHour(), function () use ($tracker, $projectKey, $operatorUserId): array {
            /** @var list<string> $types */
            $types = $this->runtime->runTracker($tracker->id(), $operatorUserId, fn (Tracker $tracker): array => iterator_to_array($tracker->fetchItemTypes($projectKey), false));

            return $types;
        });

        return collect($types)
            ->filter(fn (string $type): bool => $type !== '')
            ->mapWithKeys(fn (string $type): array => [$type => $type])
            ->all();
    }

    /** @return array<string, string> */
    private function priorityOptions(?string $trackerId, ?string $projectKey): array
    {
        $tracker = $this->tracker($trackerId);
        $operatorUserId = $this->credentialOwnerId();

        if (! $tracker instanceof Tracker || $operatorUserId === null || blank($projectKey) || $this->missingCredentialLabels($tracker) !== []) {
            return [];
        }

        /** @var list<string> $priorities */
        $priorities = Cache::remember('trackers:user:' . $operatorUserId . ':' . $trackerId . ':' . $projectKey . ':priorities', now()->addHour(), function () use ($tracker, $operatorUserId, $projectKey): array {
            return $this->runtime->runTracker($tracker->id(), $operatorUserId, fn (Tracker $tracker): array => iterator_to_array($tracker->fetchPriorities($projectKey), false));
        });

        return collect($priorities)
            ->filter(fn (string $p): bool => $p !== '')
            ->mapWithKeys(fn (string $p): array => [$p => $p])
            ->all();
    }

    /** @return array<string, string> */
    private function assigneeOptions(?string $trackerId, ?string $projectKey, string $search): array
    {
        $tracker = $this->tracker($trackerId);
        $operatorUserId = $this->credentialOwnerId();

        if (! $tracker instanceof Tracker || $operatorUserId === null || blank($projectKey) || $this->missingCredentialLabels($tracker) !== []) {
            return [];
        }

        try {
            return $this->runtime->runTracker($tracker->id(), $operatorUserId, function (Tracker $tracker) use ($projectKey, $search): array {
                $resolved = [];

                foreach ($tracker->fetchAssigneeCandidates($projectKey, $search) as $user) {
                    $resolved[$user->id] = $user->displayName;
                }

                return $resolved;
            });
        } catch (\Throwable $e) {
            Log::warning('Tracker assignee search failed', ['tracker_id' => $trackerId, 'project' => $projectKey, 'error' => $e->getMessage()]);
            Notification::make()
                ->title('Tracker lookup failed')
                ->body('Could not fetch assignee candidates from the tracker: ' . $e->getMessage())
                ->danger()
                ->send();

            return [];
        }
    }

    private function assigneeLabel(?string $trackerId, ?string $projectKey, string $value): string
    {
        return $this->assigneeOptions($trackerId, $projectKey, $value)[$value] ?? $value;
    }

    /** @return array<string, string> */
    private function workItemOptions(?string $trackerId, ?string $projectKey, string $search): array
    {
        $tracker = $this->tracker($trackerId);
        $operatorUserId = $this->credentialOwnerId();

        if (! $tracker instanceof Tracker || $operatorUserId === null || blank($projectKey) || $this->missingCredentialLabels($tracker) !== []) {
            return [];
        }

        try {
            return $this->runtime->runTracker($tracker->id(), $operatorUserId, function (Tracker $tracker) use ($projectKey, $search): array {
                $resolved = [];

                foreach ($tracker->searchWorkItems($projectKey, $search, 20) as $workItem) {
                    $resolved[$workItem->id] = sprintf('%s (%s)', $workItem->title, $workItem->id);
                }

                return $resolved;
            });
        } catch (\Throwable $e) {
            Log::warning('Tracker work item search failed', ['tracker_id' => $trackerId, 'project' => $projectKey, 'error' => $e->getMessage()]);
            Notification::make()
                ->title('Tracker lookup failed')
                ->body('Could not search work items from the tracker: ' . $e->getMessage())
                ->danger()
                ->send();

            return [];
        }
    }

    private function workItemLabel(?string $trackerId, string $workItemId): string
    {
        $tracker = $this->tracker($trackerId);
        $operatorUserId = $this->credentialOwnerId();

        if (! $tracker instanceof Tracker || $operatorUserId === null || $this->missingCredentialLabels($tracker) !== []) {
            return $workItemId;
        }

        $workItem = $this->runtime->runTracker($tracker->id(), $operatorUserId, fn (Tracker $tracker) => $tracker->getWorkItem($workItemId));

        if ($workItem === null) {
            return $workItemId;
        }

        return sprintf('%s (%s)', $workItem->title, $workItem->id);
    }

    private function trackerSupportsPriority(?string $trackerId): bool
    {
        return $this->tracker($trackerId)?->capabilities()->supportsPriority ?? false;
    }

    private function trackerSupportsAssignee(?string $trackerId): bool
    {
        return $this->tracker($trackerId)?->capabilities()->supportsAssignee ?? false;
    }

    private function trackerSupportsParent(?string $trackerId): bool
    {
        return $this->tracker($trackerId)?->capabilities()->supportsParent ?? false;
    }

    private function tracker(?string $trackerId): ?Tracker
    {
        if ($trackerId === null || $trackerId === '') {
            return null;
        }

        return $this->runtime->tracker($trackerId);
    }

    private function trackerCredentialNotice(?string $trackerId): ?string
    {
        $tracker = $this->tracker($trackerId);

        if (! $tracker instanceof Tracker) {
            return null;
        }

        $missing = $this->missingCredentialLabels($tracker);

        if ($missing === []) {
            return null;
        }

        $fields = implode(', ', $missing);
        $url = ProfileIntegrationsPage::getUrl();

        return sprintf(
            'Missing personal credentials for %s: %s. Configure them in Profile integrations (%s).',
            $tracker->displayName(),
            $fields,
            $url,
        );
    }

    /** @return list<string> */
    public function missingCredentialLabelsForTracker(?string $trackerId): array
    {
        $tracker = $this->tracker($trackerId);

        if (! $tracker instanceof Tracker) {
            return [];
        }

        $operatorUserId = $this->credentialOwnerId();

        if ($operatorUserId === null) {
            return [];
        }

        return $this->runtime->missingTrackerCredentialLabels($tracker->id(), $operatorUserId);
    }

    /** @return list<string> */
    private function missingCredentialLabels(Tracker $tracker): array
    {
        $operatorUserId = $this->credentialOwnerId();

        if ($operatorUserId === null) {
            return [];
        }

        return $this->runtime->missingTrackerCredentialLabels($tracker->id(), $operatorUserId);
    }

    private function credentialOwnerId(): ?int
    {
        $userId = Auth::id();

        if (is_int($userId)) {
            return $userId;
        }

        if (is_string($userId) && $userId !== '' && ctype_digit($userId)) {
            return (int) $userId;
        }

        return null;
    }

    private function backedEnumValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function logNoRegisteredTrackers(): void
    {
        if ($this->loggedMissingRegisteredTrackers) {
            return;
        }

        $this->loggedMissingRegisteredTrackers = true;

        Log::error('No registered trackers available for work item forms.', [
            'user_id' => $this->credentialOwnerId(),
            'registered_tracker_ids' => [],
        ]);
    }

    /**
     * @param  list<SecurityEvent>  $events
     */
    private function resolveTrackerDefault(array $events): ?TrackerProjectDefaultResolution
    {
        if ($events === []) {
            return null;
        }

        $candidates = [];

        foreach (array_keys($this->trackerOptions()) as $trackerId) {
            $resolution = $this->trackerDefaultForEvents($events, $trackerId);

            if ($resolution instanceof TrackerProjectDefaultResolution) {
                $candidates[] = $resolution;
            }
        }

        if (count($candidates) !== 1) {
            return null;
        }

        return $candidates[0];
    }

    /**
     * Surfaces ambiguous tracker project link configuration (multiple links at one level with
     * no single one marked default) even when a later level still resolved a usable default —
     * an operator relying on that lower-level default should still know a higher level needs
     * attention.
     *
     * @param  list<SecurityEvent>  $events
     * @return list<string>
     */
    private function resolveTrackerAmbiguityWarnings(array $events): array
    {
        if ($events === []) {
            return [];
        }

        $warnings = [];

        foreach (array_keys($this->trackerOptions()) as $trackerId) {
            $resolution = $this->trackerProjectDefaultResolver->resolveForEvents($events, $trackerId);

            if ($resolution->ambiguityWarning !== null && ! in_array($resolution->ambiguityWarning, $warnings, true)) {
                $warnings[] = $resolution->ambiguityWarning;
            }
        }

        return $warnings;
    }

    /** @param list<string> $warnings */
    private function trackerAmbiguityNotice(array $warnings): ?string
    {
        return $warnings === [] ? null : implode(' ', $warnings);
    }

    private function trackerDefaultNotice(?TrackerProjectDefaultResolution $resolution): ?string
    {
        if (! ($resolution instanceof TrackerProjectDefaultResolution)) {
            return null;
        }

        return sprintf(
            'Defaulted from %s to %s.',
            strtolower($resolution->confidenceLabel),
            $resolution->projectKey,
        );
    }
}
