<?php

return [
    /*
    |--------------------------------------------------------------------------
    | List-page Export Retention
    |--------------------------------------------------------------------------
    |
    | Number of days a finished list-page export (its `exports` row and its
    | CSV/XLSX files) is kept for download. The scheduled PruneExports job
    | deletes older exports daily.
    |
    */
    'retain_days' => (int) env('EXPORTS_RETAIN_DAYS', 7),
];
