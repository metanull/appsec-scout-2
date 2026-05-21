<?php

namespace App\Triage;

use App\Audit\Recorder;
use App\Models\Enums\EventSeverity;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SeverityChanger
{
    public function __construct(
        private readonly CommentManager $commentManager,
        private readonly Recorder $recorder,
    ) {}

    public function change(SecurityEvent $event, User $author, EventSeverity $newSeverity, string $comment): SecurityEvent
    {
        $comment = $this->normalizeComment($comment);

        DB::transaction(function () use ($author, $comment, $event, $newSeverity): void {
            $this->commentManager->add($event, $author, sprintf('[Severity change: %s] %s', $newSeverity->value, $comment));

            $previousSeverity = $event->getAttribute('severity');

            if (! $previousSeverity instanceof EventSeverity) {
                $previousSeverity = EventSeverity::from((string) $previousSeverity);
            }

            $event->forceFill([
                'pending_severity' => $newSeverity,
                'pending_comment' => $comment,
                'is_dirty' => true,
                'updated_at' => now(),
            ])->save();

            $this->recorder->recordSeverityChange(SecurityEvent::class, (string) $event->id, [
                'previous_severity' => $previousSeverity->value,
                'pending_severity' => $newSeverity->value,
                'comment' => $comment,
            ]);
        });

        /** @var SecurityEvent $fresh */
        $fresh = $event->fresh(['comments']);

        return $fresh;
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
