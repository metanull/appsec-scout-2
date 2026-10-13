<?php

namespace App\Mcp\Tools\Code;

use App\Mcp\Support\AzDoReposAccess;
use App\Mcp\Tools\AppSecTool;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Models\User;
use App\Sources\AzDo\AzDoNormalizer;
use App\Triage\CodesearchService;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Title;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[Name('search-code')]
#[Title('Search code in Azure DevOps')]
#[Description('Searches code across Azure DevOps repositories with your own Azure DevOps Repos credential (Profile → Integrations), e.g. to see where a vulnerable function, package or secret pattern is used. Optionally limited to one container\'s repository or one system\'s project. Returns matching files with their match count and the matching container id when known; read the code with get-code. Supports the Azure DevOps search syntax (e.g. ext:cs, path:, def:). Requires alerts.view.')]
#[IsReadOnly]
final class SearchCodeTool extends AppSecTool
{
    public function __construct(
        private readonly CodesearchService $codesearch,
        private readonly AzDoReposAccess $access,
    ) {}

    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()->min(2)->max(200)->required()->description('The text to search for, in Azure DevOps code search syntax.'),
            'container_id' => $schema->integer()->min(1)->description('Only this container\'s repository (from list-security-containers).'),
            'system_id' => $schema->integer()->min(1)->description('Only this Azure DevOps project (from list-software-systems).'),
            'max_results' => $schema->integer()->min(1)->max(100)->default(25)->description('Maximum files to return.'),
        ];
    }

    protected function requiredPermissions(): array
    {
        return ['alerts.view'];
    }

    protected function run(Request $request, User $actor): ResponseFactory
    {
        $validated = $request->validate([
            'query' => ['required', 'string', 'min:2', 'max:200'],
            'container_id' => ['nullable', 'integer', 'exists:security_containers,id', 'prohibits:system_id'],
            'system_id' => ['nullable', 'integer', 'exists:software_systems,id'],
            'max_results' => ['nullable', 'integer', 'min:1', 'max:100'],
        ], [
            'container_id.exists' => 'No security container with id :input.',
            'system_id.exists' => 'No software system with id :input.',
            'container_id.prohibits' => 'Provide container_id or system_id, not both.',
        ]);

        $filters = $this->filters($validated);
        $credentials = $this->access->credentials($actor);
        $result = $this->codesearch->search($credentials['pat'], $credentials['organization'], $validated['query'], $filters);
        $rows = array_slice($result->tableRows(), 0, (int) ($validated['max_results'] ?? 25));
        $containers = $this->containersByRepository($rows);

        return Response::structured([
            'total' => $result->totalCount(),
            'results' => array_map(fn (array $row): array => [
                ...$row,
                'matches' => (int) $row['matches'],
                'container_id' => $containers[$row['project'] . '/' . $row['repository']] ?? null,
            ], $rows),
        ]);
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, list<string>>
     */
    private function filters(array $validated): array
    {
        if (isset($validated['container_id'])) {
            $container = SecurityContainer::query()->with('sourceSoftwareSystem')->findOrFail((int) $validated['container_id']);

            return ['Project' => [AzDoReposAccess::projectOf($container)->name], 'Repository' => [$container->name]];
        }

        if (isset($validated['system_id'])) {
            return ['Project' => [AzDoReposAccess::project(SoftwareSystem::query()->findOrFail((int) $validated['system_id']))->name]];
        }

        return [];
    }

    /**
     * Container ids of the result repositories, keyed "project/repository".
     *
     * @param  list<array{project: string, repository: string, path: string, matches: string}>  $rows
     * @return array<string, int>
     */
    private function containersByRepository(array $rows): array
    {
        if ($rows === []) {
            return [];
        }

        return SecurityContainer::query()
            ->with('sourceSoftwareSystem:id,name')
            ->whereIn('name', array_unique(array_column($rows, 'repository')))
            ->whereIn('source_software_system_id', SoftwareSystem::query()
                ->select('id')
                ->where('source_id', AzDoNormalizer::SOURCE_ID)
                ->whereIn('name', array_unique(array_column($rows, 'project'))))
            ->get()
            ->mapWithKeys(fn (SecurityContainer $container): array => [$container->sourceSoftwareSystem?->name . '/' . $container->name => $container->id])
            ->all();
    }
}
