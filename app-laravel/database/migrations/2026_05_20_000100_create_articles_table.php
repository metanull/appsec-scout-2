<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('articles', function (Blueprint $table) {
            $table->id();
            $table->string('issue_type_id');
            $table->string('language')->default('en');
            $table->string('api_vuln_name')->nullable();
            $table->timestamp('fetched_at');
            $table->longText('markdown');

            $table->unique(['issue_type_id', 'language', 'api_vuln_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('articles');
    }
};
