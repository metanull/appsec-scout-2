<?php

use App\Models\Enums\InferenceSuggestionStatus;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('inference_suggestions', function (Blueprint $table): void {
            $table->id();
            $table->string('suggestion_type');
            $table->string('subject_type');
            $table->unsignedBigInteger('subject_id');
            $table->string('target_type')->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->string('proposed_action');
            $table->decimal('confidence', 5, 4);
            $table->json('evidence')->nullable();
            $table->string('evidence_fingerprint', 255);
            $table->enum('status', InferenceSuggestionStatus::values())->default(InferenceSuggestionStatus::Pending->value);
            $table->foreignId('reviewed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->text('review_note')->nullable();
            $table->timestamps();

            $table->index(['status', 'suggestion_type']);
            $table->index(['subject_type', 'subject_id']);
            $table->index('evidence_fingerprint');
            $table->unique(
                ['suggestion_type', 'evidence_fingerprint', 'status'],
                'inference_suggestions_type_fingerprint_status_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inference_suggestions');
    }
};
