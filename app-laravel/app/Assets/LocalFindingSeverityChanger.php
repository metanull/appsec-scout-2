<?php

namespace App\Assets;

use App\Audit\Recorder;
use App\Models\Enums\EventSeverity;
use App\Models\LocalFinding;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LocalFindingSeverityChanger
{
    public function __construct(
        private readonly LocalFindingCommentManager $commentManager,
        private readonly Recorder $recorder,
    ) {}

    public function change(LocalFinding $finding, User $author, EventSeverity $newSeverity, string $comment): LocalFinding
    {
        $comment = $this->normalizeComment($comment);

        DB::transaction(function () use ($author, $comment, $finding, $newSeverity): void {
            $this->commentManager->add($finding, $author, sprintf('[Severity change: %s] %s', $newSeverity->value, $comment));

            $previousSeverity = $finding->getAttribute('overridden_severity');

            $finding->forceFill([
                'overridden_severity' => $newSeverity,
                'updated_at' => now(),
            ])->save();

            $this->recorder->recordSeverityChange(LocalFinding::class, (string) $finding->id, [
                'previous_overridden_severity' => $previousSeverity instanceof EventSeverity ? $previousSeverity->value : $previousSeverity,
                'reported_severity' => $finding->severity,
                'new_overridden_severity' => $newSeverity->value,
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
