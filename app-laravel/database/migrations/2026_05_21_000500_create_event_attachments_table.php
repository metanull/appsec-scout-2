<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            throw new RuntimeException('The event_attachments payload column requires MySQL LONGBLOB support.');
        }

        Schema::create('event_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('security_events')->cascadeOnDelete();
            $table->string('kind', 64);
            $table->string('mime', 255);
            $table->string('name', 255);
            $table->binary('payload');
            $table->unsignedInteger('size_bytes');
            $table->timestamp('created_at')->useCurrent();
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('created_by_command', 64)->nullable();

            $table->index(['event_id', 'kind']);
        });

        DB::statement('ALTER TABLE event_attachments MODIFY payload LONGBLOB NOT NULL');
    }

    public function down(): void
    {
        Schema::dropIfExists('event_attachments');
    }
};
