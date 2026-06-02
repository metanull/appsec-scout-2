<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('curated_links', function (Blueprint $table): void {
            $table->id();
            $table->morphs('owner');
            $table->string('label');
            $table->string('url', 2048);
            $table->string('kind');
            $table->foreignId('created_by_user_id')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();
            $table->timestamps();

            $table->index(['kind']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('curated_links');
    }
};
