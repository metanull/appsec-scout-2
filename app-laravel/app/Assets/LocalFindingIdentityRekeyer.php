<?php

namespace App\Assets;

use App\Models\Enums\EventState;
use App\Models\LocalFinding;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Re-keys every stored LocalFinding to LocalFinding::identityHash() — rows stored when the
 * start line was always part of the identity — and merges the rows that now share an
 * identity (e.g. one package version reported at two lockfile lines) into one, keeping all
 * their comments and work-item links (LocalFindingMerger). Idempotent: a second run finds
 * nothing to change.
 */
final class LocalFindingIdentityRekeyer
{
    public function __construct(private readonly LocalFindingMerger $merger) {}

    /** @return array{rekeyed: int, merged: int} */
    public function rekey(): array
    {
        /** @var array<string, list<array{id: int, old: string, new: string}>> $groups */
        $groups = [];

        LocalFinding::query()
            ->select(['id', 'owner_type', 'owner_id', 'kind', 'rule_id', 'file_path', 'start_line', 'package_name', 'package_version', 'metadata', 'dedup_hash'])
            ->chunkById(500, function (Collection $findings) use (&$groups): void {
                foreach ($findings as $finding) {
                    /** @var LocalFinding $finding */
                    $hash = $finding->currentIdentityHash();
                    $groups["{$finding->owner_type}|{$finding->owner_id}|{$finding->kind}|{$hash}"][] = [
                        'id' => $finding->id,
                        'old' => $finding->dedup_hash,
                        'new' => $hash,
                    ];
                }
            });

        $merged = 0;
        /** @var array<int, string> $newHashes survivor id => its new identity hash */
        $newHashes = [];

        foreach ($groups as $group) {
            if (count($group) === 1 && $group[0]['old'] === $group[0]['new']) {
                continue;
            }

            $rows = LocalFinding::query()
                ->withCount(['workItemLinks', 'comments'])
                ->whereKey(array_column($group, 'id'))
                ->get();
            $survivor = $this->survivor($rows);

            foreach ($rows as $row) {
                if ($row->id !== $survivor->id) {
                    $this->merger->merge($row, $survivor);
                    $merged++;
                }
            }

            if ($survivor->dedup_hash !== $group[0]['new']) {
                $newHashes[$survivor->id] = $group[0]['new'];
            }
        }

        // Two phases, so no row ever takes a hash another row still holds under the unique
        // (owner, kind, dedup_hash) index while the rewrite is half done.
        DB::transaction(function () use ($newHashes): void {
            foreach (array_keys($newHashes) as $id) {
                LocalFinding::query()->whereKey($id)->toBase()->update(['dedup_hash' => sha1("rekey\0{$id}")]);
            }

            foreach ($newHashes as $id => $hash) {
                LocalFinding::query()->whereKey($id)->toBase()->update(['dedup_hash' => $hash]);
            }
        });

        return ['rekeyed' => count($newHashes), 'merged' => $merged];
    }

    /**
     * The row carrying the operator's work survives: a status decision first, then work-item
     * links, comments and a severity override, then the most recently seen row.
     *
     * @param  Collection<int, LocalFinding>  $rows
     */
    private function survivor(Collection $rows): LocalFinding
    {
        /** @var LocalFinding $survivor */
        $survivor = $rows->sort(function (LocalFinding $a, LocalFinding $b): int {
            return $this->rank($b) <=> $this->rank($a) ?: $a->id <=> $b->id;
        })->first();

        return $survivor;
    }

    /** @return list<int> */
    private function rank(LocalFinding $finding): array
    {
        $lastSeenAt = $finding->getAttribute('last_seen_at');

        return [
            $finding->getAttribute('status') === EventState::Open ? 0 : 1,
            (int) $finding->getAttribute('work_item_links_count') > 0 ? 1 : 0,
            (int) $finding->getAttribute('comments_count') > 0 ? 1 : 0,
            $finding->getAttribute('overridden_severity') === null ? 0 : 1,
            $lastSeenAt instanceof \DateTimeInterface ? $lastSeenAt->getTimestamp() : 0,
        ];
    }
}
