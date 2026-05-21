<?php

namespace App\Trackers\Dto;

final class CreateWorkItemRequest
{
    /**
     * @param  list<string>  $labels
     */
    public function __construct(
        public readonly string $projectKey,
        public readonly string $itemType,
        public readonly string $title,
        public readonly string $description,
        public readonly array $labels = [],
        public readonly ?string $priority = null,
        public readonly ?string $assigneeId = null,
        public readonly ?string $parentId = null,
    ) {}
}
