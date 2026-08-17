<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Workspace path
    |--------------------------------------------------------------------------
    |
    | Root directory AnalyzeRepositoryJob clones each repository into, scoped
    | per-job under a UUID subdirectory and deleted once the job finishes. In
    | the static-analysis-collector container this is the dedicated
    | static_analysis_collector_workspace volume (docker-compose.yml sets
    | STATIC_ANALYSIS_COLLECTION_WORKSPACE=/workspace-scratch); elsewhere
    | (e.g. running tests inside the app container) it falls back to a
    | private storage path that always exists. Deliberately separate from
    | repository_collection.workspace_path — the two collector containers
    | never share a scratch root, even though neither ever collides today.
    |
    */

    'workspace_path' => env('STATIC_ANALYSIS_COLLECTION_WORKSPACE', storage_path('app/private/static-analysis-collection')),

];
