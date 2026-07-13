<?php

return [
    // Root directory `PendingStaticAnalysisScanImporter` reads generated Roslynator/SpotBugs
    // SARIF reports from. Mounted read-only into the app container by docker-compose.yml
    // (STATIC_ANALYSIS_OUTPUT_DIR -> /var/www/html/static-analysis-import); overridable so
    // tests never touch the real mount.
    'import_path' => env('STATIC_ANALYSIS_IMPORT_PATH', base_path('static-analysis-import')),

    // Where PendingStaticAnalysisScanImporter tracks how many lines of each run's run.jsonl
    // it has already imported, so re-running (scheduled every minute, or triggered once more
    // by invoke-ops.ps1 right after a scan finishes) never imports the same report twice.
    'cursor_path' => env('STATIC_ANALYSIS_CURSOR_PATH', storage_path('app/private/static-analysis-scan-cursors')),
];
