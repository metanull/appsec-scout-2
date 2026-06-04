<?php

namespace App\Trackers\Defaults;

final class TrackerProjectDefaultContext
{
    public function __construct(
        public readonly ?string $virtualContainerTrackerId = null,
        public readonly ?string $virtualContainerProjectKey = null,
        public readonly ?string $virtualContainerProjectName = null,
        public readonly ?int $virtualSystemLinkId = null,
    ) {}

    public static function forVirtualContainer(string $trackerId, string $projectKey, ?string $projectName = null): self
    {
        return new self(
            virtualContainerTrackerId: trim($trackerId),
            virtualContainerProjectKey: trim($projectKey),
            virtualContainerProjectName: self::nullableTrim($projectName),
        );
    }

    public static function forVirtualSystemLink(int $linkId): self
    {
        return new self(virtualSystemLinkId: $linkId);
    }

    public function virtualContainerDefaultFor(string $trackerId): ?TrackerProjectDefaultResolution
    {
        if ($this->virtualContainerTrackerId === null || $this->virtualContainerProjectKey === null) {
            return null;
        }

        if ($this->virtualContainerTrackerId !== trim($trackerId)) {
            return null;
        }

        if ($this->virtualContainerProjectKey === '') {
            return null;
        }

        return TrackerProjectDefaultResolution::resolved(
            trackerId: $trackerId,
            projectKey: $this->virtualContainerProjectKey,
            projectName: $this->virtualContainerProjectName,
            source: 'virtual_container_context',
            confidenceLabel: 'Virtual container context',
            reasonText: 'Resolved from explicit virtual container context.',
        );
    }

    private static function nullableTrim(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
