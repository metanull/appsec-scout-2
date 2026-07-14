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
        public readonly ?string $ambiguityWarning = null,
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

    /**
     * Attaches a warning about an ambiguous tracker project link configuration (e.g. multiple
     * links at one level with no single one marked default) without changing whether a default
     * was actually resolved — the ambiguity may be at a level that was skipped in favor of a
     * later one that did resolve.
     */
    public function withAmbiguityWarning(string $warning): self
    {
        return new self(
            trackerId: $this->trackerId,
            projectKey: $this->projectKey,
            projectName: $this->projectName,
            source: $this->source,
            confidenceLabel: $this->confidenceLabel,
            reasonText: $this->reasonText,
            ambiguityWarning: $warning,
        );
    }
}
