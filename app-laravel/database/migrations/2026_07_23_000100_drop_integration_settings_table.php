<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('integration_settings');
    }

    public function down(): void
    {
        Schema::create('integration_settings', function (Blueprint $table) {
            $table->id();
            $table->string('integration_kind');
            $table->string('integration_id');
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('fetch_interval_minutes')->default(30);
            $table->timestamp('last_synced_at')->nullable();
            $table->string('last_sync_status')->nullable();
            $table->text('last_sync_message')->nullable();
            $table->timestamps();

            $table->unique(['integration_kind', 'integration_id']);
            $table->index(['integration_kind', 'enabled']);
        });
    }
};
