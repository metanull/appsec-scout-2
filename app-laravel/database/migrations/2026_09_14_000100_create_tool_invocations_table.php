<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tool_invocations', function (Blueprint $table): void {
            $table->id();

            // Polymorphic parent run: RepositoryCollectionRun or StaticAnalysisRun.
            // No foreign-key constraint (as StaticAnalysisRepositoryState's own
            // analyzed_run_id column deliberately omits one) — run rows are
            // operational history that may be pruned independently.
            $table->string('run_type');
            $table->unsignedBigInteger('run_id');

            $table->foreignId('security_container_id')->nullable()->constrained()->nullOnDelete();

            $table->string('tool', 64);
            $table->string('tool_version', 64)->nullable();
            $table->json('command_json')->nullable();
            $table->enum('outcome', ['ran_with_findings', 'ran_clean', 'skipped_no_toolchain', 'skipped_unchanged', 'failed']);
            $table->integer('exit_code')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->unsignedInteger('duration_seconds')->nullable();
            $table->text('output_tail')->nullable();

            $table->index(['run_type', 'run_id']);
            $table->index(['security_container_id', 'tool']);
            $table->index(['tool', 'outcome']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tool_invocations');
    }
};
