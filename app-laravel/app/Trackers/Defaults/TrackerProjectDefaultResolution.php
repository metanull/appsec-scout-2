<?php

namespace App\Trackers\Defaults;

final class TrackerProjectDefaultResolution
{
    public function __construct(
        public readonly string $trackerId,
        public readonly ?string $projectKey,
        public readonly ?string $projectName,
        public readonly ?string $source,
        public readonly string $confidenceLabel,
        public readonly string $reasonText,
    ) {}

    public static function resolved(
        string $trackerId,
        string $projectKey,
        ?string $projectName,
        string $source,
        string $confidenceLabel,
        string $reasonText,
    ): self {
        return new self(
            trackerId: $trackerId,
            projectKey: $projectKey,
            projectName: $projectName,
            source: $source,
            confidenceLabel: $confidenceLabel,
            reasonText: $reasonText,
        );
    }

    public static function none(string $trackerId, string $reasonText): self
    {
        return new self(
            trackerId: $trackerId,
            projectKey: null,
            projectName: null,
            source: null,
            confidenceLabel: 'No default',
            reasonText: $reasonText,
        );
    }

    public function hasDefault(): bool
    {
        return $this->projectKey !== null;
    }
}
