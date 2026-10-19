<?php

namespace App\Triage;

use App\Audit\Recorder;
use App\Models\Enums\AzDoDismissalReason;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
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

    /**
     * A dismissal of an AzDO alert must say why (`$dismissalReason`), since AzDO records the
     * reason with the dismissal; other sources and other states take none.
     */
    public function change(SecurityEvent $event, User $author, EventState $newState, string $comment, ?AzDoDismissalReason $dismissalReason = null): SecurityEvent
    {
        $comment = $this->normalizeComment($comment);
        $this->validateDismissalReason([$event], $newState, $dismissalReason);

        DB::transaction(function () use ($author, $comment, $dismissalReason, $event, $newState): void {
            $this->applyChange($event, $author, $newState, $comment, $dismissalReason);
        });

        /** @var SecurityEvent $fresh */
        $fresh = $event->fresh(['comments']);

        return $fresh;
    }

    /**
     * @param  iterable<SecurityEvent>  $events
     * @return Collection<int, SecurityEvent>
     */
    public function changeMany(iterable $events, User $author, EventState $newState, string $comment, ?AzDoDismissalReason $dismissalReason = null): Collection
    {
        $comment = $this->normalizeComment($comment);
        $events = is_array($events) ? $events : iterator_to_array($events, false);
        $this->validateDismissalReason($events, $newState, $dismissalReason);
        $eventIds = [];

        DB::transaction(function () use ($author, $comment, $dismissalReason, &$eventIds, $events, $newState): void {
            foreach ($events as $event) {
                $this->applyChange($event, $author, $newState, $comment, $dismissalReason);
                $eventIds[] = $event->id;
            }

            $this->recorder->recordBulkStateChange(SecurityEvent::class, 'bulk', array_filter([
                'event_ids' => $eventIds,
                'new_state' => $newState->value,
                'dismissal_reason' => $dismissalReason?->value,
                'count' => count($eventIds),
            ], fn (mixed $value): bool => $value !== null));
        });

        return SecurityEvent::query()->whereKey($eventIds)->get();
    }

    private function applyChange(SecurityEvent $event, User $author, EventState $newState, string $comment, ?AzDoDismissalReason $dismissalReason): void
    {
        $reason = self::storedReason($event, $newState, $dismissalReason);
        $label = $reason !== null ? sprintf('%s (%s)', $newState->value, $reason->label()) : $newState->value;

        $this->commentManager->add($event, $author, sprintf('[State change: %s] %s', $label, $comment));

        $previousState = $event->getAttribute('state');

        if (! $previousState instanceof EventState) {
            $previousState = EventState::from((string) $previousState);
        }

        $event->forceFill([
            'pending_state' => $newState,
            'pending_dismissal_reason' => $reason,
            'pending_comment' => $comment,
            'is_dirty' => true,
            'updated_at' => now(),
        ])->save();

        $this->recorder->recordStateChange(SecurityEvent::class, (string) $event->id, array_filter([
            'previous_state' => $previousState->value,
            'pending_state' => $newState->value,
            'dismissal_reason' => $reason?->value,
            'comment' => $comment,
        ], fn (mixed $value): bool => $value !== null));
    }

    /** The reason kept with the pending change: only an AzDO dismissal carries one. */
    private static function storedReason(SecurityEvent $event, EventState $newState, ?AzDoDismissalReason $dismissalReason): ?AzDoDismissalReason
    {
        return $newState === EventState::Dismissed && AzDoDismissalReason::isRequiredFor($event) ? $dismissalReason : null;
    }

    /**
     * Rejects a reason on anything but a dismissal, and an AzDO dismissal without a reason or with
     * one that does not apply to the alert's type. Public so a dry run can check it up front.
     *
     * @param  iterable<SecurityEvent>  $events
     */
    public function validateDismissalReason(iterable $events, EventState $newState, ?AzDoDismissalReason $dismissalReason): void
    {
        if ($newState !== EventState::Dismissed) {
            if ($dismissalReason !== null) {
                throw ValidationException::withMessages([
                    'dismissal_reason' => 'A dismissal reason only applies when the new state is dismissed.',
                ]);
            }

            return;
        }

        foreach ($events as $event) {
            if (! AzDoDismissalReason::isRequiredFor($event)) {
                continue;
            }

            if ($dismissalReason === null) {
                throw ValidationException::withMessages([
                    'dismissal_reason' => 'Dismissing an Azure DevOps alert requires a dismissal reason.',
                ]);
            }

            $type = $event->getAttribute('type');
            $type = $type instanceof EventType ? $type : EventType::from((string) $type);

            if (! $dismissalReason->appliesTo($type)) {
                throw ValidationException::withMessages([
                    'dismissal_reason' => sprintf('The dismissal reason "%s" does not apply to %s alerts (alert %d).', $dismissalReason->value, $type->value, $event->id),
                ]);
            }
        }
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
