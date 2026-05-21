<?php

namespace App\Triage;

use App\Audit\Recorder;
use App\Models\EventComment;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CommentManager
{
    public function __construct(private readonly Recorder $recorder) {}

    public function add(SecurityEvent $event, User $author, string $body): EventComment
    {
        $body = $this->normalizeBody($body);

        return DB::transaction(function () use ($author, $body, $event): EventComment {
            $comment = EventComment::query()->create([
                'event_id' => $event->id,
                'body' => $body,
                'author_user_id' => $author->id,
                'created_at' => now(),
            ]);

            $event->forceFill([
                'is_dirty' => true,
                'updated_at' => now(),
            ])->save();

            $this->recorder->recordCommentAdded(SecurityEvent::class, (string) $event->id, [
                'comment_id' => $comment->id,
            ]);

            return $comment;
        });
    }

    public function update(EventComment $comment, User $author, string $body): EventComment
    {
        if (! $this->canEdit($comment, $author, now())) {
            throw ValidationException::withMessages([
                'comment' => 'This comment can no longer be edited.',
            ]);
        }

        $body = $this->normalizeBody($body);

        return DB::transaction(function () use ($author, $body, $comment): EventComment {
            $comment->forceFill([
                'body' => $body,
            ])->save();

            $comment->event()->update([
                'is_dirty' => true,
                'updated_at' => now(),
            ]);

            $this->recorder->recordCommentEdited(SecurityEvent::class, (string) $comment->event_id, [
                'comment_id' => $comment->id,
                'author_user_id' => $author->id,
            ]);

            return $comment->refresh();
        });
    }

    public function canEdit(EventComment $comment, User $author, Carbon $now): bool
    {
        if ($comment->upstream_comment_id !== null) {
            return false;
        }

        if ($comment->author_user_id !== $author->id) {
            return false;
        }

        $createdAt = $comment->created_at;

        if (is_string($createdAt)) {
            $createdAt = Carbon::parse($createdAt);
        }

        return $createdAt !== null && $createdAt->greaterThanOrEqualTo($now->copy()->subMinutes(5));
    }

    private function normalizeBody(string $body): string
    {
        $body = trim($body);

        if ($body === '') {
            throw ValidationException::withMessages([
                'comment' => 'A comment is required.',
            ]);
        }

        return $body;
    }
}
