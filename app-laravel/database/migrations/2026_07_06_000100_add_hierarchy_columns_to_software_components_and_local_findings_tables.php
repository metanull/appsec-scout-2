<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('software_components', function (Blueprint $table): void {
            $table->foreignId('software_system_id')->nullable()->after('owner_id')->constrained('software_systems')->nullOnDelete();
            $table->foreignId('software_asset_id')->nullable()->after('owner_id')->constrained('software_assets')->nullOnDelete();
        });

        Schema::table('local_findings', function (Blueprint $table): void {
            $table->foreignId('software_system_id')->nullable()->after('owner_id')->constrained('software_systems')->nullOnDelete();
            $table->foreignId('software_asset_id')->nullable()->after('owner_id')->constrained('software_assets')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('software_components', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('software_system_id');
            $table->dropConstrainedForeignId('software_asset_id');
        });

        Schema::table('local_findings', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('software_system_id');
            $table->dropConstrainedForeignId('software_asset_id');
        });
    }
};
