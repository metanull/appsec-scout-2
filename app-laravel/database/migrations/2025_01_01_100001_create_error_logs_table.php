<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('error_logs', function (Blueprint $table) {
            $table->id();
            $table->string('level', 50);
            $table->string('channel', 50)->nullable();
            $table->text('message');
            $table->json('context_json')->nullable();
            $table->longText('trace')->nullable();
            $table->timestamp('occurred_at')->useCurrent();

            $table->index(['level', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('error_logs');
    }
};
