<?php

namespace App\Context\Quality;

use App\Models\SecurityContainer;
use App\Models\SecurityContainerLink;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
use App\Models\SoftwareSystemLink;
use Illuminate\Database\Eloquent\Model;

final class ContextQualityService
{
    /**
     * @return list<array{label: string, message: string, state: string, color: string, url: ?string}>
     */
    public function forSecurityEvent(SecurityEvent $event): array
    {
        $system = $event->softwareSystem;
        $container = $event->container;

        $repositoryMappingMissing = $this->hasFilePaths($event) && ! $this->hasRepositoryMapping($system, $container);
        $trackerMappingMissing = ! $this->hasTrackerMapping($system, $container);
        $sourceUrlMissing = ! filled($event->url);

        return [
            $this->indicator(
                'Repository mapping',
                $repositoryMappingMissing ? 'Missing repository mapping' : 'Repository mapping ready',
                $repositoryMappingMissing ? 'File paths exist but no repository mapping is available.' : 'Repository mappings are present for this alert context.',
                $repositoryMappingMissing ? 'warning' : 'success',
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
        $repositoryMappingMissing = $this->hasFilePaths($system) && ! $this->hasRepositoryMapping($system, null);
        $trackerMappingMissing = ! $this->hasTrackerMapping($system, null);
        $sourceUrlMissing = ! filled($system->url);

        return [
            $this->indicator(
                'Repository mapping',
                $repositoryMappingMissing ? 'Missing repository mapping' : 'Repository mapping ready',
                $repositoryMappingMissing ? 'This system has file paths but no repository mapping.' : 'This system already has repository mapping context.',
                $repositoryMappingMissing ? 'warning' : 'success',
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
        $system = $container->softwareSystem;
        $repositoryMappingMissing = $this->hasFilePaths($container) && ! $this->hasRepositoryMapping(null, $container);
        $trackerMappingMissing = ! $this->hasTrackerMapping(null, $container);
        $sourceUrlMissing = ! filled($container->url);

        return [
            $this->indicator(
                'Repository mapping',
                $repositoryMappingMissing ? 'Missing repository mapping' : 'Repository mapping ready',
                $repositoryMappingMissing ? 'This container has file paths but no repository mapping.' : 'This container already has repository mapping context.',
                $repositoryMappingMissing ? 'warning' : 'success',
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
     * @return list<array{label: string, message: string, state: string, color: string, url: ?string}>
     */
    public function forSoftwareSystemLink(SoftwareSystemLink $link): array
    {
        $members = $link->members()->get();
        $sourceUrlMissing = $members->contains(fn (SoftwareSystem $system): bool => ! filled($system->url));

        return [
            $this->indicator(
                'Members',
                $members->count() > 0 ? $members->count() . ' system member(s)' : 'No system members',
                $members->count() > 0 ? 'This virtual system has members that may need quality review.' : 'This virtual system has no members yet.',
                $members->count() > 0 ? 'info' : 'warning',
            ),
            $this->indicator(
                'Source URL',
                $sourceUrlMissing ? 'Some members lack source URLs' : 'Source URLs available',
                $sourceUrlMissing ? 'At least one system member does not expose a navigable source URL.' : 'All system members expose navigable source URLs.',
                $sourceUrlMissing ? 'warning' : 'success',
            ),
        ];
    }

    /**
     * @return list<array{label: string, message: string, state: string, color: string, url: ?string}>
     */
    public function forSecurityContainerLink(SecurityContainerLink $link): array
    {
        $members = $link->members()->get();
        $sourceUrlMissing = $members->contains(fn (SecurityContainer $container): bool => ! filled($container->url));

        return [
            $this->indicator(
                'Members',
                $members->count() > 0 ? $members->count() . ' container member(s)' : 'No container members',
                $members->count() > 0 ? 'This virtual container has members that may need quality review.' : 'This virtual container has no members yet.',
                $members->count() > 0 ? 'info' : 'warning',
            ),
            $this->indicator(
                'Source URL',
                $sourceUrlMissing ? 'Some members lack source URLs' : 'Source URLs available',
                $sourceUrlMissing ? 'At least one container member does not expose a navigable source URL.' : 'All container members expose navigable source URLs.',
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
