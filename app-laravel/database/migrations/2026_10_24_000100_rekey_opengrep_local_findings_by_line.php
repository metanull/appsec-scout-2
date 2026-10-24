<?php

use App\Assets\LocalFindingIdentityRekeyer;
use Illuminate\Database\Migrations\Migration;

/**
 * Moves stored Opengrep findings off their matchBasedId fingerprint, which Opengrep hashed from
 * each run's absolute scratch path, onto rule + file + line (see LocalFinding::identityHash()),
 * and folds the copy every static analysis run re-created into the row carrying the operator's
 * decision, before the next scan import.
 */
return new class extends Migration
{
    /**
     * Each merge commits on its own. Inside the one transaction PostgreSQL migrations run in
     * by default, every merge's savepoint holds a lock until the end, and ~14k merges run out
     * of shared memory (max_locks_per_transaction). The rekeyer is idempotent, so a run that
     * stops partway finishes on the next migrate.
     */
    public $withinTransaction = false;

    public function up(): void
    {
        app(LocalFindingIdentityRekeyer::class)->rekey();
    }

    public function down(): void
    {
        // Irreversible: merged rows are gone, and the fingerprint-based hashes never matched a
        // later scan anyway.
    }
};
