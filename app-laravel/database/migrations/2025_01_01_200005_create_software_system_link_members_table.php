<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('software_system_link_members', function (Blueprint $table) {
            $table->foreignId('link_id')->constrained('software_system_links')->cascadeOnDelete();
            $table->foreignId('software_system_id')->constrained('software_systems')->cascadeOnDelete();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->primary(['link_id', 'software_system_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('software_system_link_members');
    }
};
