<?php

namespace App\Mcp\Tools\Findings;

use App\Filament\Resources\SecurityEventResource\Support\SecurityEventTableQuery;
use App\Mcp\Support\FindingPresenter;
use App\Mcp\Support\Format;
use App\Mcp\Support\Pagination;
use App\Mcp\Support\Scope;
use App\Mcp\Support\Search;
use App\Mcp\Tools\AppSecTool;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Sources\Contracts\Source;
use App\Sources\Registry as SourceRegistry;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-alerts')]
#[Title('List alerts')]
#[Description('Lists alerts from the sources (AzDO Advanced Security, AppScan on Cloud, Detectify), most severe first, then most recently seen. Scope with exactly the asset, system or container you are triaging. Omitting state lists open alerts only; pass an empty array for every state. pending_state / pending_severity show triage changes not yet pushed back to the source. Use get-alert for the full detail. Requires alerts.view.')]
#[IsReadOnly]
final class ListAlertsTool extends AppSecTool
{
    public function __construct(private readonly SourceRegistry $sources) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'state' => $schema->array()->items($schema->string()->enum(Format::enumValues(EventState::class)))
                ->description('Alert states to include. Omitted: ["open"]. Empty array: every state.'),
            'severity' => $schema->array()->items($schema->string()->enum(Format::enumValues(EventSeverity::class)))
                ->description('Severities to include (all when omitted).'),
            'type' => $schema->array()->items($schema->string()->enum(Format::enumValues(EventType::class)))
                ->description('Alert types to include (all when omitted).'),
            'source_id' => $schema->array()->items($schema->string()->enum($this->sourceIds()))
                ->description('Sources to include (all when omitted).'),
            'has_work_item' => $schema->boolean()->description('true: only alerts linked to a tracker work item; false: only unlinked ones.'),
            'search' => Search::schema($schema, 'title, description, rule id and metadata'),
            ...Scope::schema($schema),
            ...Pagination::schema($schema),
        ];
    }

    protected function requiredPermissions(): array
    {
        return ['alerts.view'];
    }

    protected function run(Request $request, User $actor): ResponseFactory
    {
        $validated = $request->validate([
            'state' => ['sometimes', 'array'],
            'state.*' => [Rule::enum(EventState::class)],
            'severity' => ['nullable', 'array'],
            'severity.*' => [Rule::enum(EventSeverity::class)],
            'type' => ['nullable', 'array'],
            'type.*' => [Rule::enum(EventType::class)],
            'source_id' => ['nullable', 'array'],
            'source_id.*' => [Rule::in($this->sourceIds())],
            'has_work_item' => ['nullable', 'boolean'],
            'search' => Search::rules(),
        ]);

        $scope = Scope::fromRequest($request);
        $query = SecurityEvent::query()
            ->with(['softwareSystem:id,name', 'container:id,name'])
            ->withCount('workItemLinks');

        SecurityEventTableQuery::applyStates($query, $validated['state'] ?? [EventState::Open->value]);
        SecurityEventTableQuery::applySeverities($query, $validated['severity'] ?? []);
        SecurityEventTableQuery::applyTypes($query, $validated['type'] ?? []);
        SecurityEventTableQuery::applySources($query, $validated['source_id'] ?? []);
        SecurityEventTableQuery::applyAssetScopes($query, $scope->assetIds());
        SecurityEventTableQuery::applySystemScopes($query, $scope->systemIds());
        SecurityEventTableQuery::applyContainerScopes($query, $scope->containerIds());
        SecurityEventTableQuery::applyHasWorkItem($query, isset($validated['has_work_item']) ? (bool) $validated['has_work_item'] : null);
        SecurityEventTableQuery::applySearch($query, $validated['search'] ?? null);

        $query->bySeverityPriority()->orderByDesc('last_seen_at')->orderByDesc('id');

        return Pagination::paginate($query, $request, fn (SecurityEvent $event): array => self::row($event));
    }

    /** @return array<string, mixed> */
    public static function row(SecurityEvent $event): array
    {
        return [
            'id' => $event->id,
            'title' => $event->title,
            'severity' => Format::enum($event->getAttribute('severity')),
            'state' => Format::enum($event->getAttribute('state')),
            'pending_state' => Format::enum($event->getAttribute('pending_state')),
            'pending_severity' => Format::enum($event->getAttribute('pending_severity')),
            'type' => Format::enum($event->getAttribute('type')),
            'source_id' => $event->source_id,
            'system' => FindingPresenter::named($event->softwareSystem),
            'container' => FindingPresenter::named($event->container),
            'rule_id' => $event->rule_id,
            'file_path' => $event->file_path,
            'start_line' => $event->start_line,
            'last_seen_at' => Format::date($event->getAttribute('last_seen_at')),
            'work_items_count' => (int) $event->getAttribute('work_item_links_count'),
        ];
    }

    /** @return list<string> */
    private function sourceIds(): array
    {
        return array_map(fn (Source $source): string => $source->id(), $this->sources->all());
    }
}
