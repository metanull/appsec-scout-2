<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A container keeps the system its source reported (source_software_system_id) apart from the
 * system it is linked to (software_system_id), so an operator can re-link it and later fetches
 * still find it. Every sync lookup moves to the (source_software_system_id, source_container_id)
 * natural key; system_overridden_at marks a manual link that sync must leave alone.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('security_containers', function (Blueprint $table): void {
            $table->foreignId('source_software_system_id')->nullable()->after('software_system_id')
                ->constrained('software_systems')->nullOnDelete();
            $table->timestamp('system_overridden_at')->nullable()->after('source_software_system_id');
        });

        DB::table('security_containers')->update(['source_software_system_id' => DB::raw('software_system_id')]);

        Schema::table('security_containers', function (Blueprint $table): void {
            // MySQL backs the software_system_id foreign key with the leading column of the unique
            // index below and refuses to drop an index a foreign key relies on: give the key its
            // own index first.
            $table->index('software_system_id', 'sec_cont_sys_idx');
            $table->dropUnique('sec_cont_sys_src_uidx');
            $table->unique(['source_software_system_id', 'source_container_id'], 'sec_cont_srcsys_src_uidx');
        });
    }

    public function down(): void
    {
        // Foreign key first: MySQL dropped the implicit index it created for this key once the
        // unique index below became usable for it, and refuses to drop that unique index while
        // the key still relies on it.
        Schema::table('security_containers', function (Blueprint $table): void {
            $table->dropForeign(['source_software_system_id']);
        });

        Schema::table('security_containers', function (Blueprint $table): void {
            $table->dropUnique('sec_cont_srcsys_src_uidx');
            $table->unique(['software_system_id', 'source_container_id'], 'sec_cont_sys_src_uidx');
            $table->dropIndex('sec_cont_sys_idx');
        });

        Schema::table('security_containers', function (Blueprint $table): void {
            $table->dropColumn(['source_software_system_id', 'system_overridden_at']);
        });
    }
};
