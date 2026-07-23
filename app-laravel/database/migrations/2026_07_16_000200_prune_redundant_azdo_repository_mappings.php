<?php

use App\Models\RepositoryMapping;
use App\Models\SecurityContainer;
use App\Sources\AzDo\AzDoNormalizer;
use App\Sources\Context\SourceContextFacts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Removes the machine-generated RepositoryMapping rows that AzDoProjectLinker
 * used to create for every synced AzDO repository. Those rows only restated the
 * container's own browse URL / provider / default branch — data the link
 * machinery now reads straight from the container's identity — so they are
 * redundant, show up as editable duplicates in the RepositoryMappings manager,
 * and add audit noise. Operator-authored mappings (created_by_user_id set) and
 * mappings for containers with no native identity are left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        $redundantIds = [];

        RepositoryMapping::query()
            ->where('owner_type', SecurityContainer::class)
            ->whereNull('created_by_user_id')
            ->with('owner.softwareSystem')
            ->chunkById(200, function ($mappings) use (&$redundantIds): void {
                foreach ($mappings as $mapping) {
                    if ($this->containerHasNativeIdentity($mapping->owner)) {
                        $redundantIds[] = $mapping->id;
                    }
                }
            });

        // Bulk delete via the query builder so the per-model audit hook does not
        // fire once per pruned row (this is housekeeping, not an operator action).
        foreach (array_chunk($redundantIds, 500) as $chunk) {
            DB::table('repository_mappings')->whereIn('id', $chunk)->delete();
        }
    }

    public function down(): void
    {
        // Irreversible: the pruned rows are reconstructable from the AzDO source
        // (and are no longer created), so there is nothing to restore.
    }

    private function containerHasNativeIdentity(mixed $container): bool
    {
        if (! $container instanceof SecurityContainer) {
            return false;
        }

        $system = $container->softwareSystem;

        if ($system === null || $system->source_id !== AzDoNormalizer::SOURCE_ID) {
            return false;
        }

        $url = $container->url;

        if (! is_string($url) || $url === '') {
            return false;
        }

        $metadata = $container->getAttribute('metadata');
        $provider = SourceContextFacts::getString(is_array($metadata) ? $metadata : [], SourceContextFacts::SOURCE_PROVIDER);

        return $provider !== null;
    }
};
