<?php

namespace App\Mcp\Tools\Triage;

use App\Assets\LocalFindingCommentManager;
use App\Mcp\Tools\AppSecTool;
use App\Models\LocalFinding;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('comment-local-finding')]
#[Title('Comment on a local finding')]
#[Description('Adds a comment to one local finding, authored by the acting user. Requires alerts.edit.')]
final class CommentLocalFindingTool extends AppSecTool
{
    public function __construct(private readonly LocalFindingCommentManager $comments) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'finding_id' => $schema->integer()->min(1)->required()->description('The local finding id (from list-local-findings).'),
            'body' => $schema->string()->min(1)->required()->description('The comment text.'),
        ];
    }

    protected function requiredPermissions(): array
    {
        return ['alerts.edit'];
    }

    protected function run(Request $request, User $actor): ResponseFactory
    {
        $validated = $request->validate([
            'finding_id' => ['required', 'integer', 'exists:local_findings,id'],
            'body' => ['required', 'string'],
        ], ['finding_id.exists' => 'No local finding with id :input.']);

        $comment = $this->comments->add(
            LocalFinding::query()->findOrFail((int) $validated['finding_id']),
            $actor,
            $validated['body'],
        );

        return Response::structured(['finding_id' => (int) $validated['finding_id'], 'comment_id' => $comment->id]);
    }
}
