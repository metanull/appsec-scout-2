<?php

namespace App\SourceCode;

use App\Mcp\Support\AzDoReposAccess;
use App\Models\Enums\RepositoryProviderType;
use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\User;
use App\Sources\AzDo\AzDoClientFactory;
use App\Sources\AzDo\AzDoNormalizer;
use Illuminate\Contracts\Cache\Repository as Cache;
use InvalidArgumentException;

/**
 * Where an alert's source file opens: at the current code (the alert's branch, else the
 * repository's default branch) or at the commit the alert was raised on.
 *
 * The alert view links to it through the app (route alerts.source), not straight to the
 * provider, because a scanner's path can include the build's checkout folder ("project/…"),
 * which no static link can drop. For an Azure DevOps repository the redirect resolves the real
 * path against the repository with the clicking user's own Azure DevOps Repos credential (the
 * same resolution get-code does) and caches it per repository, path and version.
 */
final class AlertSourceLocator
{
    public const CURRENT = 'current';

    public const COMMIT = 'commit';

    public const VERSIONS = [self::CURRENT, self::COMMIT];

    private const CACHE_TTL_SECONDS = 86_400;

    public function __construct(
        private readonly RepositoryCodeIdentityResolver $identities,
        private readonly RepositoryCodeUrlGenerator $urls,
        private readonly AzDoReposAccess $access,
        private readonly AzDoClientFactory $clients,
        private readonly AzDoCheckoutPathResolver $paths,
        private readonly Cache $cache,
    ) {}

    /**
     * The app link that opens the alert's source file at $version, or null when the alert has
     * no such link: no file path, no repository identity, no ref to read at, or (for the commit
     * version) no commit.
     */
    public function appUrl(SecurityEvent $event, string $version): ?string
    {
        if ($this->providerUrl($event, $version, $event->file_path) === null) {
            return null;
        }

        return route('alerts.source', ['event' => $event, 'version' => $version]);
    }

    /**
     * The provider URL of the alert's source file at $version, with an Azure DevOps path
     * resolved to the file that exists in the repository.
     *
     * @throws InvalidArgumentException when the alert has no such link, the user has no Azure
     *                                  DevOps Repos credential, or the file is not in the repository
     */
    public function url(SecurityEvent $event, string $version, User $user): string
    {
        $path = $this->resolvesAgainstAzDo($event) ? $this->azDoPath($event, $version, $user) : $event->file_path;

        return $this->providerUrl($event, $version, $path)
            ?? throw new InvalidArgumentException("Alert #{$event->id} has no source file to open.");
    }

    private function providerUrl(SecurityEvent $event, string $version, ?string $path): ?string
    {
        if (! in_array($version, self::VERSIONS, true) || ! is_string($path) || $path === '') {
            return null;
        }

        $commit = $version === self::COMMIT ? $this->commit($event) : null;

        if ($version === self::COMMIT && $commit === null) {
            return null;
        }

        $identity = $this->identities->resolve($event->container, $event->softwareSystem);

        if (! $identity instanceof RepositoryCodeIdentity) {
            return null;
        }

        return $this->urls->fileUrlFor($identity, new CodeLocation(
            filePath: $path,
            startLine: is_int($event->start_line) ? $event->start_line : null,
            endLine: is_int($event->end_line) ? $event->end_line : null,
            branch: $this->branch($event),
            commitSha: $commit,
        ));
    }

    /**
     * Only the container's own Azure DevOps identity is resolved against: an operator's
     * repository mapping may point the code somewhere else, where this repository's paths
     * mean nothing, so its paths are used as reported.
     */
    private function resolvesAgainstAzDo(SecurityEvent $event): bool
    {
        $container = $event->container;

        if (! $container instanceof SecurityContainer || $container->sourceSoftwareSystem?->source_id !== AzDoNormalizer::SOURCE_ID) {
            return false;
        }

        $own = $this->identities->containerIdentity($container);

        return $own !== null
            && $own->providerType === RepositoryProviderType::AzureRepos
            && $own == $this->identities->resolve($container, $event->softwareSystem);
    }

    private function azDoPath(SecurityEvent $event, string $version, User $user): string
    {
        /** @var SecurityContainer $container */
        $container = $event->container;
        $credentials = $this->access->credentials($user);
        $reportedPath = '/' . ltrim((string) $event->file_path, '/');
        [$ref, $refType] = $version === self::COMMIT ? [$this->commit($event), 'commit'] : [$this->branch($event), 'branch'];
        $key = sprintf('alert-source-path:%d:%s', $container->id, sha1($refType . "\n" . $ref . "\n" . $reportedPath));

        return $this->cache->remember($key, self::CACHE_TTL_SECONDS, function () use ($container, $credentials, $reportedPath, $ref, $refType): string {
            $project = AzDoReposAccess::projectOf($container);
            $client = $this->clients->make($credentials['organization'], $credentials['pat']);

            return $this->paths->read($client, (string) $project->source_system_id, (string) $container->source_container_id, $reportedPath, $ref, $refType)['path'];
        });
    }

    private function commit(SecurityEvent $event): ?string
    {
        return is_string($event->commit_sha) && $event->commit_sha !== '' ? $event->commit_sha : null;
    }

    /** "refs/heads/main" and "main" both name the main branch. */
    private function branch(SecurityEvent $event): ?string
    {
        $branch = $event->branch;

        if (! is_string($branch) || trim($branch) === '') {
            return null;
        }

        return str_starts_with($branch, 'refs/heads/') ? substr($branch, strlen('refs/heads/')) : $branch;
    }
}
