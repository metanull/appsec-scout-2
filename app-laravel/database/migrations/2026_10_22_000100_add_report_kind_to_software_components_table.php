<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records which SBOM report (attachment kind) a software component comes from, so a re-scan only
 * marks the components of its own report as removed: a repository's `trivy fs` SBOM and its
 * `trivy rootfs` SBOM of committed JAR/WAR/EAR archives both store components on the same
 * container. Every component so far came from a `sbom` attachment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('software_components', function (Blueprint $table): void {
            $table->string('report_kind', 64)->nullable()->after('attachment_id');
        });

        DB::table('software_components')->update(['report_kind' => 'sbom']);
    }

    public function down(): void
    {
        Schema::table('software_components', function (Blueprint $table): void {
            $table->dropColumn('report_kind');
        });
    }
};
