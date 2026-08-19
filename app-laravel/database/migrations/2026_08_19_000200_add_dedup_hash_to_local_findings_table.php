<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_findings', function (Blueprint $table): void {
            $table->char('dedup_hash', 40)->nullable()->after('start_line');
        });
    }

    public function down(): void
    {
        Schema::table('local_findings', function (Blueprint $table): void {
            $table->dropColumn('dedup_hash');
        });
    }
};
