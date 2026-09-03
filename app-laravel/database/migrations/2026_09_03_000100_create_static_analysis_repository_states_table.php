<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('static_analysis_repository_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('security_container_id')
                ->unique()
                ->constrained()
                ->cascadeOnDelete();
            $table->string('commit_sha', 64);
            $table->timestamp('analyzed_at');
            // Deliberately no foreign key to static_analysis_runs: run rows are
            // operational history that may be pruned, and the scan state must
            // outlive them.
            $table->unsignedBigInteger('analyzed_run_id')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('static_analysis_repository_states');
    }
};
