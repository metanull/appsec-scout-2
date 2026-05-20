<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Audit Log Retention
    |--------------------------------------------------------------------------
    |
    | Number of days to retain audit log entries. The scheduled PruneAuditLogs
    | job deletes entries older than this threshold daily.
    |
    */
    'retain_days' => (int) env('AUDIT_RETAIN_DAYS', 365),
];
