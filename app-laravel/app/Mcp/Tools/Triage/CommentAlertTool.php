<?php

namespace App\Mcp\Tools\Triage;

use App\Mcp\Tools\AppSecTool;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Triage\CommentManager;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;

#[Name('comment-alert')]
#[Title('Comment on an alert')]
#[Description('Adds a comment to one alert, authored by the acting user. Requires alerts.edit.')]
final class CommentAlertTool extends AppSecTool
{
    public function __construct(private readonly CommentManager $comments) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'alert_id' => $schema->integer()->min(1)->required()->description('The alert id (from list-alerts).'),
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
            'alert_id' => ['required', 'integer', 'exists:security_events,id'],
            'body' => ['required', 'string'],
        ], ['alert_id.exists' => 'No alert with id :input.']);

        $comment = $this->comments->add(
            SecurityEvent::query()->findOrFail((int) $validated['alert_id']),
            $actor,
            $validated['body'],
        );

        return Response::structured(['alert_id' => (int) $validated['alert_id'], 'comment_id' => $comment->id]);
    }
}
