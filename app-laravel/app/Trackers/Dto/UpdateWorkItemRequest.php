<?php

namespace App\Trackers\Dto;

final class UpdateWorkItemRequest
{
    /**
     * @param  list<string>|null  $labels
     */
    public function __construct(
        public readonly ?string $title = null,
        public readonly ?string $description = null,
        public readonly ?string $state = null,
        public readonly ?array $labels = null,
        public readonly ?string $priority = null,
        public readonly ?string $assigneeId = null,
        public readonly ?string $parentId = null,
    ) {}
}
