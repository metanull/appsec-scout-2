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

    /*
    |--------------------------------------------------------------------------
    | Maven Central mirror
    |--------------------------------------------------------------------------
    |
    | Trivy resolves every pom.xml's parent and BOM POMs over HTTP. Maven
    | Central answers a collection sweep's burst of those requests with 429
    | Too Many Requests and then blocks Trivy's user agent from this IP for
    | 30 minutes, which Trivy treats as fatal — every Java repository after
    | the first then fails. CollectRepositoryJob therefore hands Trivy a
    | per-job Maven settings.xml mirroring `central` to this URL (Google's
    | official Maven Central mirror by default; point it at a corporate
    | repository manager if one exists). Repositories a pom.xml declares
    | itself are untouched. An empty value sends Trivy to Maven Central
    | directly.
    |
    */

    'maven_mirror_url' => env('REPOSITORY_COLLECTION_MAVEN_MIRROR_URL', 'https://maven-central.storage-download.googleapis.com/maven2/'),

];
