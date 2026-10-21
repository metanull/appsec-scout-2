<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Remembers that a re-scan, not an operator, resolved a local finding — and the status it had
 * then — so the finding can be reopened with that status if a later scan reports it again.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_findings', function (Blueprint $table): void {
            $table->timestamp('resolved_by_scan_at')->nullable()->after('status');
            $table->string('status_before_scan_resolution', 32)->nullable()->after('resolved_by_scan_at');
        });
    }

    public function down(): void
    {
        Schema::table('local_findings', function (Blueprint $table): void {
            $table->dropColumn(['resolved_by_scan_at', 'status_before_scan_resolution']);
        });
    }
};
