<?php

namespace App\Triage;

use App\Audit\Recorder;
use App\Models\Enums\EventState;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class StateChanger
{
    public function __construct(
        private readonly CommentManager $commentManager,
        private readonly Recorder $recorder,
    ) {}

    public function change(SecurityEvent $event, User $author, EventState $newState, string $comment): SecurityEvent
    {
        $comment = $this->normalizeComment($comment);

        DB::transaction(function () use ($author, $comment, $event, $newState): void {
            $this->applyChange($event, $author, $newState, $comment);
        });

        /** @var SecurityEvent $fresh */
        $fresh = $event->fresh(['comments']);

        return $fresh;
    }

    /**
     * @param  iterable<SecurityEvent>  $events
     * @return Collection<int, SecurityEvent>
     */
    public function changeMany(iterable $events, User $author, EventState $newState, string $comment): Collection
    {
        $comment = $this->normalizeComment($comment);
        $eventIds = [];

        DB::transaction(function () use ($author, $comment, &$eventIds, $events, $newState): void {
            foreach ($events as $event) {
                $this->applyChange($event, $author, $newState, $comment);
                $eventIds[] = $event->id;
            }

            $this->recorder->recordBulkStateChange(SecurityEvent::class, 'bulk', [
                'event_ids' => $eventIds,
                'new_state' => $newState->value,
                'count' => count($eventIds),
            ]);
        });

        return SecurityEvent::query()->whereKey($eventIds)->get();
    }

    private function applyChange(SecurityEvent $event, User $author, EventState $newState, string $comment): void
    {
        $this->commentManager->add($event, $author, sprintf('[State change: %s] %s', $newState->value, $comment));

        $previousState = $event->getAttribute('state');

        if (! $previousState instanceof EventState) {
            $previousState = EventState::from((string) $previousState);
        }

        $event->forceFill([
            'pending_state' => $newState,
            'pending_comment' => $comment,
            'is_dirty' => true,
            'updated_at' => now(),
        ])->save();

        $this->recorder->recordStateChange(SecurityEvent::class, (string) $event->id, [
            'previous_state' => $previousState->value,
            'pending_state' => $newState->value,
            'comment' => $comment,
        ]);
    }

    private function normalizeComment(string $comment): string
    {
        $comment = trim($comment);

        if ($comment === '') {
            throw ValidationException::withMessages([
                'comment' => 'A justification comment is required.',
            ]);
        }

        if (mb_strlen($comment) < 10) {
            throw ValidationException::withMessages([
                'comment' => 'The justification comment must be at least 10 characters.',
            ]);
        }

        return $comment;
    }
}
