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

    /*
    |--------------------------------------------------------------------------
    | Committed JAR/WAR/EAR archives
    |--------------------------------------------------------------------------
    |
    | `trivy fs` reads only manifests and lock files, so a repository's
    | committed archives (a vendored application server, a deployed .ear) are
    | scanned by a second pair of `trivy rootfs` runs. Trivy identifies a JAR
    | by its SHA-1 through the Java DB, a ~1.5 GB OCI artifact it downloads on
    | first use and refreshes after 3 days, into the cache directory below
    | (docker-compose.yml points it at the collector_trivy_cache volume, so it
    | survives container recreates). An empty cache directory leaves Trivy's
    | own default (~/.cache/trivy). The Java DB repository overrides Trivy's
    | default registries (mirror.gcr.io, then ghcr.io), for a deployment whose
    | proxy blocks them; an empty value keeps Trivy's default.
    |
    */

    'trivy_cache_dir' => env('REPOSITORY_COLLECTION_TRIVY_CACHE_DIR', ''),

    'trivy_java_db_repository' => env('REPOSITORY_COLLECTION_TRIVY_JAVA_DB_REPOSITORY', ''),

    /*
    |--------------------------------------------------------------------------
    | Queue connection
    |--------------------------------------------------------------------------
    |
    | The connection CollectRepositoryJob batches are dispatched on, and the one
    | the collector container's worker consumes (docker/Dockerfile names it on
    | its `queue:work` command line). `redis-collection` exists because its
    | retry_after must exceed the job's two-hour timeout; see config/queue.php.
    | Tests set this to `sync`; anywhere else, changing it means changing the
    | collector's command too.
    |
    */

    'queue_connection' => env('REPOSITORY_COLLECTION_QUEUE_CONNECTION', 'redis-collection'),

];
