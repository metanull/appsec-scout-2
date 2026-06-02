<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('repository_providers', function (Blueprint $table): void {
            $table->id();
            $table->string('provider_type', 32);
            $table->string('name');
            $table->string('base_url');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index('provider_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('repository_providers');
    }
};
