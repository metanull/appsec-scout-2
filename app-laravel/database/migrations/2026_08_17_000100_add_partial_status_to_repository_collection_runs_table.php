<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('repository_collection_runs', function (Blueprint $table) {
            $table->enum('status', ['running', 'success', 'partial', 'failure'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('repository_collection_runs', function (Blueprint $table) {
            $table->enum('status', ['running', 'success', 'failure'])->change();
        });
    }
};
