<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_disabled')->default(false)->after('password');
            $table->timestamp('last_login_at')->nullable()->after('remember_token');
            $table->timestamp('disabled_at')->nullable()->after('last_login_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['is_disabled', 'last_login_at', 'disabled_at']);
        });
    }
};
