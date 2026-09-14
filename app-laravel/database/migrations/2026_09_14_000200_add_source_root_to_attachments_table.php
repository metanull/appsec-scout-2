<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Records the absolute directory a scanned tree was cloned into, so the SARIF
 * parser can relativise the absolute artifact URIs Roslynator/SpotBugs/Opengrep
 * emit against it. Null for attachments that never had a clone root (SBOMs,
 * manual uploads, Trivy reports that are already repository-relative).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attachments', function (Blueprint $table): void {
            $table->string('source_root', 1024)->nullable()->after('created_by_command');
        });
    }

    public function down(): void
    {
        Schema::table('attachments', function (Blueprint $table): void {
            $table->dropColumn('source_root');
        });
    }
};
