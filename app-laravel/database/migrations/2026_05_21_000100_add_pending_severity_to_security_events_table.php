<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('security_events', function (Blueprint $table) {
            $table->enum('pending_severity', ['critical', 'high', 'medium', 'low', 'informational'])
                ->nullable()
                ->after('pending_state');
        });
    }

    public function down(): void
    {
        Schema::table('security_events', function (Blueprint $table) {
            $table->dropColumn('pending_severity');
        });
    }
};
