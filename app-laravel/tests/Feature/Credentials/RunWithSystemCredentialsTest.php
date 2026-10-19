<?php

use App\Assets\AttachmentService;
use App\Assets\DependencyTrack\DependencyTrackClient;
use App\Assets\DependencyTrack\DependencyTrackClientFactory;
use App\Credentials\RunWithSystemCredentials;
use App\Credentials\Vault;
use App\Events\AttachmentStored;
use App\Listeners\PushSbomAttachmentToDependencyTrack;
use App\Models\Attachment;
use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;
use App\Models\RepositoryCollectionRun;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\StaticAnalysisRun;
use App\Models\User;
use App\SourceControl\Collection\AnalyzeRepositoryJob;
use App\SourceControl\Collection\CollectRepositoryJob;
use App\SourceControl\Collection\RepositoryCollectionTarget;
use App\Sources\Dto\EventDto;
use App\Sync\EnrichAzDoSecretJob;
use App\Sync\RefetchEventJob;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Tests\Fakes\FakeSource;

/**
 * Background work is system-triggered: a job or queued listener reads the system credential even
 * when it runs synchronously inside a user's owner scope, and never inherits that user's.
 */
function runAsUser(User $user, Closure $callback): void
{
    app(Vault::class)->runAsOwner($user->id, function () use ($callback): void {
        $callback();
    });
}

function ownerIsolationTarget(): RepositoryCollectionTarget
{
    return new RepositoryCollectionTarget(
        projectId: 'project-001',
        projectName: 'SecurityProject',
        projectDescription: null,
        projectUrl: 'https://dev.azure.com/testorg/SecurityProject',
        repositoryId: 'repo-001',
        repositoryName: 'backend-api',
        repositoryBrowseUrl: 'https://dev.azure.com/testorg/SecurityProject/_git/backend-api',
        repositoryCloneUrl: 'https://testorg@dev.azure.com/testorg/SecurityProject/_git/backend-api',
        defaultBranch: 'main',
    );
}

it('declares the system-credential middleware on every job and listener that reads credentials', function (object $handler) {
    $middleware = $handler instanceof PushSbomAttachmentToDependencyTrack
        ? $handler->middleware(new AttachmentStored(new Attachment))
        : $handler->middleware();

    expect($middleware)->toContainOnlyInstancesOf(RunWithSystemCredentials::class)->toHaveCount(1);
})->with([
    'EnrichAzDoSecretJob' => fn () => new EnrichAzDoSecretJob('azdo', 1, 'proj-1', 'repo-1', 1),
    'CollectRepositoryJob' => fn () => new CollectRepositoryJob(ownerIsolationTarget(), 1),
    'AnalyzeRepositoryJob' => fn () => new AnalyzeRepositoryJob(ownerIsolationTarget(), 1),
    'RefetchEventJob' => fn () => new RefetchEventJob(1),
    'PushSbomAttachmentToDependencyTrack' => fn () => app(PushSbomAttachmentToDependencyTrack::class),
]);

it('refetches with the system credential when dispatched inside a user owner scope', function () {
    $user = User::factory()->create();
    app(Vault::class)->set('fake.apiKey', null, 'system-key');
    app(Vault::class)->set('fake.apiKey', $user->id, 'user-key');

    $source = new class extends FakeSource
    {
        /** @var list<?string> */
        public array $tokens = [];

        public function fetchRawEvent(SecurityEvent $event): EventDto
        {
            $this->tokens[] = app(Vault::class)->get('fake.apiKey');

            return parent::fetchRawEvent($event);
        }
    };
    $source->withRawEvent(new EventDto(
        sourceEventId: 'evt-001',
        sourceSystemId: 'sys-001',
        sourceContainerId: null,
        title: 'Refetched title',
        severity: EventSeverity::High,
        state: EventState::Open,
        type: EventType::Vulnerability,
    ));
    app()->bind('appsec-scout.source.fake', fn () => $source);
    app()->tag(['appsec-scout.source.fake'], 'appsec-scout.source');

    $system = SoftwareSystem::factory()->create(['source_id' => 'fake', 'source_system_id' => 'sys-001']);
    $event = SecurityEvent::factory()->create([
        'source_id' => 'fake',
        'source_event_id' => 'evt-001',
        'software_system_id' => $system->id,
    ]);

    runAsUser($user, fn () => RefetchEventJob::dispatch($event->id));

    expect($source->tokens)->toBe(['system-key']);
});

it('does not enrich an AzDO secret with the dispatching user credential', function () {
    $user = User::factory()->create();
    app(Vault::class)->set('azdo.pat', $user->id, 'user-pat');
    app(Vault::class)->set('azdo.organization', $user->id, 'user-org');
    $event = SecurityEvent::factory()->secret()->create(['source_id' => 'azdo']);

    expect(fn () => runAsUser($user, fn () => EnrichAzDoSecretJob::dispatch('azdo', $event->id, 'proj-1', 'repo-1', 3001)))
        ->toThrow(RuntimeException::class, 'AzDO PAT not configured');
});

it('does not collect a repository with the dispatching user credential', function () {
    $user = User::factory()->create();
    app(Vault::class)->set('azdo-repos.pat', $user->id, 'user-pat');
    $run = RepositoryCollectionRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now(),
        'status' => 'running',
        'counts_json' => ['repositories_considered' => 1, 'repositories_completed' => 0, 'repositories_failed' => 0],
    ]);

    expect(fn () => runAsUser($user, fn () => CollectRepositoryJob::dispatch(ownerIsolationTarget(), $run->id)))
        ->toThrow(RuntimeException::class, 'AzDO Repos PAT not configured.');
});

it('does not analyze a repository with the dispatching user credential', function () {
    $user = User::factory()->create();
    app(Vault::class)->set('azdo-repos.pat', $user->id, 'user-pat');
    $run = StaticAnalysisRun::query()->create([
        'source_control_id' => 'azdo-repos',
        'started_at' => now(),
        'status' => 'running',
        'counts_json' => ['repositories_considered' => 1, 'repositories_completed' => 0, 'repositories_failed' => 0],
    ]);

    expect(fn () => runAsUser($user, fn () => AnalyzeRepositoryJob::dispatch(ownerIsolationTarget(), $run->id)))
        ->toThrow(RuntimeException::class, 'AzDO Repos PAT not configured.');
});

it('pushes an SBOM with the system Dependency-Track key when stored inside a user owner scope', function () {
    $user = User::factory()->create();
    app(Vault::class)->set('dependencytrack.apiKey', null, 'system-dt-key');
    app(Vault::class)->set('dependencytrack.apiKey', $user->id, 'user-dt-key');

    $history = [];
    $stack = HandlerStack::create(new MockHandler([new Response(200, [], '{}')]));
    $stack->push(Middleware::history($history));
    app()->instance(DependencyTrackClientFactory::class, new class($stack) extends DependencyTrackClientFactory
    {
        public function __construct(private readonly HandlerStack $stack) {}

        public function make(string $apiKey, string $baseUrl): DependencyTrackClient
        {
            return new DependencyTrackClient($apiKey, $baseUrl, new Client(['handler' => $this->stack]));
        }
    });

    $container = SecurityContainer::factory()->create(['name' => 'backend-api']);

    runAsUser($user, fn () => app(AttachmentService::class)->attachTo(
        $container,
        'sbom',
        'application/json',
        'sbom.cdx.json',
        (string) file_get_contents(base_path('tests/Fixtures/Trivy/cyclonedx-sample.json')),
    ));

    expect($history)->toHaveCount(1)
        ->and($history[0]['request']->getHeaderLine('X-Api-Key'))->toBe('system-dt-key');
});
