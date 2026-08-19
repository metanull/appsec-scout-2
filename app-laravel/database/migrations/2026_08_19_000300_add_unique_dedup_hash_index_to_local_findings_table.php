<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_findings', function (Blueprint $table): void {
            // owner_type (255) + owner_id (bigint) + kind (32) + dedup_hash (40, fixed-width)
            // comfortably fits MySQL's 3072-byte max key length even at utf8mb4, unlike the
            // original six-column combination in the local_findings create migration, which
            // included the unbounded file_path and could not be made a real unique index.
            $table->char('dedup_hash', 40)->nullable(false)->change();
            $table->unique(['owner_type', 'owner_id', 'kind', 'dedup_hash'], 'local_findings_owner_kind_dedup_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::table('local_findings', function (Blueprint $table): void {
            $table->dropUnique('local_findings_owner_kind_dedup_hash_unique');
            $table->char('dedup_hash', 40)->nullable()->change();
        });
    }
};
