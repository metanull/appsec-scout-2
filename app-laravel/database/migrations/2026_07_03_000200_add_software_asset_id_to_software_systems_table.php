<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('software_systems', function (Blueprint $table): void {
            $table->foreignId('software_asset_id')
                ->nullable()
                ->after('id')
                ->constrained('software_assets')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('software_systems', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('software_asset_id');
        });
    }
};
