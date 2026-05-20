<?php

namespace App\Sources\Dto;

use App\Models\Enums\EventSeverity;
use App\Models\Enums\EventState;
use App\Models\Enums\EventType;

final class EventDto
{
    public function __construct(
        public readonly string $sourceEventId,
        public readonly string $sourceSystemId,
        public readonly string $title,
        public readonly EventSeverity $severity,
        public readonly EventState $state,
        public readonly EventType $type,
        public readonly ?string $sourceContainerId = null,
        public readonly ?string $description = null,
        public readonly ?string $ruleId = null,
        public readonly ?string $fingerprint = null,
        public readonly ?string $url = null,
        public readonly ?string $remediation = null,
        public readonly ?string $filePath = null,
        public readonly ?int $startLine = null,
        public readonly ?int $endLine = null,
        public readonly ?string $snippet = null,
        public readonly ?string $commitSha = null,
        public readonly ?string $branch = null,
        public readonly ?string $versionControlUrl = null,
        public readonly ?string $sourceData = null,
        /** @var array<string, mixed>|null */
        public readonly ?array $metadata = null,
        public readonly ?\DateTimeInterface $firstSeenAt = null,
        public readonly ?\DateTimeInterface $lastSeenAt = null,
    ) {}
}
