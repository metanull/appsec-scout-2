<?php

namespace App\Mcp\Support;

use App\Models\SoftwareSystem;
use App\Sources\AzDo\AzDoNormalizer;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Which systems of a scope a repository run (collection or static analysis) can take. Those runs
 * only cover Azure DevOps repositories and are exclusive (one running at a time), so a scope
 * spanning several AzDO systems queues the first and reports the rest.
 */
final class RepositoryRunTargets
{
    /**
     * @param  Collection<int, SoftwareSystem>  $queued
     * @param  Collection<int, SoftwareSystem>  $notQueued
     * @param  Collection<int, SoftwareSystem>  $skipped
     */
    private function __construct(
        public readonly SoftwareSystem $first,
        private readonly Collection $queued,
        private readonly Collection $notQueued,
        private readonly Collection $skipped,
    ) {}

    /** @param  Collection<int, SoftwareSystem>  $systems  the scope's active systems */
    public static function from(Collection $systems, string $operation): self
    {
        $isAzDo = fn (SoftwareSystem $system): bool => $system->source_id === AzDoNormalizer::SOURCE_ID;
        $azdo = $systems->filter($isAzDo)->values();
        $skipped = $systems->reject($isAzDo);
        $first = $azdo->first();

        if ($first === null) {
            throw new InvalidArgumentException("No Azure DevOps system in this scope: {$operation} only covers Azure DevOps repositories.");
        }

        return new self($first, collect([$first]), $azdo->slice(1)->values(), $skipped->values());
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    public function report(string $operation): array
    {
        $row = fn (SoftwareSystem $system, ?string $reason = null): array => array_filter([
            'system_id' => $system->id,
            'name' => $system->name,
            'source_id' => $system->source_id,
            'reason' => $reason,
        ], fn (mixed $value): bool => $value !== null);

        return [
            'queued' => $this->queued->map(fn (SoftwareSystem $system): array => $row($system))->values()->all(),
            'not_queued' => $this->notQueued->map(fn (SoftwareSystem $system): array => $row($system, "One {$operation} run at a time; call again for this system once the current run finishes."))->values()->all(),
            'skipped' => $this->skipped->map(fn (SoftwareSystem $system): array => $row($system, "{$operation} only covers Azure DevOps repositories."))->values()->all(),
        ];
    }
}
