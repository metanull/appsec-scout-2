<?php

namespace App\SourceControl\Collection\Hygiene;

/**
 * One folder of a repository holding committed build output or compiled binaries: everything
 * RepositoryHygieneCheck reports about it as a single finding.
 */
final readonly class CommittedBuildOutput
{
    /** @param  list<string>  $sampleFiles */
    public function __construct(
        public string $ruleId,
        public string $folder,
        public int $fileCount,
        public int $sizeBytes,
        public int $secretCount,
        public array $sampleFiles,
    ) {}

    /** A folder that also holds a Trivy secret finding leaks that secret once per copy. */
    public function severity(): string
    {
        return $this->secretCount > 0 ? 'HIGH' : 'MEDIUM';
    }
}
