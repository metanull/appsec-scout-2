<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('local_findings', function (Blueprint $table): void {
            $table->string('status', 32)->default('open')->after('correlation_method');
            $table->string('overridden_severity', 32)->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('local_findings', function (Blueprint $table): void {
            $table->dropColumn(['status', 'overridden_severity']);
        });
    }
};
