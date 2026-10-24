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
