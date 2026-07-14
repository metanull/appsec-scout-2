<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('local_finding_work_item_links', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('local_finding_id')->constrained('local_findings')->cascadeOnDelete();
            $table->string('tracker_id');
            $table->string('work_item_id');
            $table->text('work_item_url')->nullable();
            $table->string('work_item_title')->nullable();
            $table->string('work_item_state')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('synced_at')->nullable();

            $table->unique(['local_finding_id', 'tracker_id', 'work_item_id'], 'local_finding_work_item_links_unique');
            $table->index(['tracker_id', 'work_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('local_finding_work_item_links');
    }
};
