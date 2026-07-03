<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('software_components', function (Blueprint $table): void {
            $table->id();
            $table->morphs('owner');
            $table->foreignId('attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->string('name');
            $table->string('version')->nullable();
            $table->string('ecosystem')->nullable();
            $table->string('purl');
            $table->string('license')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['owner_type', 'owner_id', 'purl'], 'software_components_owner_purl_unique');
            $table->index(['name', 'version']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('software_components');
    }
};
