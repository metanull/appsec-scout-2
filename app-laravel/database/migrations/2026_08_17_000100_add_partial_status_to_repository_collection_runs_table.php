<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * PostgreSQL implements enum() columns as varchar + CHECK constraint, and
 * Laravel's enum()->change() inlines that CHECK clause into ALTER COLUMN TYPE,
 * which PostgreSQL rejects as a syntax error. Replace the auto-named check
 * constraint (<table>_<column>_check) directly on that driver instead; MySQL
 * and SQLite keep the portable schema-builder path.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('alter table repository_collection_runs drop constraint repository_collection_runs_status_check');
            DB::statement("alter table repository_collection_runs add constraint repository_collection_runs_status_check check (\"status\" in ('running', 'success', 'partial', 'failure'))");

            return;
        }

        Schema::table('repository_collection_runs', function (Blueprint $table) {
            $table->enum('status', ['running', 'success', 'partial', 'failure'])->change();
        });
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            DB::statement('alter table repository_collection_runs drop constraint repository_collection_runs_status_check');
            DB::statement("alter table repository_collection_runs add constraint repository_collection_runs_status_check check (\"status\" in ('running', 'success', 'failure'))");

            return;
        }

        Schema::table('repository_collection_runs', function (Blueprint $table) {
            $table->enum('status', ['running', 'success', 'failure'])->change();
        });
    }
};
