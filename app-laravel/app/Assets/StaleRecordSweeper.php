<?php

namespace App\Assets;

use App\Models\Enums\EventState;
use App\Models\SecurityContainer;
use App\Models\SoftwareAsset;
use App\Models\SoftwareComponent;
use App\Models\SoftwareSystem;

/**
 * Mark-and-sweep staleness for rows populated by a full enumeration/scan pass — Source
 * fetchSystems()/fetchContainers(), Source Control fetchProjects()/fetchRepositories(), or an
 * Attachment ingestion. Given the ids touched during one complete pass, marks every other
 * existing row in the same scope as removed (or, for LocalFinding, resolved), and un-marks a
 * SoftwareSystem/SecurityContainer/SoftwareComponent that reappears in a later pass.
 *
 * Callers must only invoke a sweep method after a pass finishes without throwing — a partial
 * pass (e.g. an upstream API failure mid-enumeration) must never sweep, or rows that are
 * genuinely still present would be wrongly marked gone.
 */
final class StaleRecordSweeper
{
    /** @param  list<int>  $touchedIds */
    public function sweepSystems(string $sourceId, array $touchedIds): void
    {
        SoftwareSystem::query()->where('source_id', $sourceId)
            ->whereNotIn('id', $touchedIds)->whereNull('removed_at')
            ->update(['removed_at' => now()]);

        if ($touchedIds !== []) {
            SoftwareSystem::query()->where('source_id', $sourceId)
                ->whereIn('id', $touchedIds)->whereNotNull('removed_at')
                ->update(['removed_at' => null]);
        }
    }

    /** @param  list<int>  $touchedIds */
    public function sweepContainers(string $sourceId, array $touchedIds): void
    {
        $systemIds = SoftwareSystem::query()->where('source_id', $sourceId)->pluck('id');

        SecurityContainer::query()->whereIn('software_system_id', $systemIds)
            ->whereNotIn('id', $touchedIds)->whereNull('removed_at')
            ->update(['removed_at' => now()]);

        if ($touchedIds !== []) {
            SecurityContainer::query()->whereIn('software_system_id', $systemIds)
                ->whereIn('id', $touchedIds)->whereNotNull('removed_at')
                ->update(['removed_at' => null]);
        }
    }

    /** @param  list<int>  $touchedIds */
    public function sweepComponents(SoftwareAsset|SoftwareSystem|SecurityContainer $owner, array $touchedIds): void
    {
        $owner->softwareComponents()
            ->whereNotIn('id', $touchedIds)->whereNull('removed_at')
            ->update(['removed_at' => now()]);

        if ($touchedIds !== []) {
            $owner->softwareComponents()
                ->whereIn('id', $touchedIds)->whereNotNull('removed_at')
                ->update(['removed_at' => null]);
        }
    }

    /**
     * Auto-resolves LocalFinding rows of the given kind that dropped out of the latest scan,
     * unless an operator already moved them to a different status — a resolved status is never
     * reverted here on reappearance, since automation must never override a manual decision and
     * there is no way to tell a sweep-set Resolved apart from an operator-set one.
     *
     * @param  list<int>  $touchedIds
     */
    public function sweepFindingsAsResolved(SoftwareAsset|SoftwareSystem|SecurityContainer $owner, string $kind, array $touchedIds): void
    {
        $owner->localFindings()
            ->where('kind', $kind)
            ->whereNotIn('id', $touchedIds)
            ->where('status', EventState::Open->value)
            ->update(['status' => EventState::Resolved->value]);
    }
}
