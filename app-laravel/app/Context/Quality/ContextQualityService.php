<?php

namespace App\Context\Quality;

use App\Models\SecurityContainer;
use App\Models\SecurityEvent;
use App\Models\SoftwareSystem;
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
