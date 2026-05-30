<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->string('source_id');
            $table->string('source_event_id');
            $table->foreignId('software_system_id')->constrained('software_systems')->cascadeOnDelete();
            $table->foreignId('container_id')->nullable()->constrained('security_containers')->nullOnDelete();
            $table->string('title');
            $table->longText('description')->nullable();
            $table->enum('severity', ['critical', 'high', 'medium', 'low', 'informational']);
            $table->enum('state', ['open', 'acknowledged', 'in_progress', 'resolved', 'dismissed']);
            $table->enum('type', ['vulnerability', 'secret', 'dependency', 'license', 'misconfiguration', 'code_quality', 'iac', 'posture']);
            $table->string('rule_id')->nullable();
            $table->string('fingerprint')->nullable();
            $table->string('url')->nullable();
            $table->longText('remediation')->nullable();
            $table->string('file_path')->nullable();
            $table->unsignedInteger('start_line')->nullable();
            $table->unsignedInteger('end_line')->nullable();
            $table->longText('snippet')->nullable();
            $table->string('commit_sha')->nullable();
            $table->string('branch')->nullable();
            $table->string('version_control_url')->nullable();
            $table->mediumText('source_data')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->boolean('is_dirty')->default(false);
            $table->enum('pending_state', ['open', 'acknowledged', 'in_progress', 'resolved', 'dismissed'])->nullable();
            $table->text('pending_comment')->nullable();

            $table->unique(['source_id', 'source_event_id']);
            $table->index(['software_system_id', 'state']);
            $table->index('severity');
            $table->index('fingerprint');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_events');
    }
};
