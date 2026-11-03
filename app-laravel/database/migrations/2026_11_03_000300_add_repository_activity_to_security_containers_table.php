<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Repository activity reported by a Source Control provider's inventory sync: whether the
 * repository is disabled upstream, and the newest commit on its default branch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('security_containers', function (Blueprint $table): void {
            $table->boolean('is_disabled')->default(false)->after('url');
            $table->string('last_commit_sha', 64)->nullable()->after('is_disabled');
            $table->timestamp('last_commit_at')->nullable()->after('last_commit_sha');
            $table->string('last_commit_author')->nullable()->after('last_commit_at');
        });
    }

    public function down(): void
    {
        Schema::table('security_containers', function (Blueprint $table): void {
            $table->dropColumn(['is_disabled', 'last_commit_sha', 'last_commit_at', 'last_commit_author']);
        });
    }
};
