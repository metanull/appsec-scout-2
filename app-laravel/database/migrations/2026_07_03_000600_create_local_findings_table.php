<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_findings', function (Blueprint $table): void {
            $table->id();
            $table->morphs('owner');
            $table->foreignId('attachment_id')->nullable()->constrained('attachments')->nullOnDelete();
            $table->string('kind', 32);
            $table->string('rule_id');
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('severity', 32)->nullable();
            $table->string('file_path');
            $table->unsignedInteger('start_line')->nullable();
            $table->unsignedInteger('end_line')->nullable();
            $table->string('package_name')->nullable();
            $table->string('package_version')->nullable();
            $table->json('metadata')->nullable();
            $table->foreignId('correlated_security_event_id')->nullable()->constrained('security_events')->nullOnDelete();
            $table->string('correlation_method', 32)->nullable();
            $table->timestamp('first_seen_at')->nullable();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();

            // Not a unique constraint: MySQL's 3072-byte max key length can't fit
            // owner_type+owner_id+kind+rule_id+file_path+start_line at full column
            // width. Idempotency on re-scan is enforced at the application level
            // (AttachmentIngestionService does a firstOrNew on these same columns).
            $table->index(['owner_type', 'owner_id', 'kind', 'rule_id'], 'local_findings_owner_finding_index');
            $table->index(['kind', 'severity']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_findings');
    }
};
