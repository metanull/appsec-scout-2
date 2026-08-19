<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('error_logs', function (Blueprint $table): void {
            $table->foreignId('software_system_id')->nullable()->after('channel')->constrained('software_systems')->nullOnDelete();
            $table->foreignId('security_container_id')->nullable()->after('software_system_id')->constrained('security_containers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('error_logs', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('security_container_id');
            $table->dropConstrainedForeignId('software_system_id');
        });
    }
};
