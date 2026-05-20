<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('software_systems', function (Blueprint $table) {
            $table->id();
            $table->string('source_id');
            $table->string('source_system_id');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['source_id', 'source_system_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('software_systems');
    }
};
