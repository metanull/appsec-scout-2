<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Workspace path
    |--------------------------------------------------------------------------
    |
    | Root directory CollectRepositoryJob clones each repository into, scoped
    | per-job under a UUID subdirectory and deleted once the job finishes. In
    | the collector container this is the dedicated collector_workspace volume
    | (docker-compose.yml sets REPOSITORY_COLLECTION_WORKSPACE=/workspace-scratch);
    | elsewhere (e.g. running tests inside the app container) it falls back to a
    | private storage path that always exists.
    |
    */

    'workspace_path' => env('REPOSITORY_COLLECTION_WORKSPACE', storage_path('app/private/repository-collection')),

    /*
    |--------------------------------------------------------------------------
    | Trivy
    |--------------------------------------------------------------------------
    |
    | The shared trivy-server container (docker-compose.yml) and the token file
    | trivy-token-init provisions into the trivy_token volume — the same server
    | and token docker/ops/collect-sboms.sh already scans against.
    |
    */

    'trivy_server_url' => env('TRIVY_SERVER_URL'),

    'trivy_token_file' => env('TRIVY_TOKEN_FILE', '/var/lib/trivy-token/token'),

    'trivy_timeout' => env('REPOSITORY_COLLECTION_TRIVY_TIMEOUT', '15m'),

];
