<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_containers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('software_system_id')->constrained('software_systems')->cascadeOnDelete();
            $table->string('source_container_id');
            $table->string('name');
            $table->string('kind')->nullable();
            $table->string('url')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();

            $table->unique(['software_system_id', 'source_container_id'], 'sec_cont_sys_src_uidx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_containers');
    }
};
