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

    /*
    |--------------------------------------------------------------------------
    | Build/analysis timeouts
    |--------------------------------------------------------------------------
    |
    | Same defaults docker/ops/collect-static-analysis.sh already uses
    | (DOTNET_RESTORE_TIMEOUT/DOTNET_BUILD_TIMEOUT/JAVA_BUILD_TIMEOUT/
    | ANALYSIS_TIMEOUT), reused here so both paths behave identically unless
    | explicitly overridden.
    |
    */

    'dotnet_restore_timeout' => (int) env('STATIC_ANALYSIS_DOTNET_RESTORE_TIMEOUT', 600),

    'dotnet_build_timeout' => (int) env('STATIC_ANALYSIS_DOTNET_BUILD_TIMEOUT', 900),

    'java_build_timeout' => (int) env('STATIC_ANALYSIS_JAVA_BUILD_TIMEOUT', 900),

    'analysis_timeout' => (int) env('STATIC_ANALYSIS_ANALYSIS_TIMEOUT', 900),

    /*
    |--------------------------------------------------------------------------
    | Find Security Bugs plugin
    |--------------------------------------------------------------------------
    |
    | Directory AnalyzeRepositoryJob scans for a findsecbugs-plugin-*.jar file
    | (version-agnostic, matching collect-static-analysis.sh's own `find`
    | lookup) — baked into docker/static-analysis-collector/Dockerfile.
    |
    */

    'findsecbugs_plugin_dir' => env('STATIC_ANALYSIS_FINDSECBUGS_PLUGIN_DIR', '/opt/spotbugs-plugins'),

    /*
    |--------------------------------------------------------------------------
    | Opengrep rules
    |--------------------------------------------------------------------------
    |
    | Directory AnalyzeRepositoryJob passes to `opengrep scan -f` — vendored,
    | version-pinned rules (csharp/java/javascript/typescript) baked into
    | docker/static-analysis-collector/Dockerfile.
    |
    */

    'opengrep_rules_dir' => env('STATIC_ANALYSIS_OPENGREP_RULES_DIR', '/opt/opengrep-rules'),

    'opengrep_timeout' => (int) env('STATIC_ANALYSIS_OPENGREP_TIMEOUT', 900),

];
