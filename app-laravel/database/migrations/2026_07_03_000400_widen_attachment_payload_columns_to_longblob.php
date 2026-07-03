<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's Schema::binary() always maps to MySQL's BLOB type (64 KiB max),
 * regardless of the actual payload size. Real attachments — CycloneDX SBOMs
 * in particular — routinely exceed that, and MySQL truncates/rejects the
 * insert rather than silently succeeding. SQLite's BLOB has no such limit,
 * which is why this never surfaced in the (SQLite-backed) test suite.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE attachments MODIFY payload LONGBLOB NOT NULL');
        DB::statement('ALTER TABLE event_attachments MODIFY payload LONGBLOB NOT NULL');
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'mysql') {
            return;
        }

        DB::statement('ALTER TABLE attachments MODIFY payload BLOB NOT NULL');
        DB::statement('ALTER TABLE event_attachments MODIFY payload BLOB NOT NULL');
    }
};
