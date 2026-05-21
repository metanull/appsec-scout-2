<?php

namespace App\Filament\Resources\SecurityEventResource\Pages;

use App\Audit\AuditLog;
use App\Filament\Resources\SecurityEventResource;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\EventAttachment;
use App\Models\EventComment;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Models\WorkItemLink;
use App\Sources\Dto\EventDto;
use App\Sources\Registry;
use App\Sync\RefetchEventJob;
use App\Trackers\CreateWorkItemJob;
use App\Trackers\WorkItemFormOptions;
use App\Trackers\WorkItemService;
use App\Triage\AttachmentService;
use App\Triage\CommentManager;
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
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\WithFileUploads;
use Tempest\Highlight\Highlighter;
use Tempest\Highlight\Themes\InlineTheme;

class ViewSecurityEvent extends ViewRecord
{
    use WithFileUploads;

    protected static string $resource = SecurityEventResource::class;

    protected string $view = 'filament.resources.security-event-resource.pages.view-security-event';

    public string $newCommentBody = '';

    public ?int $editingCommentId = null;

    public string $editingCommentBody = '';

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
                            ->helperText('Optional. Use project:<name> or repo:<name>.')
                            ->nullable(),
                    ])
                    ->action(fn (array $data): bool => $this->dispatchCodesearch($data)),
            ])
                ->label('Run triage')
                ->icon('heroicon-o-play'),
        ];
    }

    public function loadSecretOccurrences(): void
    {
        $record = $this->eventRecord();
        $eventType = $this->eventType($record);

        if ($eventType !== EventType::Secret) {
            return;
        }

        $source = app(Registry::class)->find($record->source_id);

        if ($source === null) {
            Notification::make()->title('Source is not enabled')->danger()->send();

            return;
        }

        $dto = $source->enrichEvent($record);

        if ($dto === null) {
            Notification::make()->title('No enrichment available for this alert')->warning()->send();

            return;
        }

        $this->applyEventDto($record, $dto);
        $this->refreshFormData([]);

        Notification::make()->title('Secret occurrences loaded')->success()->send();
    }

    /**
     * @return Collection<int, EventComment>
     */
    public function comments(): Collection
    {
        $comments = $this->eventRecord()
            ->comments()
            ->with('author')
            ->get();

        return $comments;
    }

    /** @return Collection<int, EventAttachment> */
    public function attachments(): Collection
    {
        return $this->eventRecord()
            ->attachments()
            ->with('createdBy')
            ->get();
    }

    /** @return Collection<int, WorkItemLink> */
    public function workItemLinks(): Collection
    {
        return $this->eventRecord()
            ->workItemLinks()
            ->with('createdBy')
            ->get();
    }

    /** @return Collection<int, AuditLog> */
    public function auditRows(): Collection
    {
        return AuditLog::query()
            ->where('subject_type', SecurityEvent::class)
            ->where('subject_id', (string) $this->eventRecord()->id)
            ->latest('created_at')
            ->limit(20)
            ->get();
    }

    /** @return list<array{rule_id:string,severity:string,location:string,snippet:string,snippet_html:string}> */
    public function sarifRows(): array
    {
        $attachment = $this->attachments()->firstWhere('kind', 'trivy-sarif');

        if (! $attachment instanceof EventAttachment) {
            return [];
        }

        /** @var mixed $decoded */
        $decoded = json_decode($attachment->payload, true);

        if (! is_array($decoded)) {
            return [];
        }

        $runs = $decoded['runs'] ?? [];

        if (! is_array($runs)) {
            return [];
        }

        $rows = [];

        foreach ($runs as $run) {
            if (! is_array($run)) {
                continue;
            }

            $results = $run['results'] ?? [];

            if (! is_array($results)) {
                continue;
            }

            foreach ($results as $result) {
                if (! is_array($result)) {
                    continue;
                }

                $location = 'n/a';
                $snippet = '';
                $locations = $result['locations'] ?? [];

                if (is_array($locations) && isset($locations[0]) && is_array($locations[0])) {
                    $physicalLocation = $locations[0]['physicalLocation'] ?? [];
                    $artifact = is_array($physicalLocation) ? ($physicalLocation['artifactLocation'] ?? []) : [];
                    $region = is_array($physicalLocation) ? ($physicalLocation['region'] ?? []) : [];
                    $path = is_array($artifact) ? ($artifact['uri'] ?? 'n/a') : 'n/a';
                    $line = is_array($region) ? ($region['startLine'] ?? 'n/a') : 'n/a';
                    $location = sprintf('%s:%s', $path, $line);
                    $snippetValue = is_array($region) ? ($region['snippet']['text'] ?? '') : '';
                    $snippet = is_string($snippetValue) ? $snippetValue : '';
                }

                $rows[] = [
                    'rule_id' => is_string($result['ruleId'] ?? null) ? $result['ruleId'] : 'n/a',
                    'severity' => is_string($result['level'] ?? null) ? $result['level'] : 'n/a',
                    'location' => $location,
                    'snippet' => $snippet,
                    'snippet_html' => $this->highlightSnippet($snippet, $location),
                ];
            }
        }

        return $rows;
    }

    public function downloadAttachmentUrl(EventAttachment $attachment): string
    {
        return route('alerts.attachments.download', [
            'event' => $this->eventRecord()->id,
            'attachment' => $attachment->id,
        ]);
    }

    public function deleteAttachment(int $attachmentId): void
    {
        Gate::authorize('work-items.create');

        $attachment = $this->eventRecord()->attachments()->findOrFail($attachmentId);
        app(AttachmentService::class)->delete($attachment);
        Notification::make()->title('Attachment deleted')->success()->send();
    }

    public function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }

        if ($bytes < 1048576) {
            return number_format($bytes / 1024, 1) . ' KB';
        }

        return number_format($bytes / 1048576, 1) . ' MB';
    }

    public function addComment(): void
    {
        /** @var User|null $user */
        $user = Auth::user();

        if (! $user?->can('alerts.edit')) {
            abort(403);
        }

        try {
            app(CommentManager::class)->add($this->eventRecord(), $user, $this->newCommentBody);
        } catch (ValidationException $exception) {
            $this->addError('newCommentBody', $exception->errors()['comment'][0] ?? 'Unable to add comment.');

            return;
        }

        $this->newCommentBody = '';
        $this->editingCommentId = null;
        $this->refreshFormData([]);

        Notification::make()->title('Comment added')->success()->send();
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

    public function startEditingComment(int $commentId): void
    {
        $comment = $this->commentById($commentId);

        if ($comment === null || ! $this->canEditComment($comment)) {
            abort(403);
        }

        $this->editingCommentId = $comment->id;
        $this->editingCommentBody = $comment->body;
        $this->resetErrorBag('editingCommentBody');
    }

    public function cancelEditingComment(): void
    {
        $this->editingCommentId = null;
        $this->editingCommentBody = '';
        $this->resetErrorBag('editingCommentBody');
    }

    public function saveCommentEdit(): void
    {
        $comment = $this->editingCommentId === null ? null : $this->commentById($this->editingCommentId);

        if ($comment === null || ! $this->canEditComment($comment)) {
            abort(403);
        }

        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            abort(403);
        }

        try {
            app(CommentManager::class)->update($comment, $user, $this->editingCommentBody);
        } catch (ValidationException $exception) {
            $this->addError('editingCommentBody', $exception->errors()['comment'][0] ?? 'Unable to save the comment.');

            return;
        }

        $this->cancelEditingComment();
        $this->refreshFormData([]);

        Notification::make()->title('Comment updated')->success()->send();
    }

    public function unlinkWorkItem(int $linkId): void
    {
        Gate::authorize('work-items.link');

        $link = $this->eventRecord()->workItemLinks()->findOrFail($linkId);

        app(WorkItemService::class)->unlink($link);

        $this->refreshFormData([]);

        Notification::make()->title('Work item unlinked')->success()->send();
    }

    public function canEditComment(EventComment $comment): bool
    {
        /** @var User|null $user */
        $user = Auth::user();

        if ($user === null) {
            return false;
        }

        return app(CommentManager::class)->canEdit($comment, $user, now());
    }

    /**
     * @return list<string>
     */
    public static function sectionsForType(EventType $type): array
    {
        return match ($type) {
            EventType::Secret => ['universal', 'secret', 'remediation', 'comments', 'audit', 'work_items'],
            EventType::Dependency => ['universal', 'dependency', 'remediation', 'comments', 'audit', 'work_items'],
            EventType::Vulnerability, EventType::CodeQuality => ['universal', 'code_location', 'remediation', 'comments', 'audit', 'work_items'],
            EventType::Misconfiguration, EventType::Iac, EventType::Posture => ['universal', 'posture', 'remediation', 'comments', 'audit', 'work_items'],
            default => ['universal', 'remediation', 'comments', 'audit', 'work_items'],
        };
    }

    public function remediationHtml(): string
    {
        $markdown = $this->eventRecord()->remediation;

        if (! is_string($markdown) || trim($markdown) === '') {
            return '<p class="text-sm text-gray-500">No remediation guidance available.</p>';
        }

        return Str::markdown($markdown, [
            'html_input' => 'strip',
            'allow_unsafe_links' => false,
        ]);
    }

    private function reloadFromSource(): void
    {
        RefetchEventJob::dispatch($this->eventRecord()->id);

        Notification::make()->title('Alert refresh queued')->success()->send();
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
        );

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

    private function highlightSnippet(string $snippet, string $location): string
    {
        if (trim($snippet) === '') {
            return '';
        }

        return self::snippetHighlighter()->parse($snippet, $this->snippetLanguage($location));
    }

    private function snippetLanguage(string $location): string
    {
        $path = trim(strtok($location, ':') ?: '');
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return match ($extension) {
            'php' => 'php',
            'js', 'jsx' => 'javascript',
            'ts', 'tsx' => 'typescript',
            'json' => 'json',
            'yml', 'yaml' => 'yaml',
            'sh', 'bash' => 'bash',
            'sql' => 'sql',
            'css' => 'css',
            'html' => 'html',
            'xml' => 'xml',
            default => 'text',
        };
    }

    private static function snippetHighlighter(): Highlighter
    {
        static $highlighter = null;

        if ($highlighter instanceof Highlighter) {
            return $highlighter;
        }

        $highlighter = new Highlighter(
            theme: new InlineTheme(base_path('vendor/tempest/highlight/src/Themes/Css/github-light.css')),
        );

        return $highlighter;
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

    /** @return list<string> */
    public function visibleSections(): array
    {
        return self::sectionsForType($this->eventType($this->eventRecord()));
    }

    private function eventRecord(): SecurityEvent
    {
        /** @var SecurityEvent $record */
        $record = $this->getRecord();

        return $record;
    }

    private function eventType(SecurityEvent $record): EventType
    {
        $type = $record->getAttribute('type');

        return $type instanceof EventType ? $type : EventType::from((string) $type);
    }

    private function commentById(int $commentId): ?EventComment
    {
        return $this->eventRecord()->comments()->with('author')->find($commentId);
    }
}
