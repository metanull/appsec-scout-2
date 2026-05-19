<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('credentials', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('owner_user_id')->nullable()->comment('null = system credential');
            $table->string('integration_key');
            $table->text('value');
            $table->string('description')->nullable();
            $table->timestamp('last_tested_at')->nullable();
            $table->boolean('last_tested_ok')->nullable();
            $table->text('last_tested_error')->nullable();
            $table->timestamps();

            $table->unique(['owner_user_id', 'integration_key']);
            $table->foreign('owner_user_id')->references('id')->on('users')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credentials');
    }
};
