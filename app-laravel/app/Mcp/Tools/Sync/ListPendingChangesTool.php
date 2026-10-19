<?php

namespace App\Mcp\Tools\Sync;

use App\Filament\Resources\SecurityEventResource\Support\SecurityEventTableQuery;
use App\Mcp\Support\FindingPresenter;
use App\Mcp\Support\Format;
use App\Mcp\Support\Pagination;
use App\Mcp\Support\Scope;
use App\Mcp\Tools\AppSecTool;
use App\Models\SecurityEvent;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-pending-changes')]
#[Title('List pending alert changes')]
#[Description('Lists alerts with state, severity or comment changes not yet pushed to their source (the Pending Sync page), newest change first, with the retry count and last error of earlier failed pushes. Filter by asset, system, container or source. Push them with push-pending-changes. Requires work-items.sync and sources.push-state.')]
#[IsReadOnly]
final class ListPendingChangesTool extends AppSecTool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            ...Scope::schema($schema),
            'source_id' => $schema->string()->description('Only alerts of this source (e.g. azdo, asoc, detectify).'),
            ...Pagination::schema($schema),
        ];
    }

    protected function requiredPermissions(): array
    {
        return ['work-items.sync', 'sources.push-state'];
    }

    protected function run(Request $request, User $actor): ResponseFactory
    {
        $validated = $request->validate(['source_id' => ['nullable', 'string', 'max:64']]);
        $scope = Scope::fromRequest($request);

        $query = SecurityEvent::query()
            ->with(['softwareSystem:id,name', 'container:id,name'])
            ->where('is_dirty', true)
            ->when(isset($validated['source_id']), fn ($query) => $query->where('source_id', $validated['source_id']));

        SecurityEventTableQuery::applyAssetScopes($query, $scope->assetIds());
        SecurityEventTableQuery::applySystemScopes($query, $scope->systemIds());
        SecurityEventTableQuery::applyContainerScopes($query, $scope->containerIds());

        $query->orderByDesc('updated_at')->orderByDesc('id');

        return Pagination::paginate($query, $request, fn (SecurityEvent $event): array => self::row($event));
    }

    /** @return array<string, mixed> */
    public static function row(SecurityEvent $event): array
    {
        $metadata = Format::arrayAttribute($event->getAttribute('metadata'));

        return [
            'id' => $event->id,
            'title' => $event->title,
            'source_id' => $event->source_id,
            'system' => FindingPresenter::named($event->softwareSystem),
            'container' => FindingPresenter::named($event->container),
            'state' => Format::enum($event->getAttribute('state')),
            'pending_state' => Format::enum($event->getAttribute('pending_state')),
            'pending_dismissal_reason' => Format::enum($event->getAttribute('pending_dismissal_reason')),
            'severity' => Format::enum($event->getAttribute('severity')),
            'pending_severity' => Format::enum($event->getAttribute('pending_severity')),
            'pending_comment' => $event->pending_comment,
            'push_retry_count' => (int) ($metadata['pushRetryCount'] ?? 0),
            'last_push_error' => is_string($metadata['lastPushError'] ?? null) ? $metadata['lastPushError'] : null,
            'updated_at' => Format::date($event->getAttribute('updated_at')),
        ];
    }
}
