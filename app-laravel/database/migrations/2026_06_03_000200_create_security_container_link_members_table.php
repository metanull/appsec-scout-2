<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_container_link_members', function (Blueprint $table): void {
            $table->foreignId('link_id')->constrained('security_container_links')->cascadeOnDelete();
            $table->foreignId('security_container_id')->constrained('security_containers')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->primary(['link_id', 'security_container_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_container_link_members');
    }
};
