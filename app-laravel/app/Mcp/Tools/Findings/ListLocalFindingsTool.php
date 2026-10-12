<?php

namespace App\Mcp\Tools\Findings;

use App\Filament\Resources\LocalFindingResource\Support\LocalFindingTableQuery;
use App\Mcp\Support\FindingPresenter;
use App\Mcp\Support\Format;
use App\Mcp\Support\Pagination;
use App\Mcp\Support\Scope;
use App\Mcp\Support\Search;
use App\Mcp\Tools\AppSecTool;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\LocalFinding;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Validation\Rule;
use Laravel\Mcp\Request;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('list-local-findings')]
#[Title('List local findings')]
#[Description('Lists local findings produced by AppSec Scout\'s own scans of collected repositories: Trivy vulnerabilities (vulnerable packages, i.e. the SBOM\'s vulnerabilities) and secrets, and static-analysis code-quality issues. Most severe first, then most recently seen. Severity is the effective one: an analyst override, else the scanner\'s; "unknown" matches scanner severities outside the five levels. Omitting status lists open findings only; pass an empty array for every status. Use get-local-finding for the full detail. Requires alerts.view.')]
#[IsReadOnly]
final class ListLocalFindingsTool extends AppSecTool
{
    private const KINDS = [LocalFinding::KIND_VULNERABILITY, LocalFinding::KIND_SECRET, LocalFinding::KIND_CODE_QUALITY];

    public function schema(JsonSchema $schema): array
    {
        return [
            'kind' => $schema->array()->items($schema->string()->enum(self::KINDS))
                ->description('Finding kinds to include (all when omitted).'),
            'status' => $schema->array()->items($schema->string()->enum(Format::enumValues(EventState::class)))
                ->description('Statuses to include. Omitted: ["open"]. Empty array: every status.'),
            'severity' => $schema->array()->items($schema->string()->enum(self::severities()))
                ->description('Effective severities to include (all when omitted).'),
            'is_correlated' => $schema->boolean()->description('true: only findings correlated to a source alert; false: only uncorrelated ones.'),
            'has_work_item' => $schema->boolean()->description('true: only findings linked to a tracker work item; false: only unlinked ones.'),
            'search' => Search::schema($schema, 'title, description, rule id, file path, package and metadata'),
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
            'kind' => ['nullable', 'array'],
            'kind.*' => [Rule::in(self::KINDS)],
            'status' => ['sometimes', 'array'],
            'status.*' => [Rule::enum(EventState::class)],
            'severity' => ['nullable', 'array'],
            'severity.*' => [Rule::in(self::severities())],
            'is_correlated' => ['nullable', 'boolean'],
            'has_work_item' => ['nullable', 'boolean'],
            'search' => Search::rules(),
        ]);

        $scope = Scope::fromRequest($request);
        $query = LocalFinding::query()
            ->with(['owner', 'softwareSystem:id,name'])
            ->withCount('workItemLinks');

        LocalFindingTableQuery::applyKinds($query, $validated['kind'] ?? []);
        LocalFindingTableQuery::applyStatuses($query, $validated['status'] ?? [EventState::Open->value]);
        LocalFindingTableQuery::applyEffectiveSeverities($query, $validated['severity'] ?? []);
        LocalFindingTableQuery::applyAssetScopes($query, $scope->assetIds());
        LocalFindingTableQuery::applySystemScopes($query, $scope->systemIds());
        LocalFindingTableQuery::applyContainerScopes($query, $scope->containerIds());
        LocalFindingTableQuery::applyIsCorrelated($query, isset($validated['is_correlated']) ? (bool) $validated['is_correlated'] : null);
        LocalFindingTableQuery::applyHasWorkItem($query, isset($validated['has_work_item']) ? (bool) $validated['has_work_item'] : null);
        LocalFindingTableQuery::applySearch($query, $validated['search'] ?? null);

        $query->orderByRaw(LocalFindingTableQuery::effectiveSeverityRankSql() . ' DESC')
            ->orderByDesc('last_seen_at')
            ->orderByDesc('id');

        return Pagination::paginate($query, $request, fn (LocalFinding $finding): array => self::row($finding));
    }

    /** @return array<string, mixed> */
    public static function row(LocalFinding $finding): array
    {
        return [
            'id' => $finding->id,
            'kind' => $finding->kind,
            'title' => $finding->title,
            'severity' => $finding->effectiveSeverityLabel(),
            'status' => Format::enum($finding->getAttribute('status')),
            'rule_id' => $finding->rule_id,
            'file_path' => $finding->file_path,
            'start_line' => $finding->start_line,
            'package_name' => $finding->package_name,
            'package_version' => $finding->package_version,
            'owner' => FindingPresenter::owner($finding->owner),
            'system' => FindingPresenter::named($finding->softwareSystem),
            'last_seen_at' => Format::date($finding->getAttribute('last_seen_at')),
            'work_items_count' => (int) $finding->getAttribute('work_item_links_count'),
        ];
    }

    /** @return list<string> the five severity levels plus "unknown" */
    private static function severities(): array
    {
        return [...Format::enumValues(EventSeverity::class), 'unknown'];
    }
}
