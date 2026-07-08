<?php

return [
    // Root directory `PendingSbomScanImporter` reads generated SBOM/vulnerability/secret
    // scan reports from. Mounted read-only into the app container by docker-compose.yml
    // (SBOM_OUTPUT_DIR -> /var/www/html/sbom-import); overridable so tests never touch
    // the real mount.
    'import_path' => env('SBOM_IMPORT_PATH', base_path('sbom-import')),

    // Where PendingSbomScanImporter tracks how many lines of each run's run.jsonl it has
    // already imported, so re-running (scheduled every minute, or triggered once more by
    // invoke-ops.ps1 right after a scan finishes) never imports the same report twice.
    'cursor_path' => env('SBOM_CURSOR_PATH', storage_path('app/private/sbom-scan-cursors')),
];
