<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repository_mappings', function (Blueprint $table): void {
            $table->id();
            $table->morphs('owner');
            $table->foreignId('repository_provider_id')
                ->constrained('repository_providers')
                ->cascadeOnDelete();
            $table->string('repository_name');
            $table->text('repository_url');
            $table->string('default_branch')->nullable()->default('main');
            $table->string('path_prefix')->nullable();
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(
                ['owner_type', 'owner_id', 'repository_provider_id', 'repository_name'],
                'repository_mappings_owner_provider_repository_unique',
            );
            $table->index(['repository_provider_id', 'repository_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repository_mappings');
    }
};
