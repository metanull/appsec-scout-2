<?php

use App\Credentials\Vault;
use App\Mcp\Servers\AppSecScoutServer;
use App\Mcp\Support\AzDoReposAccess;
use App\Mcp\Tools\Code\SearchCodeTool;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\Triage\CodesearchClient;
use App\Triage\CodesearchClientFactory;
use Database\Seeders\RolePermissionSeeder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    $this->searches = [];
    $this->factoryCalls = [];
    $searches = &$this->searches;
    $calls = &$this->factoryCalls;

    app()->instance(CodesearchClientFactory::class, new class($searches, $calls) extends CodesearchClientFactory
    {
        /**
         * @param  list<array<string, mixed>>  $searches
         * @param  list<array{organization: string, pat: string}>  $calls
         */
        public function __construct(private array &$searches, private array &$calls) {}

        public function make(string $organization, string $pat): CodesearchClient
        {
            $this->calls[] = ['organization' => $organization, 'pat' => $pat];
            $history = [];
            $stack = HandlerStack::create(new MockHandler([new Response(200, [], json_encode([
                'count' => 3,
                'results' => [
                    ['project' => ['name' => 'Payments'], 'repository' => ['name' => 'payments-api'], 'path' => '/src/Crypto.cs', 'hits' => [['charOffset' => 1], ['charOffset' => 9]]],
                    ['project' => ['name' => 'Payments'], 'repository' => ['name' => 'payments-web'], 'path' => '/app.js', 'hits' => [['charOffset' => 4]]],
                    ['project' => ['name' => 'Other'], 'repository' => ['name' => 'tools'], 'path' => '/x.py', 'hits' => []],
                ],
            ], JSON_THROW_ON_ERROR))]));
            $stack->push(Middleware::history($history));
            $searches = &$this->searches;
            $stack->push(function (callable $handler) use (&$searches): callable {
                return function ($request, array $options) use ($handler, &$searches) {
                    $searches[] = json_decode((string) $request->getBody(), true, 512, JSON_THROW_ON_ERROR);

                    return $handler($request, $options);
                };
            });

            return new CodesearchClient($organization, $pat, new Client(['handler' => $stack]));
        }
    });

    $this->reader = mcpUser('Reader');
    app(Vault::class)->set('azdo-repos.pat', $this->reader->id, 'user-pat');
    app(Vault::class)->set('azdo-repos.organization', $this->reader->id, 'contoso');
    app(Vault::class)->set('azdo-repos.pat', null, 'system-pat');
    app(Vault::class)->set('azdo-repos.organization', null, 'contoso');
});

it('searches with the acting user\'s own credential and links results to known containers', function () {
    $project = SoftwareSystem::factory()->create(['source_id' => 'azdo', 'name' => 'Payments']);
    $repo = SecurityContainer::factory()->forSystem($project)->create(['name' => 'payments-api']);

    AppSecScoutServer::actingAs($this->reader)->tool(SearchCodeTool::class, ['query' => 'Rfc2898DeriveBytes', 'max_results' => 2])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('total', 3)
            ->where('results.0', ['project' => 'Payments', 'repository' => 'payments-api', 'path' => '/src/Crypto.cs', 'matches' => 2, 'container_id' => $repo->id])
            ->where('results.1.container_id', null)
            ->has('results', 2));

    expect($this->factoryCalls)->toBe([['organization' => 'contoso', 'pat' => 'user-pat']])
        ->and($this->searches[0]['searchText'])->toBe('Rfc2898DeriveBytes')
        ->and($this->searches[0])->not->toHaveKey('filters');
});

it('limits the search to a container\'s repository in its source project, or to a system\'s project', function () {
    $source = SoftwareSystem::factory()->create(['source_id' => 'azdo', 'name' => 'Payments']);
    $container = SecurityContainer::factory()->relinked($source, SoftwareSystem::factory()->create(['name' => 'Billing']))->create(['name' => 'payments-api']);

    AppSecScoutServer::actingAs($this->reader)->tool(SearchCodeTool::class, ['query' => 'secret', 'container_id' => $container->id])->assertOk();
    AppSecScoutServer::actingAs($this->reader)->tool(SearchCodeTool::class, ['query' => 'secret', 'system_id' => $source->id])->assertOk();

    expect($this->searches[0]['filters'])->toBe(['Project' => ['Payments'], 'Repository' => ['payments-api']])
        ->and($this->searches[1]['filters'])->toBe(['Project' => ['Payments']]);
});

it('refuses systems and containers outside Azure DevOps, and both scopes at once', function () {
    $asoc = SoftwareSystem::factory()->create(['source_id' => 'asoc', 'name' => 'Portal']);
    $container = SecurityContainer::factory()->forSystem($asoc)->create();

    AppSecScoutServer::actingAs($this->reader)->tool(SearchCodeTool::class, ['query' => 'secret', 'system_id' => $asoc->id])
        ->assertHasErrors(["Software system #{$asoc->id} (Portal) is not an Azure DevOps project: code access covers Azure DevOps repositories only."]);
    AppSecScoutServer::actingAs($this->reader)->tool(SearchCodeTool::class, ['query' => 'secret', 'container_id' => $container->id])
        ->assertHasErrors(["Security container #{$container->id} ({$container->name}) is not an Azure DevOps repository: code access covers Azure DevOps repositories only."]);
    AppSecScoutServer::actingAs($this->reader)->tool(SearchCodeTool::class, ['query' => 'secret', 'system_id' => $asoc->id, 'container_id' => $container->id])
        ->assertHasErrors(['Provide container_id or system_id, not both.']);

    expect($this->searches)->toBe([]);
});

it('refuses a user without a personal credential even though a system credential exists', function () {
    AppSecScoutServer::actingAs(mcpUser('Reader'))->tool(SearchCodeTool::class, ['query' => 'secret'])
        ->assertHasErrors([AzDoReposAccess::MISSING_CREDENTIAL]);

    expect($this->factoryCalls)->toBe([]);
});

it('requires alerts.view', function () {
    AppSecScoutServer::actingAs(mcpUser(null))->tool(SearchCodeTool::class, ['query' => 'secret'])
        ->assertHasErrors(['Permission denied: this tool requires the alerts.view permission.']);
});
