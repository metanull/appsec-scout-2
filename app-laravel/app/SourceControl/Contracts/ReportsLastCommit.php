<?php

namespace App\SourceControl\Contracts;

use App\Sources\Dto\CommitDto;
use App\Sources\Dto\ContainerDto;
use App\Sources\Dto\SystemDto;

/**
 * Optional mixin for an EnumeratesInventory provider that can report the newest commit on a
 * repository's default branch. The inventory sync records it on the repository's container
 * (last_commit_sha/at/author); null means the repository has no commit to report (empty,
 * disabled, or without a default branch).
 */
interface ReportsLastCommit
{
    public function fetchLastCommit(SystemDto $project, ContainerDto $repository): ?CommitDto;
}
