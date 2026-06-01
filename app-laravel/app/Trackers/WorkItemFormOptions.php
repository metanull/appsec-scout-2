<?php

namespace App\Trackers;

use App\Credentials\CredentialField;
use App\Credentials\Vault;
use App\Filament\Pages\ProfileIntegrationsPage;
use App\Models\SecurityEvent;
use App\Trackers\Contracts\Tracker;
use BackedEnum;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TagsInput;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

final class WorkItemFormOptions
{
    public function __construct(
        private readonly Registry $registry,
        private readonly Vault $vault,
    ) {}

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
     * @return array<int, Placeholder|Select|TagsInput|TextInput>
     */
    public function createSchema(array $events = []): array
    {
        return [
            Select::make('tracker')
                ->label('Tracker')
                ->options($this->trackerOptions())
                ->placeholder('Select a tracker')
                ->searchable()
                ->preload()
                ->required()
                ->live()
                ->afterStateUpdated(function (Set $set): void {
                    $set('project', null);
                    $set('item_type', null);
                    $set('assignee_id', null);
                    $set('parent_id', null);
                }),
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

    /** @return array<int, Placeholder|Select|TextInput> */
    public function linkSchema(): array
    {
        return [
            Select::make('tracker')
                ->label('Tracker')
                ->options($this->trackerOptions())
                ->placeholder('Select a tracker')
                ->searchable()
                ->preload()
                ->required()
                ->live()
                ->afterStateUpdated(function (Set $set): void {
                    $set('project', null);
                    $set('selected_work_item', null);
                }),
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

        if (! $tracker instanceof Tracker || $this->missingCredentialLabels($tracker) !== []) {
            return [];
        }

        /** @var array<string, string> $projects */
        $projects = Cache::remember('trackers:user:' . $this->credentialOwnerId() . ':' . $trackerId . ':projects', now()->addHour(), function () use ($tracker): array {
            $resolved = [];

            $this->runAsCredentialOwner(function () use ($tracker, &$resolved): void {
                foreach ($tracker->fetchProjects() as $project) {
                    $resolved[$project->key] = $project->name;
                }
            });

            return $resolved;
        });

        return $projects;
    }

    /** @return array<string, string> */
    private function itemTypeOptions(?string $trackerId, ?string $projectKey): array
    {
        $tracker = $this->tracker($trackerId);

        if (! $tracker instanceof Tracker || blank($projectKey) || $this->missingCredentialLabels($tracker) !== []) {
            return [];
        }

        $types = Cache::remember('trackers:user:' . $this->credentialOwnerId() . ':' . $trackerId . ':' . $projectKey . ':item-types', now()->addHour(), function () use ($tracker, $projectKey): array {
            /** @var list<string> $types */
            $types = $this->runAsCredentialOwner(fn (): array => iterator_to_array($tracker->fetchItemTypes($projectKey), false));

            return $types;
        });

        return collect($types)
            ->filter(fn (string $type): bool => $type !== '')
            ->mapWithKeys(fn (string $type): array => [$type => $type])
            ->all();
    }

    /** @return array<string, string> */
    private function assigneeOptions(?string $trackerId, ?string $projectKey, string $search): array
    {
        $tracker = $this->tracker($trackerId);

        if (! $tracker instanceof Tracker || blank($projectKey) || $this->missingCredentialLabels($tracker) !== []) {
            return [];
        }

        $resolved = [];

        $this->runAsCredentialOwner(function () use ($tracker, $projectKey, $search, &$resolved): void {
            foreach ($tracker->fetchAssigneeCandidates($projectKey, $search) as $user) {
                $resolved[$user->id] = $user->displayName;
            }
        });

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

        if (! $tracker instanceof Tracker || blank($projectKey) || $this->missingCredentialLabels($tracker) !== []) {
            return [];
        }

        $resolved = [];

        $this->runAsCredentialOwner(function () use ($tracker, $projectKey, $search, &$resolved): void {
            foreach ($tracker->searchWorkItems($projectKey, $search, 20) as $workItem) {
                $resolved[$workItem->id] = sprintf('%s (%s)', $workItem->title, $workItem->id);
            }
        });

        return $resolved;
    }

    private function workItemLabel(?string $trackerId, string $workItemId): string
    {
        $tracker = $this->tracker($trackerId);

        if (! $tracker instanceof Tracker || $this->missingCredentialLabels($tracker) !== []) {
            return $workItemId;
        }

        $workItem = $this->runAsCredentialOwner(fn () => $tracker->getWorkItem($workItemId));

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

        return $this->missingCredentialLabels($tracker);
    }

    /** @return list<string> */
    private function missingCredentialLabels(Tracker $tracker): array
    {
        $missing = [];

        foreach ($tracker->credentialFields() as $field) {
            if (! $field->required) {
                continue;
            }

            if (! $this->hasCredentialValue($field)) {
                $missing[] = $field->label;
            }
        }

        return $missing;
    }

    private function hasCredentialValue(CredentialField $field): bool
    {
        $value = $this->runAsCredentialOwner(fn (): ?string => $this->vault->get($field->key, null, true));

        return is_string($value) && trim($value) !== '';
    }

    private function credentialOwnerId(): ?int
    {
        $userId = Auth::id();

        return is_int($userId) ? $userId : null;
    }

    private function runAsCredentialOwner(callable $callback): mixed
    {
        return $this->vault->runAsOwner($this->credentialOwnerId(), $callback, true);
    }

    private function backedEnumValue(mixed $value): ?string
    {
        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
