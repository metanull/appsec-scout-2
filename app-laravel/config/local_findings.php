<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Closed Local Finding Compaction
    |--------------------------------------------------------------------------
    |
    | Number of days after its last report that a Dismissed or Resolved local
    | finding is compacted by the daily `local-findings:prune` run: its scanner
    | metadata, description and all but its latest comment are dropped. The row
    | itself, with its status, is kept, so a later scan that reports the finding
    | again keeps the decision instead of opening it again.
    |
    */
    'compact_after_days' => (int) env('LOCAL_FINDING_COMPACT_AFTER_DAYS', 90),
];
