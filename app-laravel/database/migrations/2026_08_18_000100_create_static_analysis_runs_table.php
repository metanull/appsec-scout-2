<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('static_analysis_runs', function (Blueprint $table) {
            $table->id();
            $table->string('source_control_id');
            $table->string('batch_id')->nullable();
            $table->timestamp('started_at');
            $table->timestamp('finished_at')->nullable();
            $table->enum('status', ['running', 'success', 'partial', 'failure']);
            $table->json('counts_json')->nullable();
            $table->text('error_message')->nullable();

            $table->index(['source_control_id', 'status']);
            $table->index(['source_control_id', 'finished_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('static_analysis_runs');
    }
};
