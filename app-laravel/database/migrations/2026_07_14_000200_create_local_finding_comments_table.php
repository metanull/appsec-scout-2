<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_finding_comments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('local_finding_id')->constrained('local_findings')->cascadeOnDelete();
            $table->text('body');
            $table->foreignId('author_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();

            $table->index(['local_finding_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_finding_comments');
    }
};
