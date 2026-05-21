<?php

namespace App\Trackers;

use App\Models\SecurityEvent;
use App\Trackers\Contracts\Tracker;
use BackedEnum;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\Facades\Cache;

final class WorkItemFormOptions
{
    public function __construct(private readonly Registry $registry) {}

    /** @return array<string, string> */
    public function trackerOptions(): array
    {
        $options = [];

        foreach ($this->registry->enabled() as $tracker) {
            $options[$tracker->id()] = $tracker->displayName();
        }

        return $options;
    }

    /**
     * @param  list<SecurityEvent>  $events
     * @return array<int, Radio|Select|TagsInput|TextInput>
     */
    public function createSchema(array $events = []): array
    {
        return [
            Radio::make('tracker')
                ->label('Tracker')
                ->options($this->trackerOptions())
                ->required()
                ->live(),
            Select::make('project')
                ->label('Project')
                ->required()
                ->searchable()
                ->preload()
                ->options(fn (Get $get): array => $this->projectOptions($get->string('tracker', isNullable: true)))
                ->live(),
            Select::make('item_type')
                ->label('Item type')
                ->required()
                ->options(fn (Get $get): array => $this->itemTypeOptions(
                    $get->string('tracker', isNullable: true),
                    $get->string('project', isNullable: true),
                ))
                ->live(),
            TextInput::make('priority')
                ->label('Priority')
                ->visible(fn (Get $get): bool => $this->trackerSupportsPriority($get->string('tracker', isNullable: true)))
                ->maxLength(255),
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

    /** @return array<int, Radio|Select|TextInput> */
    public function linkSchema(): array
    {
        return [
            Radio::make('tracker')
                ->label('Tracker')
                ->options($this->trackerOptions())
                ->required()
                ->live(),
            Select::make('project')
                ->label('Project')
                ->required()
                ->searchable()
                ->preload()
                ->options(fn (Get $get): array => $this->projectOptions($get->string('tracker', isNullable: true)))
                ->live(),
            Select::make('selected_work_item')
                ->label('Work item')
                ->required()
                ->searchable()
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

        if (! $tracker instanceof Tracker) {
            return [];
        }

        /** @var array<string, string> $projects */
        $projects = Cache::remember("trackers:{$trackerId}:projects", now()->addHour(), function () use ($tracker): array {
            $resolved = [];

            foreach ($tracker->fetchProjects() as $project) {
                $resolved[$project->key] = $project->name;
            }

            return $resolved;
        });

        return $projects;
    }

    /** @return array<string, string> */
    private function itemTypeOptions(?string $trackerId, ?string $projectKey): array
    {
        $tracker = $this->tracker($trackerId);

        if (! $tracker instanceof Tracker || blank($projectKey)) {
            return [];
        }

        $types = Cache::remember("trackers:{$trackerId}:{$projectKey}:item-types", now()->addHour(), fn (): array => iterator_to_array($tracker->fetchItemTypes($projectKey), false));

        return collect($types)
            ->filter(fn (string $type): bool => $type !== '')
            ->mapWithKeys(fn (string $type): array => [$type => $type])
            ->all();
    }

    /** @return array<string, string> */
    private function assigneeOptions(?string $trackerId, ?string $projectKey, string $search): array
    {
        $tracker = $this->tracker($trackerId);

        if (! $tracker instanceof Tracker || blank($projectKey)) {
            return [];
        }

        $resolved = [];

        foreach ($tracker->fetchAssigneeCandidates($projectKey, $search) as $user) {
            $resolved[$user->id] = $user->displayName;
        }

        return $resolved;
    }

    private function assigneeLabel(?string $trackerId, ?string $projectKey, string $value): string
    {
        return $this->assigneeOptions($trackerId, $projectKey, $value)[$value] ?? $value;
    }

    /** @return array<string, string> */
    private function workItemOptions(?string $trackerId, ?string $projectKey, string $search): array
    {
        $tracker = $this->tracker($trackerId);

        if (! $tracker instanceof Tracker || blank($projectKey)) {
            return [];
        }

        $resolved = [];

        foreach ($tracker->searchWorkItems($projectKey, $search, 20) as $workItem) {
            $resolved[$workItem->id] = sprintf('%s (%s)', $workItem->title, $workItem->id);
        }

        return $resolved;
    }

    private function workItemLabel(?string $trackerId, string $workItemId): string
    {
        $tracker = $this->tracker($trackerId);

        if (! $tracker instanceof Tracker) {
            return $workItemId;
        }

        $workItem = $tracker->getWorkItem($workItemId);

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

        return $this->registry->find($trackerId);
    }

    private function backedEnumValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
