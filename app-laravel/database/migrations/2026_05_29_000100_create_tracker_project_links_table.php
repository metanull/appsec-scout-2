<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tracker_project_links', function (Blueprint $table): void {
            $table->id();
            $table->morphs('owner');
            $table->string('tracker_id');
            $table->string('project_key');
            $table->string('project_name')->nullable();
            $table->boolean('is_default')->default(false);
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'tracker_id', 'project_key'], 'tpl_owner_tracker_project_unique');
            $table->index(['tracker_id', 'project_key']);
        });

        Schema::create('tracker_config', function (Blueprint $table): void {
            $table->id();
            $table->string('key')->unique();
            $table->text('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tracker_config');
        Schema::dropIfExists('tracker_project_links');
    }
};
