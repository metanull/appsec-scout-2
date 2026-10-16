<?php

namespace App\Trackers\Dto;

/**
 * A work item just created and linked, plus why its operator comment could not be posted (null
 * when there was no comment or it was posted). The work item exists either way.
 */
final readonly class CreatedWorkItem
{
    public function __construct(
        public WorkItemDto $workItem,
        public ?string $commentError = null,
    ) {}
}
