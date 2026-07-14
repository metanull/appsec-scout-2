<?php

namespace App\Assets;

use App\Audit\Recorder;
use App\Models\Enums\EventState;
use App\Models\LocalFinding;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LocalFindingStatusChanger
{
    public function __construct(
        private readonly LocalFindingCommentManager $commentManager,
        private readonly Recorder $recorder,
    ) {}

    public function change(LocalFinding $finding, User $author, EventState $newStatus, string $comment): LocalFinding
    {
        $comment = $this->normalizeComment($comment);

        DB::transaction(function () use ($author, $comment, $finding, $newStatus): void {
            $this->commentManager->add($finding, $author, sprintf('[Status change: %s] %s', $newStatus->value, $comment));

            $previousStatus = $finding->status;

            $finding->forceFill([
                'status' => $newStatus,
                'updated_at' => now(),
            ])->save();

            $this->recorder->recordStateChange(LocalFinding::class, (string) $finding->id, [
                'previous_status' => $previousStatus instanceof EventState ? $previousStatus->value : $previousStatus,
                'new_status' => $newStatus->value,
                'comment' => $comment,
            ]);
        });

        /** @var LocalFinding $fresh */
        $fresh = $finding->fresh(['comments']);

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
