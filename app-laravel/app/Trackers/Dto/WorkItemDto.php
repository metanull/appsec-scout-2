<?php

namespace App\Trackers\Dto;

final class WorkItemDto
{
    /**
     * @param  list<string>  $labels
     */
    public function __construct(
        public readonly string $id,
        public readonly string $projectKey,
        public readonly string $title,
        public readonly string $state,
        public readonly ?string $url = null,
        public readonly ?string $itemType = null,
        public readonly ?string $priority = null,
        public readonly ?UserDto $assignee = null,
        public readonly ?string $parentId = null,
        public readonly array $labels = [],
        public readonly ?string $description = null,
    ) {}
}
