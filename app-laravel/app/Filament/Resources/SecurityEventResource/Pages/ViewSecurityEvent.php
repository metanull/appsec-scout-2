<?php

namespace App\Filament\Resources\SecurityEventResource\Pages;

use App\Filament\Resources\SecurityEventResource;
use App\Models\Enums\EventType;
use App\Models\EventComment;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Sources\Dto\EventDto;
use App\Sources\Registry;
use App\Triage\CommentManager;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class ViewSecurityEvent extends ViewRecord
{
    protected static string $resource = SecurityEventResource::class;

    protected string $view = 'filament.resources.security-event-resource.pages.view-security-event';

    public string $newCommentBody = '';

    public ?int $editingCommentId = null;

    public string $editingCommentBody = '';

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            Action::make('reloadFromSource')
                ->label('Reload from source')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (): bool => Gate::allows('sources.push-state'))
                ->action(fn () => $this->reloadFromSource()),
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
        $record = $this->eventRecord();
        $source = app(Registry::class)->find($record->source_id);

        if ($source === null) {
            Notification::make()->title('Source is not enabled')->danger()->send();

            return;
        }

        $dto = $source->fetchRawEvent($record);
        $this->applyEventDto($record, $dto);
        $this->refreshFormData([]);

        Notification::make()->title('Alert refreshed from source')->success()->send();
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
        return EventType::from((string) $record->type);
    }

    private function commentById(int $commentId): ?EventComment
    {
        return $this->eventRecord()->comments()->with('author')->find($commentId);
    }
}
