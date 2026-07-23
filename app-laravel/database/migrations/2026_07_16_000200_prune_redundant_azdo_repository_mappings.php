<?php

use App\Models\RepositoryMapping;
use App\Models\SecurityContainer;
use App\Sources\AzDo\AzDoNormalizer;
use App\Sources\Context\SourceContextFacts;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Deletes machine-generated (created_by_user_id null) RepositoryMapping rows
 * whose owning AzDO SecurityContainer already resolves to its own code identity
 * (browse URL + source.provider), for which the container's identity is
 * authoritative. Operator-authored mappings and mappings on containers without a
 * native identity are left untouched.
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
        // Irreversible: the deleted rows are reconstructable from the AzDO source,
        // so there is nothing to restore.
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
