<?php

use App\Credentials\Vault;
use App\Mcp\Servers\AppSecScoutServer;
use App\Mcp\Support\AzDoReposAccess;
use App\Mcp\Tools\Code\GetCodeTool;
use App\Models\LocalFinding;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Sources\AzDo\AzDoClient;
use App\Sources\AzDo\AzDoClientFactory;
use App\Sources\Context\SourceContextFacts;
use Database\Seeders\RolePermissionSeeder;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    $this->requests = [];
    $this->credentials = [];
    $requests = &$this->requests;
    $credentials = &$this->credentials;

    app()->instance(AzDoClientFactory::class, new class($requests, $credentials) extends AzDoClientFactory
    {
        /**
         * @param  list<RequestInterface>  $requests
         * @param  list<array{organization: string, pat: string}>  $credentials
         */
        public function __construct(private array &$requests, private array &$credentials) {}

        public function make(string $organization, string $pat): AzDoClient
        {
            $this->credentials[] = ['organization' => $organization, 'pat' => $pat];
            $requests = &$this->requests;
            $file = implode("\r\n", array_map(fn (int $n): string => "line {$n}", range(1, 60)));
            $respond = function (RequestInterface $request) use (&$requests, $file): Response {
                $requests[] = $request;

                // The repository has no "checkout" folder: it is where a build checked it out.
                $path = requestQuery($request)['path'];

                return str_contains($path, 'missing') || str_starts_with($path, '/checkout/')
                    ? new Response(404)
                    : new Response(200, [], json_encode(['content' => $file], JSON_THROW_ON_ERROR));
            };

            return new AzDoClient($organization, $pat, 'https://dev.azure.com', new Client(['handler' => HandlerStack::create(new MockHandler(array_fill(0, 5, $respond)))]), new Client(['handler' => new MockHandler]));
        }
    });

    $this->reader = mcpUser('Reader');
    app(Vault::class)->set('azdo-repos.pat', $this->reader->id, 'user-pat');
    app(Vault::class)->set('azdo-repos.organization', $this->reader->id, 'contoso');
    app(Vault::class)->set('azdo-repos.pat', null, 'system-pat');

    $this->project = SoftwareSystem::factory()->create(['source_id' => 'azdo', 'source_system_id' => 'project-guid', 'name' => 'Payments']);
    $this->repo = SecurityContainer::factory()->forSystem($this->project)->create([
        'source_container_id' => 'repo-guid',
        'name' => 'payments-api',
        'metadata' => SourceContextFacts::set([], SourceContextFacts::CODE_DEFAULT_BRANCH, 'main'),
    ]);
});

function requestQuery(RequestInterface $request): array
{
    parse_str($request->getUri()->getQuery(), $query);

    return $query;
}

it('shows the code around a local finding with the user\'s own credential, on the default branch', function () {
    $finding = LocalFinding::query()->create([
        'owner_type' => SecurityContainer::class,
        'owner_id' => $this->repo->id,
        'software_system_id' => $this->project->id,
        'kind' => LocalFinding::KIND_SECRET,
        'rule_id' => 'aws-access-key',
        'title' => 'AWS key',
        'file_path' => 'src/Config.cs',
        'start_line' => 30,
    ]);

    AppSecScoutServer::actingAs($this->reader)->tool(GetCodeTool::class, ['local_finding_id' => $finding->id, 'context_lines' => 2])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('container', ['id' => $this->repo->id, 'name' => 'payments-api'])
            ->where('path', '/src/Config.cs')
            ->where('reported_path', '/src/Config.cs')
            ->where('branch', 'main')
            ->where('total_lines', 60)
            ->where('focus_line', 30)
            ->where('start_line', 28)
            ->where('end_line', 32)
            ->where('lines.2', ['number' => 30, 'text' => 'line 30'])
            ->has('lines', 5));

    expect($this->credentials)->toBe([['organization' => 'contoso', 'pat' => 'user-pat']])
        ->and($this->requests[0]->getUri()->getPath())->toBe('project-guid/_apis/git/repositories/repo-guid/items')
        ->and(requestQuery($this->requests[0]))->toMatchArray(['path' => '/src/Config.cs', 'versionDescriptor_version' => 'main']);
});

it('shows the code around an alert on its own branch, clamped at the file end', function () {
    $alert = SecurityEvent::factory()->forContainer($this->repo)->create(['file_path' => '/src/Crypto.cs', 'start_line' => 59, 'branch' => 'refs/heads/release']);

    AppSecScoutServer::actingAs($this->reader)->tool(GetCodeTool::class, ['alert_id' => $alert->id, 'context_lines' => 5])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('branch', 'release')
            ->where('start_line', 54)
            ->where('end_line', 60)
            ->etc());
});

it('resolves an alert path that includes the build checkout folder to the file in the repository', function () {
    $alert = SecurityEvent::factory()->forContainer($this->repo)->create(['file_path' => 'checkout/src/Crypto.cs', 'start_line' => 30, 'branch' => 'main']);

    AppSecScoutServer::actingAs($this->reader)->tool(GetCodeTool::class, ['alert_id' => $alert->id, 'context_lines' => 1])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('path', '/src/Crypto.cs')
            ->where('reported_path', '/checkout/src/Crypto.cs')
            ->where('focus_line', 30)
            ->etc());

    expect(array_map(fn (RequestInterface $request): string => requestQuery($request)['path'], $this->requests))
        ->toBe(['/checkout/src/Crypto.cs', '/src/Crypto.cs']);
});

it('fails when no trailing part of a finding path exists, and reads a given path as given', function () {
    $alert = SecurityEvent::factory()->forContainer($this->repo)->create(['file_path' => 'checkout/missing.txt', 'branch' => 'main']);

    AppSecScoutServer::actingAs($this->reader)->tool(GetCodeTool::class, ['alert_id' => $alert->id])
        ->assertHasErrors(['File /checkout/missing.txt not found in the repository at branch main. No shorter trailing part of that path exists there either.']);
    AppSecScoutServer::actingAs($this->reader)->tool(GetCodeTool::class, ['container_id' => $this->repo->id, 'path' => 'checkout/src/Crypto.cs'])
        ->assertHasErrors(['File /checkout/src/Crypto.cs not found in the repository at branch main.']);

    expect(array_map(fn (RequestInterface $request): string => requestQuery($request)['path'], $this->requests))
        ->toBe(['/checkout/missing.txt', '/missing.txt', '/checkout/src/Crypto.cs']);
});

it('reads any file of a container, from the top when no line is given, from its source project when re-linked', function () {
    $relinked = SecurityContainer::factory()->relinked($this->project, SoftwareSystem::factory()->create(['source_id' => 'asoc']))->create(['source_container_id' => 'other-repo']);

    AppSecScoutServer::actingAs($this->reader)->tool(GetCodeTool::class, ['container_id' => $relinked->id, 'path' => 'README.md', 'context_lines' => 1, 'branch' => 'dev'])
        ->assertOk()
        ->assertStructuredContent(fn ($json) => $json
            ->where('start_line', 1)
            ->where('end_line', 3)
            ->where('focus_line', null)
            ->where('branch', 'dev')
            ->etc());

    expect($this->requests[0]->getUri()->getPath())->toBe('project-guid/_apis/git/repositories/other-repo/items');
});

it('refuses what has no Azure DevOps code to show', function () {
    $asoc = SecurityContainer::factory()->forSystem(SoftwareSystem::factory()->create(['source_id' => 'asoc']))->create();
    $systemFinding = LocalFinding::query()->create([
        'owner_type' => SoftwareSystem::class, 'owner_id' => $this->project->id, 'software_system_id' => $this->project->id,
        'kind' => LocalFinding::KIND_VULNERABILITY, 'rule_id' => 'CVE-1', 'title' => 'x', 'file_path' => 'a.txt',
    ]);
    $noPath = SecurityEvent::factory()->forContainer($this->repo)->create(['file_path' => null]);

    AppSecScoutServer::actingAs($this->reader)->tool(GetCodeTool::class, ['container_id' => $asoc->id, 'path' => 'a.txt'])
        ->assertHasErrors(["Security container #{$asoc->id} ({$asoc->name}) is not an Azure DevOps repository: code access covers Azure DevOps repositories only."]);
    AppSecScoutServer::actingAs($this->reader)->tool(GetCodeTool::class, ['local_finding_id' => $systemFinding->id])
        ->assertHasErrors(["Local finding #{$systemFinding->id} belongs to a software system or asset, not to a repository, so it has no code to show."]);
    AppSecScoutServer::actingAs($this->reader)->tool(GetCodeTool::class, ['alert_id' => $noPath->id])
        ->assertHasErrors(["Alert #{$noPath->id} has no file path, so it has no code to show."]);
    AppSecScoutServer::actingAs($this->reader)->tool(GetCodeTool::class, ['container_id' => $this->repo->id, 'path' => 'a.txt', 'line' => 61])
        ->assertHasErrors(['Line 61 is beyond the end of the file (60 lines).']);
    AppSecScoutServer::actingAs($this->reader)->tool(GetCodeTool::class, ['container_id' => $this->repo->id, 'path' => 'missing.txt'])
        ->assertHasErrors(['File /missing.txt not found in the repository at branch main.']);
    AppSecScoutServer::actingAs($this->reader)->tool(GetCodeTool::class, ['alert_id' => $noPath->id, 'container_id' => $this->repo->id, 'path' => 'a.txt'])
        ->assertHasErrors(['Provide exactly one of local_finding_id, alert_id, or container_id with path.']);
});

it('refuses a user without a personal credential, and one without alerts.view', function () {
    AppSecScoutServer::actingAs(mcpUser('Reader'))->tool(GetCodeTool::class, ['container_id' => $this->repo->id, 'path' => 'a.txt'])
        ->assertHasErrors([AzDoReposAccess::MISSING_CREDENTIAL]);
    AppSecScoutServer::actingAs(mcpUser(null))->tool(GetCodeTool::class, ['container_id' => $this->repo->id, 'path' => 'a.txt'])
        ->assertHasErrors(['Permission denied: this tool requires the alerts.view permission.']);

    expect($this->credentials)->toBe([]);
});
