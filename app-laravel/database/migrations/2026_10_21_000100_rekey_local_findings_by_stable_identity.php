<?php

use App\Assets\LocalFindingIdentityRekeyer;
use Illuminate\Database\Migrations\Migration;

/**
 * Moves every stored local finding to the line-independent identity (see
 * LocalFinding::identityHash()) before the next scan import, so a re-scan matches the rows
 * operators already triaged instead of re-creating them and resolving the originals.
 */
return new class extends Migration
{
    public function up(): void
    {
        app(LocalFindingIdentityRekeyer::class)->rekey();
    }

    public function down(): void
    {
        // Irreversible: merged rows are gone, and the old line-based hashes only mattered for
        // matching scans, which now use the new identity.
    }
};
