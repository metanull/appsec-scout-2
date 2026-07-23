<?php

namespace App\Context\Quality;

use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\SourceCode\RepositoryCodeIdentityResolver;
use Illuminate\Database\Eloquent\Model;

final class ContextQualityService
{
    public function __construct(
        private readonly RepositoryCodeIdentityResolver $identityResolver,
    ) {}

    /**
     * @return list<array{label: string, message: string, state: string, color: string, url: ?string}>
     */
    public function forSecurityEvent(SecurityEvent $event): array
    {
        $system = $event->softwareSystem;
        $container = $event->container;

        $codeLocationMissing = $this->hasFilePaths($event) && ! $this->hasCodeLocation($system, $container);
        $trackerMappingMissing = ! $this->hasTrackerMapping($system, $container);
        $sourceUrlMissing = ! filled($event->url);

        return [
            $this->indicator(
                'Code location',
                $codeLocationMissing ? 'Code location missing' : 'Code location ready',
                $codeLocationMissing ? 'File paths exist but this alert cannot be linked to source code — no repository identity or mapping is available.' : 'This alert can be linked to source code.',
                $codeLocationMissing ? 'warning' : 'success',
            ),
            $this->indicator(
                'Tracker mapping',
                $trackerMappingMissing ? 'Missing tracker mapping' : 'Tracker mapping ready',
                $trackerMappingMissing ? 'No tracker project link is configured for this alert context.' : 'Tracker project links are configured for this alert context.',
                $trackerMappingMissing ? 'warning' : 'success',
            ),
            $this->indicator(
                'Source URL',
                $sourceUrlMissing ? 'Source URL unavailable' : 'Source URL available',
                $sourceUrlMissing ? 'The alert does not currently expose a navigable source URL.' : 'A source URL is available for this alert.',
                $sourceUrlMissing ? 'warning' : 'success',
            ),
        ];
    }

    /**
     * @return list<array{label: string, message: string, state: string, color: string, url: ?string}>
     */
    public function forSoftwareSystem(SoftwareSystem $system): array
    {
        $codeLocationMissing = $this->hasFilePaths($system) && ! $this->hasCodeLocation($system, null);
        $trackerMappingMissing = ! $this->hasTrackerMapping($system, null);
        $sourceUrlMissing = ! filled($system->url);

        return [
            $this->indicator(
                'Code location',
                $codeLocationMissing ? 'Code location missing' : 'Code location ready',
                $codeLocationMissing ? 'This system has file paths but cannot be linked to source code — no repository identity or mapping is available.' : 'This system can be linked to source code.',
                $codeLocationMissing ? 'warning' : 'success',
            ),
            $this->indicator(
                'Tracker mapping',
                $trackerMappingMissing ? 'Missing tracker mapping' : 'Tracker mapping ready',
                $trackerMappingMissing ? 'No tracker project link is configured for this system.' : 'Tracker project links are configured for this system.',
                $trackerMappingMissing ? 'warning' : 'success',
            ),
            $this->indicator(
                'Source URL',
                $sourceUrlMissing ? 'Source URL unavailable' : 'Source URL available',
                $sourceUrlMissing ? 'This system does not currently expose a navigable source URL.' : 'A source URL is available for this system.',
                $sourceUrlMissing ? 'warning' : 'success',
            ),
        ];
    }

    /**
     * @return list<array{label: string, message: string, state: string, color: string, url: ?string}>
     */
    public function forSecurityContainer(SecurityContainer $container): array
    {
        $codeLocationMissing = $this->hasFilePaths($container) && ! $this->hasCodeLocation(null, $container);
        $trackerMappingMissing = ! $this->hasTrackerMapping(null, $container);
        $sourceUrlMissing = ! filled($container->url);

        return [
            $this->indicator(
                'Code location',
                $codeLocationMissing ? 'Code location missing' : 'Code location ready',
                $codeLocationMissing ? 'This container has file paths but cannot be linked to source code — no repository identity or mapping is available.' : 'This container can be linked to source code.',
                $codeLocationMissing ? 'warning' : 'success',
            ),
            $this->indicator(
                'Tracker mapping',
                $trackerMappingMissing ? 'Missing tracker mapping' : 'Tracker mapping ready',
                $trackerMappingMissing ? 'No tracker project link is configured for this container.' : 'Tracker project links are configured for this container.',
                $trackerMappingMissing ? 'warning' : 'success',
            ),
            $this->indicator(
                'Source URL',
                $sourceUrlMissing ? 'Source URL unavailable' : 'Source URL available',
                $sourceUrlMissing ? 'This container does not currently expose a navigable source URL.' : 'A source URL is available for this container.',
                $sourceUrlMissing ? 'warning' : 'success',
            ),
        ];
    }

    /**
     * @return array{label: string, message: string, state: string, color: string, url: ?string}
     */
    private function indicator(string $label, string $message, string $state, string $color, ?string $url = null): array
    {
        return [
            'label' => $label,
            'message' => $message,
            'state' => $state,
            'color' => $color,
            'url' => $url,
        ];
    }

    /**
     * Whether findings in this context can be linked to source code — either an
     * operator RepositoryMapping override exists, or the container carries its
     * own native code identity (browse URL + provider), which the link
     * machinery reads directly without a mapping.
     */
    private function hasCodeLocation(?SoftwareSystem $system, ?SecurityContainer $container): bool
    {
        if ($this->hasRepositoryMapping($system, $container)) {
            return true;
        }

        if ($container instanceof SecurityContainer && $this->identityResolver->containerIdentity($container) !== null) {
            return true;
        }

        $system ??= $container?->softwareSystem;

        if ($system instanceof SoftwareSystem) {
            foreach ($system->containers()->whereNotNull('url')->get() as $systemContainer) {
                if ($this->identityResolver->containerIdentity($systemContainer) !== null) {
                    return true;
                }
            }
        }

        return false;
    }

    private function hasRepositoryMapping(?SoftwareSystem $system, ?SecurityContainer $container): bool
    {
        if ($system instanceof SoftwareSystem && $system->repositoryMappings()->exists()) {
            return true;
        }

        if ($container instanceof SecurityContainer && $container->repositoryMappings()->exists()) {
            return true;
        }

        if ($system instanceof SoftwareSystem) {
            return $system->containers()->whereHas('repositoryMappings')->exists();
        }

        if ($container instanceof SecurityContainer && $container->softwareSystem instanceof SoftwareSystem) {
            return $container->softwareSystem->repositoryMappings()->exists()
                || $container->softwareSystem->containers()->whereHas('repositoryMappings')->exists();
        }

        return false;
    }

    private function hasTrackerMapping(?SoftwareSystem $system, ?SecurityContainer $container): bool
    {
        if ($system instanceof SoftwareSystem && $system->trackerProjectLinks()->exists()) {
            return true;
        }

        if ($container instanceof SecurityContainer && $container->trackerProjectLinks()->exists()) {
            return true;
        }

        if ($system instanceof SoftwareSystem) {
            return $system->containers()->whereHas('trackerProjectLinks')->exists();
        }

        if ($container instanceof SecurityContainer && $container->softwareSystem instanceof SoftwareSystem) {
            return $container->softwareSystem->trackerProjectLinks()->exists()
                || $container->softwareSystem->containers()->whereHas('trackerProjectLinks')->exists();
        }

        return false;
    }

    private function hasFilePaths(Model $record): bool
    {
        if ($record instanceof SecurityEvent) {
            return filled($record->file_path);
        }

        if ($record instanceof SoftwareSystem) {
            return $record->events()->whereNotNull('file_path')->exists();
        }

        if ($record instanceof SecurityContainer) {
            return $record->events()->whereNotNull('file_path')->exists();
        }

        return false;
    }
}
