<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repository collection and static analysis runs can cover a single software system as well as
 * every repository; the scope is recorded on the run (null = every repository). Both run tables
 * share one Filament resource, so they gain the column together.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repository_collection_runs', function (Blueprint $table): void {
            $table->foreignId('software_system_id')->nullable()->after('source_control_id')
                ->constrained('software_systems')->nullOnDelete();
        });

        Schema::table('static_analysis_runs', function (Blueprint $table): void {
            $table->foreignId('software_system_id')->nullable()->after('source_control_id')
                ->constrained('software_systems')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('repository_collection_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('software_system_id');
        });

        Schema::table('static_analysis_runs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('software_system_id');
        });
    }
};
