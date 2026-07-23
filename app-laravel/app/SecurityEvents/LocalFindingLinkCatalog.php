<?php

namespace App\SecurityEvents;

use App\Models\CuratedLink;
use App\Models\LocalFinding;
use App\Models\LocalFindingWorkItemLink;
use App\Models\SecurityContainer;
use App\Models\SoftwareSystem;
use App\SourceCode\CodeLocation;
use App\SourceCode\RepositoryCodeIdentity;
use App\SourceCode\RepositoryCodeIdentityResolver;
use App\SourceCode\RepositoryCodeUrlGenerator;

/**
 * Builds the same deduplicated, typed "Links & References" catalog the alert
 * view shows (EventLinkCatalog), but for a LocalFinding.
 *
 * A finding carries no source-provided alert/version-control URL, so its
 * Source:System, Code:Repository and Code:SourceFile links are derived from the
 * identity the owning SoftwareSystem/SecurityContainer already stores — the
 * same repository facts the SBOM/static-analysis scan (and a live source sync)
 * recorded when the org was enumerated. No RepositoryMapping is required; an
 * operator override is honoured when present via RepositoryCodeIdentityResolver.
 *
 * Each entry is an array{label: string, url: string, kind: string, external: bool}.
 */
final class LocalFindingLinkCatalog
{
    public function __construct(
        private readonly RepositoryCodeIdentityResolver $repositoryCodeIdentityResolver,
        private readonly RepositoryCodeUrlGenerator $repositoryCodeUrlGenerator,
    ) {}

    /**
     * @return list<array{label: string, url: string, kind: string, external: bool}>
     */
    public function build(LocalFinding $finding): array
    {
        $collector = new LinkCollector;

        $add = static function (string $label, ?string $url, string $kind, int $priority = 0) use ($collector): void {
            $collector->add($label, $url, $kind, $priority);
        };

        $owner = $finding->owner;
        $container = $owner instanceof SecurityContainer ? $owner : null;
        $system = $finding->softwareSystem;

        $container?->loadMissing(['repositoryMappings.repositoryProvider', 'curatedLinks']);
        $system?->loadMissing(['repositoryMappings.repositoryProvider', 'curatedLinks']);

        // Source system URL
        if ($system instanceof SoftwareSystem && is_string($system->url)) {
            $add('System', $system->url, LinkCollector::KIND_SOURCE, 1);
            $this->addCuratedLinks($system->curatedLinks, $add, 1);
        }

        // Container (repository) URL
        if ($container instanceof SecurityContainer && is_string($container->url)) {
            $add('Repository', $container->url, LinkCollector::KIND_SOURCE, 2);
            $this->addCuratedLinks($container->curatedLinks, $add, 2);
        }

        // Code repository + source file, from the container/system's own identity
        $this->addRepositoryLinks($finding, $container, $system, $add);

        // Rule documentation captured by the SARIF parser
        $metadata = $finding->getAttribute('metadata');
        if (is_array($metadata) && isset($metadata['helpUri']) && is_string($metadata['helpUri'])) {
            $add('Rule documentation', $metadata['helpUri'], LinkCollector::KIND_REMEDIATION);
        }

        // Work item links
        foreach ($finding->workItemLinks as $link) {
            /** @var LocalFindingWorkItemLink $link */
            if (is_string($link->work_item_url) && $link->work_item_url !== '') {
                $label = trim(sprintf('%s: %s', $link->tracker_id, $link->work_item_title ?? $link->work_item_id));
                $add($label, $link->work_item_url, LinkCollector::KIND_TRACKER, 0);
            }
        }

        return $collector->all();
    }

    /**
     * @param  callable(string, ?string, string, int): void  $add
     */
    private function addRepositoryLinks(
        LocalFinding $finding,
        ?SecurityContainer $container,
        ?SoftwareSystem $system,
        callable $add,
    ): void {
        $identity = $this->repositoryCodeIdentityResolver->resolve($container, $system);

        if (! $identity instanceof RepositoryCodeIdentity) {
            return;
        }

        $add('Repository', $this->repositoryCodeUrlGenerator->repositoryUrlFor($identity), LinkCollector::KIND_CODE, 3);

        $filePath = $finding->file_path;

        if (! is_string($filePath) || $filePath === '') {
            return;
        }

        $location = new CodeLocation(
            filePath: $filePath,
            startLine: is_int($finding->start_line) ? $finding->start_line : null,
            endLine: is_int($finding->end_line) ? $finding->end_line : null,
        );

        $fileUrl = $this->repositoryCodeUrlGenerator->fileUrlFor($identity, $location);
        $add('Source file', $fileUrl, LinkCollector::KIND_CODE, 3);
    }

    /**
     * @param  iterable<CuratedLink>|null  $links
     * @param  callable(string, ?string, string, int): void  $add
     */
    private function addCuratedLinks(?iterable $links, callable $add, int $priority): void
    {
        if ($links === null) {
            return;
        }

        foreach ($links as $link) {
            $add($link->label, $link->url, $link->kind, $priority);
        }
    }
}
