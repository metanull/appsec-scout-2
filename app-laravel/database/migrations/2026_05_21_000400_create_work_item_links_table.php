<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('work_item_links', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained('security_events')->cascadeOnDelete();
            $table->string('tracker_id');
            $table->string('work_item_id');
            $table->text('work_item_url')->nullable();
            $table->string('work_item_title')->nullable();
            $table->string('work_item_state')->nullable();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('synced_at')->nullable();

            $table->unique(['event_id', 'tracker_id', 'work_item_id']);
            $table->index(['tracker_id', 'work_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('work_item_links');
    }
};
