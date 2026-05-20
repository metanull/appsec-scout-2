<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_view_state', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('view_id', 120);
            $table->json('payload_json');
            $table->timestamps();

            $table->unique(['user_id', 'view_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_view_state');
    }
};
