<?php

use App\Credentials\Vault;
use App\Filament\Resources\SecurityEventResource;
use App\Mcp\Support\AzDoReposAccess;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\User;
use App\SecurityEvents\EventLinkCatalog;
use App\Sources\AzDo\AzDoClient;
use App\Sources\AzDo\AzDoClientFactory;
use App\Sources\Context\SourceContextFacts;
use Database\Seeders\RolePermissionSeeder;
use Filament\Notifications\Livewire\Notifications;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

beforeEach(function () {
    (new RolePermissionSeeder)->run();
    $this->itemUrl = 'https://dev.azure.com:443/contoso/project-guid/_git/repo-guid?path=%2Fproject%2Fcore-web%2FApp.java&version=GCabc123&line=12&lineStyle=plain';
    $this->pats = [];
    $pats = &$this->pats;
    $this->requests = [];
    $requests = &$this->requests;

    app()->instance(AzDoClientFactory::class, new class($requests, $pats) extends AzDoClientFactory
    {
        /**
         * @param  list<RequestInterface>  $requests
         * @param  list<string>  $pats
         */
        public function __construct(private array &$requests, private array &$pats) {}

        public function make(string $organization, string $pat): AzDoClient
        {
            $this->pats[] = $pat;
            $requests = &$this->requests;
            $respond = function (RequestInterface $request) use (&$requests): Response {
                $requests[] = $request;
                parse_str($request->getUri()->getQuery(), $query);

                // The repository has no "project" folder: it is where the build checked it out.
                return str_starts_with($query['path'], '/project/') || str_contains($query['path'], 'Gone')
                    ? new Response(404)
                    : new Response(200, [], json_encode(['content' => 'class App {}'], JSON_THROW_ON_ERROR));
            };

            return new AzDoClient($organization, $pat, 'https://dev.azure.com', new Client(['handler' => HandlerStack::create(new MockHandler(array_fill(0, 5, $respond)))]), new Client(['handler' => new MockHandler]));
        }
    });

    $this->user = User::factory()->create([
        'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'),
        'two_factor_recovery_codes' => encrypt(json_encode(['code-1'])),
        'two_factor_confirmed_at' => now(),
    ]);
    $this->user->syncRoles(['Reader']);
    app(Vault::class)->set('azdo-repos.pat', $this->user->id, 'user-pat');
    app(Vault::class)->set('azdo-repos.organization', $this->user->id, 'contoso');

    $project = SoftwareSystem::factory()->create(['source_id' => 'azdo', 'source_system_id' => 'project-guid', 'name' => 'Agora']);
    $metadata = SourceContextFacts::set([], SourceContextFacts::SOURCE_PROVIDER, 'azure-repos');
    $repo = SecurityContainer::factory()->forSystem($project)->create([
        'source_container_id' => 'repo-guid',
        'name' => 'Agora',
        'url' => 'https://dev.azure.com/contoso/Agora/_git/Agora',
        'metadata' => SourceContextFacts::set($metadata, SourceContextFacts::CODE_DEFAULT_BRANCH, 'main'),
    ]);

    $this->alert = SecurityEvent::factory()->forContainer($repo)->create([
        'url' => 'https://dev.azure.com/contoso/Agora/_git/Agora/alerts/7',
        'file_path' => 'project/core-web/App.java',
        'start_line' => 12,
        'end_line' => 12,
        'branch' => 'master',
        'commit_sha' => 'abc123',
        'version_control_url' => $this->itemUrl,
        'metadata' => ['links' => [['label' => 'Source file', 'url' => $this->itemUrl]]],
    ]);
});

/** @return list<string> */
function probedPaths(array $requests): array
{
    return array_map(function (RequestInterface $request): string {
        parse_str($request->getUri()->getQuery(), $query);

        return $query['path'] . '@' . $query['versionDescriptor_versionType'] . ':' . $query['versionDescriptor_version'];
    }, $requests);
}

it('opens the current source file without the build checkout folder, resolved with the user\'s credential', function () {
    $this->actingAs($this->user)
        ->get(route('alerts.source', ['event' => $this->alert, 'version' => 'current']))
        ->assertRedirect('https://dev.azure.com/contoso/Agora/_git/Agora?path=/core-web/App.java&version=GBmaster&line=12&lineStartColumn=1&lineEndColumn=1');

    expect(probedPaths($this->requests))->toBe(['/project/core-web/App.java@branch:master', '/core-web/App.java@branch:master'])
        ->and($this->pats)->toBe(['user-pat']);
});

it('opens the source file at the alert\'s commit, and caches the resolved path', function () {
    $url = route('alerts.source', ['event' => $this->alert, 'version' => 'commit']);
    $expected = 'https://dev.azure.com/contoso/Agora/_git/Agora?path=/core-web/App.java&version=GCabc123&line=12&lineStartColumn=1&lineEndColumn=1';

    $this->actingAs($this->user)->get($url)->assertRedirect($expected);
    $this->actingAs($this->user)->get($url)->assertRedirect($expected);

    expect(probedPaths($this->requests))->toBe(['/project/core-web/App.java@commit:abc123', '/core-web/App.java@commit:abc123']);
});

it('returns to the alert with the reason when the source file cannot be opened', function (?string $filePath, string $reason) {
    if ($filePath === null) {
        app(Vault::class)->forget('azdo-repos.pat', $this->user->id);
    } else {
        $this->alert->update(['file_path' => $filePath]);
    }

    $this->actingAs($this->user)
        ->get(route('alerts.source', ['event' => $this->alert, 'version' => 'current']))
        ->assertRedirect(SecurityEventResource::getUrl('view', ['record' => $this->alert]));

    $notifications = new Notifications;
    $notifications->mount();
    $notifications->pullNotificationsFromSession();
    $notification = $notifications->notifications->first();

    expect($notification?->getTitle())->toBe('Could not open the source file')
        ->and($notification?->getBody())->toContain($reason);
})->with([
    'no Azure DevOps Repos credential' => [null, AzDoReposAccess::MISSING_CREDENTIAL],
    'file in no part of the repository' => ['project/Gone.java', 'not found in the repository'],
]);

it('has no source link to open without a file, without a commit, or without alerts.view', function () {
    $this->actingAs($this->user)->get('/alerts/' . $this->alert->id . '/source/tip')->assertNotFound();

    $this->alert->update(['commit_sha' => null]);
    $this->actingAs($this->user)->get(route('alerts.source', ['event' => $this->alert, 'version' => 'commit']))->assertNotFound();

    $this->alert->update(['file_path' => null]);
    $this->actingAs($this->user)->get(route('alerts.source', ['event' => $this->alert, 'version' => 'current']))->assertNotFound();

    $this->user->syncRoles([]);
    $this->actingAs($this->user)->get(route('alerts.source', ['event' => $this->alert, 'version' => 'current']))->assertForbidden();

    expect($this->requests)->toBe([]);
});

it('lists the app source links in the view catalog in place of the static ones, which tracker descriptions keep', function () {
    $alert = $this->alert->fresh(['softwareSystem', 'container', 'workItemLinks']);

    $view = collect(app(EventLinkCatalog::class)->buildForView($alert))->pluck('url', 'label');
    $static = collect(app(EventLinkCatalog::class)->build($alert))->pluck('url');

    expect($view->get('Source file'))->toBe(route('alerts.source', ['event' => $alert, 'version' => 'current']))
        ->and($view->get('Source file (commit)'))->toBe(route('alerts.source', ['event' => $alert, 'version' => 'commit']))
        ->and($view->values()->all())->not->toContain($this->itemUrl)
        ->and($static->all())->toContain($this->itemUrl);
});

it('shows View alert, View source and View source (commit) on the Summary tab', function () {
    $this->actingAs($this->user)
        ->get(SecurityEventResource::getUrl('view', ['record' => $this->alert]))
        ->assertOk()
        ->assertSeeInOrder(['View alert', 'View source', 'View source (commit)'])
        ->assertSee('https://dev.azure.com/contoso/Agora/_git/Agora/alerts/7', false)
        ->assertSee(route('alerts.source', ['event' => $this->alert, 'version' => 'commit']), false);

    expect($this->requests)->toBe([]);
});
