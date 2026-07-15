<?php

namespace App\Assets;

use App\Audit\Recorder;
use App\Models\LocalFinding;
use App\Models\LocalFindingComment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class LocalFindingCommentManager
{
    public function __construct(private readonly Recorder $recorder) {}

    public function add(LocalFinding $finding, User $author, string $body): LocalFindingComment
    {
        $body = $this->normalizeBody($body);

        return DB::transaction(function () use ($author, $body, $finding): LocalFindingComment {
            $comment = LocalFindingComment::query()->create([
                'local_finding_id' => $finding->id,
                'body' => $body,
                'author_user_id' => $author->id,
                'created_at' => now(),
            ]);

            $this->recorder->recordCommentAdded(LocalFinding::class, (string) $finding->id, [
                'comment_id' => $comment->id,
            ]);

            return $comment;
        });
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
