<?php

namespace App\Trackers\Dto;

/**
 * DTO used by bulk reconciliation scans. The project is the tracker project the work item
 * currently belongs to, as reported by the tracker itself.
 */
final readonly class ReconciliationCandidateDto
{
    /**
     * @param  list<string>  $labels
     * @param  list<string>  $extractedUrls
     */
    public function __construct(
        public string $trackerId,
        public string $workItemId,
        public ?string $workItemUrl,
        public string $title,
        public string $state,
        public array $labels,
        public array $extractedUrls,
        public string $searchStrategy,
        public ?ProjectDto $project = null,
    ) {}
}
