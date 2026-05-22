<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('security_events', function (Blueprint $table) {
            $table->text('url')->nullable()->change();
            $table->text('file_path')->nullable()->change();
            $table->text('version_control_url')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('security_events', function (Blueprint $table) {
            $table->string('url', 2048)->nullable()->change();
            $table->string('file_path', 2048)->nullable()->change();
            $table->string('version_control_url', 2048)->nullable()->change();
        });
    }
};
