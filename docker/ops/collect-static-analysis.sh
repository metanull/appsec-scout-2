#!/bin/bash
# Runs static analysis (Roslynator for .NET, SpotBugs + Find Security Bugs for Java) for
# every non-disabled Git repository across every project in an Azure DevOps organization,
# emitting SARIF reports.
#
# For .NET repos, each *.sln found is restored and built first (Roslynator needs resolved
# package references) and analyzed individually; results are merged into one SARIF file per
# repo. For Java repos, the topmost pom.xml/build.gradle[.kts] is built with the repo's own
# wrapper if present (mvnw/gradlew), else the system Maven/Gradle install; every directory
# that ends up containing compiled .class files is then analyzed together in one SpotBugs
# run. Build failures are non-fatal — the corresponding report is simply not generated for
# that repo, same as collect-sboms.sh already treats dotnet restore/build failures.
#
# Required environment: AZDO_ORG, AZDO_PAT
# Optional environment: AZDO_PROJECT_FILTER, AZDO_REPO_FILTER (regex), OUTPUT_DIR,
#   STATIC_ANALYSIS_TYPES (comma-separated subset of dotnet,java; default: both),
#   DOTNET_RESTORE_TIMEOUT / DOTNET_BUILD_TIMEOUT (seconds, default 600/900),
#   JAVA_BUILD_TIMEOUT (seconds, default 900), ANALYSIS_TIMEOUT (per Roslynator/SpotBugs
#   invocation, default 900), RESUME_FROM (comma-separated list of previous run directory
#   names, relative to OUTPUT_DIR — see collect-sboms.sh for the exact semantics; set via
#   invoke-ops.ps1 -StaticAnalysis -Resume), AZDO_SKIP_IMPORT (drops a .skip-import marker,
#   same as the SBOM scan — set via invoke-ops.ps1 -StaticAnalysis -SkipUpload)
set -uo pipefail

: "${AZDO_ORG:?AZDO_ORG must be set}"
: "${AZDO_PAT:?AZDO_PAT must be set}"

API_VERSION="7.1"
OUTPUT_DIR="${OUTPUT_DIR:-/output-static-analysis}"
PROJECT_FILTER="${AZDO_PROJECT_FILTER:-}"
REPO_FILTER="${AZDO_REPO_FILTER:-}"
DOTNET_RESTORE_TIMEOUT="${DOTNET_RESTORE_TIMEOUT:-600}"
DOTNET_BUILD_TIMEOUT="${DOTNET_BUILD_TIMEOUT:-900}"
JAVA_BUILD_TIMEOUT="${JAVA_BUILD_TIMEOUT:-900}"
ANALYSIS_TIMEOUT="${ANALYSIS_TIMEOUT:-900}"
SCAN_TYPES=" ${STATIC_ANALYSIS_TYPES:-dotnet,java} "
SCAN_TYPES="${SCAN_TYPES//,/ }"
FINDSECBUGS_JAR=$(find /opt/spotbugs-plugins -maxdepth 1 -iname 'findsecbugs-plugin-*.jar' 2>/dev/null | head -1)

scan_enabled() {
    [[ "$SCAN_TYPES" == *" $1 "* ]]
}

NETRC_FILE="$HOME/.netrc"
printf 'machine dev.azure.com\n  login azdo\n  password %s\n' "$AZDO_PAT" > "$NETRC_FILE"
chmod 600 "$NETRC_FILE"

# PAT never appears in argv or shell history — git reads it from the credential store.
git config --global credential.helper store
printf 'https://:%s@dev.azure.com\n' "$AZDO_PAT" > "$HOME/.git-credentials"
chmod 600 "$HOME/.git-credentials"

RUN_TS=$(date -u +%Y%m%dT%H%M%SZ)
RUN_DIR="$OUTPUT_DIR/$RUN_TS"
mkdir -p "$RUN_DIR"
RESULTS_FILE="$RUN_DIR/run.jsonl"
: > "$RESULTS_FILE"
ERROR_FLAG="$RUN_DIR/.azdo_api_error"

# invoke-ops.ps1 -SkipUpload sets this so a dry-run scan never reaches appsec-scout at
# all — including via the scheduled tick, which otherwise runs every minute independently
# of this script. PendingStaticAnalysisScanImporter skips any run directory with this marker.
if [ "${AZDO_SKIP_IMPORT:-0}" = "1" ]; then
    touch "$RUN_DIR/.skip-import"
fi

sanitize() {
    printf '%s' "$1" | tr -c 'A-Za-z0-9._-' '_'
}

# See collect-sboms.sh for the exact RESUME_FROM semantics this mirrors.
declare -A RESUMED_REPO_IDS=()
RESUME_SKIPPED=0
if [ -n "${RESUME_FROM:-}" ]; then
    resume_files=()
    IFS=',' read -ra resume_run_names <<< "$RESUME_FROM"
    for resume_run_name in "${resume_run_names[@]}"; do
        resume_file="$OUTPUT_DIR/$resume_run_name/run.jsonl"
        if [ ! -e "$resume_file" ]; then
            echo "ERROR: RESUME_FROM run '$resume_run_name' has no run.jsonl at $resume_file." >&2
            exit 1
        fi
        resume_files+=("$resume_file")
    done
    while IFS= read -r resumed_id; do
        [ -n "$resumed_id" ] && RESUMED_REPO_IDS["$resumed_id"]=1
    done < <(jq -rs '.[].repositoryId' "${resume_files[@]}")
    echo "Resuming from ${#resume_files[@]} previous run(s) ($RESUME_FROM) — ${#RESUMED_REPO_IDS[@]} already-scanned repositories will be skipped."
fi

# Emits one JSON object per line (the "value" entries) across every page of an Azure
# DevOps REST collection endpoint. Identical to collect-sboms.sh's azdo_get_all.
azdo_get_all() {
    local url="$1"
    local sep="?"
    [[ "$url" == *'?'* ]] && sep="&"
    local token=""
    local headers body http_status curl_exit
    headers=$(mktemp)
    body=$(mktemp)
    while :; do
        local page_url="$url"
        [ -n "$token" ] && page_url="${url}${sep}continuationToken=${token}"
        http_status=$(curl -sS --netrc-file "$NETRC_FILE" -D "$headers" -o "$body" -w '%{http_code}' "$page_url")
        curl_exit=$?
        if [ "$curl_exit" -ne 0 ] || [ "$http_status" -lt 200 ] || [ "$http_status" -ge 300 ] \
            || ! jq -e '.value' "$body" >/dev/null 2>&1; then
            {
                echo "ERROR: Azure DevOps API request failed for $page_url"
                [ "$curl_exit" -ne 0 ] && echo "  curl exit code: $curl_exit"
                echo "  HTTP status: $http_status"
                echo "  Response body (first 500 chars):"
                head -c 500 "$body"
                echo ""
                echo "  This usually means the PAT is invalid, expired, or lacks required scope."
                echo "  Verify it with: .\\scripts\\test-AzureDevOpsToken.ps1 -Credential (Get-Secret AzureDevOps)"
            } >&2
            touch "$ERROR_FLAG"
            rm -f "$headers" "$body"
            return 1
        fi
        jq -c '.value[]' "$body"
        token=$(grep -i '^x-ms-continuationtoken:' "$headers" | head -1 | cut -d: -f2- | tr -d ' \r\n')
        [ -z "$token" ] && break
    done
    rm -f "$headers" "$body"
}

# Finds every directory that is the topmost Java build root in the tree — a directory
# with pom.xml/build.gradle[.kts] that is not itself nested under another such directory.
# A repo's own root is checked first (the common case: one root build file drives every
# submodule); only if that's absent does this fall back to a shallow recursive search, so
# a genuine multi-root layout (independent Java projects living side by side in one repo)
# is still covered without redundantly building the same multi-module project once per
# submodule.
find_java_project_dirs() {
    local workdir="$1"
    if [ -f "$workdir/pom.xml" ] || [ -f "$workdir/build.gradle" ] || [ -f "$workdir/build.gradle.kts" ]; then
        printf '%s\n' "$workdir"
        return
    fi
    find "$workdir" -mindepth 1 -maxdepth 3 \
        \( -iname 'pom.xml' -o -iname 'build.gradle' -o -iname 'build.gradle.kts' \) -type f -printf '%h\n' \
        | sort -u
}

build_java_project() {
    local dir="$1"
    if [ -x "$dir/mvnw" ]; then
        (cd "$dir" && timeout "$JAVA_BUILD_TIMEOUT" ./mvnw --batch-mode --quiet -DskipTests compile) >/dev/null 2>&1
    elif [ -f "$dir/pom.xml" ]; then
        (cd "$dir" && timeout "$JAVA_BUILD_TIMEOUT" mvn --batch-mode --quiet -DskipTests compile) >/dev/null 2>&1
    elif [ -x "$dir/gradlew" ]; then
        (cd "$dir" && timeout "$JAVA_BUILD_TIMEOUT" ./gradlew --quiet compileJava) >/dev/null 2>&1
    elif [ -f "$dir/build.gradle" ] || [ -f "$dir/build.gradle.kts" ]; then
        (cd "$dir" && timeout "$JAVA_BUILD_TIMEOUT" gradle --quiet compileJava) >/dev/null 2>&1
    else
        return 1
    fi
}

process_repo() {
    local project_name="$1" repo_name="$2" remote_url="$3" project_id="$4" repo_id="$5"
    local workdir
    workdir=$(mktemp -d)
    trap 'rm -rf "$workdir"' RETURN

    local safe_project safe_repo out_dir dotnet_path java_path
    safe_project=$(sanitize "$project_name")
    safe_repo=$(sanitize "$repo_name")
    out_dir="$RUN_DIR/$safe_project"
    mkdir -p "$out_dir"
    dotnet_path="$out_dir/${safe_repo}.dotnet.sarif"
    java_path="$out_dir/${safe_repo}.java.sarif"

    local clone_ok=false
    if git clone --quiet --depth 1 --no-tags --shallow-submodules "$remote_url" "$workdir" >/dev/null 2>&1; then
        clone_ok=true
    fi

    local solutions_json="[]"
    local dotnet_ok=false
    if [ "$clone_ok" = true ] && scan_enabled dotnet; then
        local sln_results=()
        local sln_reports=()
        while IFS= read -r -d '' sln; do
            local restore_ok=false build_ok=false analyzed_ok=false
            if timeout "$DOTNET_RESTORE_TIMEOUT" dotnet restore "$sln" >/dev/null 2>&1; then
                restore_ok=true
                if timeout "$DOTNET_BUILD_TIMEOUT" dotnet build --no-restore "$sln" >/dev/null 2>&1; then
                    build_ok=true
                fi

                local sln_report
                sln_report="$(mktemp -u "${workdir}/roslynator-XXXXXX.sarif")"
                if timeout "$ANALYSIS_TIMEOUT" roslynator analyze "$sln" --output "$sln_report" --severity-level info >/dev/null 2>&1 \
                    && [ -s "$sln_report" ]; then
                    analyzed_ok=true
                    sln_reports+=("$sln_report")
                fi
            fi
            local rel_sln="${sln#"$workdir"/}"
            sln_results+=("$(jq -nc --arg path "$rel_sln" --argjson restore "$restore_ok" --argjson build "$build_ok" --argjson analyzed "$analyzed_ok" \
                '{path: $path, restore: $restore, build: $build, analyzed: $analyzed}')")
        done < <(find "$workdir" -iname '*.sln' -type f -print0)
        if [ "${#sln_results[@]}" -gt 0 ]; then
            solutions_json=$(printf '%s\n' "${sln_results[@]}" | jq -sc '.')
        fi
        if [ "${#sln_reports[@]}" -gt 0 ] \
            && jq -sc '{"$schema": (.[0]["$schema"] // "https://raw.githubusercontent.com/oasis-tcs/sarif-spec/master/Schemata/sarif-schema-2.1.0.json"), version: (.[0].version // "2.1.0"), runs: map(.runs[0])}' \
                "${sln_reports[@]}" > "$dotnet_path" 2>/dev/null \
            && [ -s "$dotnet_path" ]; then
            dotnet_ok=true
        fi
    fi

    local java_ok=false
    if [ "$clone_ok" = true ] && scan_enabled java; then
        local project_dirs=()
        while IFS= read -r project_dir; do
            [ -n "$project_dir" ] && project_dirs+=("$project_dir")
        done < <(find_java_project_dirs "$workdir")

        for project_dir in "${project_dirs[@]}"; do
            build_java_project "$project_dir"
        done

        local class_dirs=()
        while IFS= read -r class_dir; do
            class_dirs+=("$class_dir")
        done < <(find "$workdir" -type f -name '*.class' -printf '%h\n' 2>/dev/null | sort -u)

        if [ "${#class_dirs[@]}" -gt 0 ]; then
            local spotbugs_args=(-textui -sarif -output "$java_path")
            [ -n "$FINDSECBUGS_JAR" ] && spotbugs_args+=(-pluginList "$FINDSECBUGS_JAR")
            if timeout "$ANALYSIS_TIMEOUT" spotbugs "${spotbugs_args[@]}" "${class_dirs[@]}" >/dev/null 2>&1 \
                && [ -s "$java_path" ]; then
                java_ok=true
            fi
        fi
    fi

    jq -nc \
        --arg project "$project_name" \
        --arg repository "$repo_name" \
        --arg projectId "$project_id" \
        --arg repositoryId "$repo_id" \
        --arg webUrl "$remote_url" \
        --argjson cloned "$clone_ok" \
        --argjson solutions "$solutions_json" \
        --argjson dotnetAnalysisGenerated "$dotnet_ok" \
        --arg dotnetAnalysisPath "$([ "$dotnet_ok" = true ] && echo "$safe_project/${safe_repo}.dotnet.sarif" || echo "")" \
        --argjson javaAnalysisGenerated "$java_ok" \
        --arg javaAnalysisPath "$([ "$java_ok" = true ] && echo "$safe_project/${safe_repo}.java.sarif" || echo "")" \
        '{project: $project, repository: $repository, projectId: $projectId, repositoryId: $repositoryId,
          webUrl: $webUrl, cloned: $cloned, solutions: $solutions,
          dotnetAnalysisGenerated: $dotnetAnalysisGenerated, dotnetAnalysisPath: $dotnetAnalysisPath,
          javaAnalysisGenerated: $javaAnalysisGenerated, javaAnalysisPath: $javaAnalysisPath}' \
        >> "$RESULTS_FILE"
}

echo "Enumerating projects in organization '$AZDO_ORG'..."
project_count=0
repo_count=0

while IFS= read -r project; do
    project_name=$(jq -r '.name' <<<"$project")
    project_id=$(jq -r '.id' <<<"$project")
    if [ -n "$PROJECT_FILTER" ] && ! grep -Eq "$PROJECT_FILTER" <<<"$project_name"; then
        continue
    fi
    project_count=$((project_count + 1))
    echo "Project: $project_name"

    while IFS= read -r repo; do
        repo_name=$(jq -r '.name' <<<"$repo")
        repo_id=$(jq -r '.id' <<<"$repo")
        is_disabled=$(jq -r '.isDisabled' <<<"$repo")
        remote_url=$(jq -r '.remoteUrl' <<<"$repo")
        if [ "$is_disabled" = "true" ]; then
            echo "  Skipping disabled repository: $repo_name"
            continue
        fi
        if [ -n "$REPO_FILTER" ] && ! grep -Eq "$REPO_FILTER" <<<"$repo_name"; then
            continue
        fi
        if [ -n "${RESUMED_REPO_IDS[$repo_id]:-}" ]; then
            echo "  Skipping repository (already scanned, resuming): $repo_name"
            RESUME_SKIPPED=$((RESUME_SKIPPED + 1))
            continue
        fi
        repo_count=$((repo_count + 1))
        echo "  Repository: $repo_name"
        process_repo "$project_name" "$repo_name" "$remote_url" "$project_id" "$repo_id"
    done < <(azdo_get_all "https://dev.azure.com/${AZDO_ORG}/${project_id}/_apis/git/repositories?api-version=${API_VERSION}")
done < <(azdo_get_all "https://dev.azure.com/${AZDO_ORG}/_apis/projects?api-version=${API_VERSION}")

jq -s \
    --arg org "$AZDO_ORG" \
    --arg runTs "$RUN_TS" \
    --argjson projects "$project_count" \
    --argjson repos "$repo_count" \
    --arg resumedFrom "${RESUME_FROM:-}" \
    --argjson resumeSkipped "$RESUME_SKIPPED" \
    '{
        organization: $org,
        runTimestamp: $runTs,
        resumedFrom: (if $resumedFrom == "" then null else $resumedFrom end),
        repositoriesSkippedAlreadyScanned: $resumeSkipped,
        projectsScanned: $projects,
        repositoriesConsidered: $repos,
        repositoriesCloned: (map(select(.cloned == true)) | length),
        repositoriesCloneFailed: (map(select(.cloned == false)) | length),
        dotnetReportsGenerated: (map(select(.dotnetAnalysisGenerated == true)) | length),
        javaReportsGenerated: (map(select(.javaAnalysisGenerated == true)) | length),
        solutionsRestored: (map(.solutions[]? | select(.restore == true)) | length),
        solutionsBuilt: (map(.solutions[]? | select(.build == true)) | length),
        solutionsAnalyzed: (map(.solutions[]? | select(.analyzed == true)) | length),
        results: .
    }' "$RESULTS_FILE" > "$RUN_DIR/summary.json"

echo ""
echo "Scan complete (types: ${SCAN_TYPES})."
echo "  Projects scanned:        $project_count"
echo "  Repositories considered: $repo_count"
if [ -n "${RESUME_FROM:-}" ]; then
    echo "  Repositories skipped (resumed): $RESUME_SKIPPED"
fi
echo "  Output directory:        $RUN_DIR"

if [ -f "$ERROR_FLAG" ]; then
    echo "" >&2
    echo "One or more Azure DevOps API requests failed during this run — results above are incomplete. See ERROR lines above for details." >&2
    exit 1
fi
