#!/bin/bash
# Collects Trivy reports (SBOM as CycloneDX, vulnerabilities and secrets as
# SARIF) for every non-disabled Git repository across every project in an
# Azure DevOps organization.
#
# For .NET repos, each *.sln found is restored and built first so Trivy can
# read resolved package versions from project.assets.json instead of falling
# back to the version ranges declared in *.csproj. Restore/build failures are
# non-fatal — Trivy still runs and produces range-based results.
#
# Required environment: AZDO_ORG, AZDO_PAT, TRIVY_SERVER_URL
# Optional environment: AZDO_PROJECT_FILTER, AZDO_REPO_FILTER (regex), OUTPUT_DIR,
#   AZDO_SCAN_TYPES (comma-separated subset of sbom,vuln,secret; default: all three),
#   TRIVY_TIMEOUT (per-scan Trivy timeout; secret scanning in particular needs more
#   than the 5m default on large trees — default here is 15m)
#
# Every scan runs against the shared trivy-server container (see docker-compose.yml)
# rather than downloading its own vulnerability database — this script is meant to run
# as part of the core stack (appsec-scout.ps1), not standalone, so trivy-server and the
# shared token it authenticates with are always expected to be present.
set -uo pipefail

: "${AZDO_ORG:?AZDO_ORG must be set}"
: "${AZDO_PAT:?AZDO_PAT must be set}"
: "${TRIVY_SERVER_URL:?TRIVY_SERVER_URL must be set — run this via the core stack (appsec-scout.ps1), not standalone}"

TRIVY_TOKEN_FILE="${TRIVY_TOKEN_FILE:-/var/lib/trivy-token/token}"
if [ ! -s "$TRIVY_TOKEN_FILE" ]; then
    echo "ERROR: Trivy shared token not found at $TRIVY_TOKEN_FILE." >&2
    echo "       Start the core stack first (.\\scripts\\appsec-scout.ps1) so trivy-token-init has provisioned it." >&2
    exit 1
fi
TRIVY_SERVER_ARGS=(--server "$TRIVY_SERVER_URL" --token "$(cat "$TRIVY_TOKEN_FILE")")

API_VERSION="7.1"
OUTPUT_DIR="${OUTPUT_DIR:-/output}"
PROJECT_FILTER="${AZDO_PROJECT_FILTER:-}"
REPO_FILTER="${AZDO_REPO_FILTER:-}"
RESTORE_TIMEOUT="${AZDO_RESTORE_TIMEOUT:-600}"
BUILD_TIMEOUT="${AZDO_BUILD_TIMEOUT:-900}"
TRIVY_TIMEOUT="${TRIVY_TIMEOUT:-15m}"
SCAN_TYPES=" ${AZDO_SCAN_TYPES:-sbom,vuln,secret} "
SCAN_TYPES="${SCAN_TYPES//,/ }"

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
# all — including via the scheduled sbom:import-pending-scans tick, which otherwise runs
# every minute independently of this script and would import the run before it even
# finishes. PendingSbomScanImporter skips any run directory containing this marker.
if [ "${AZDO_SKIP_IMPORT:-0}" = "1" ]; then
    touch "$RUN_DIR/.skip-import"
fi

sanitize() {
    printf '%s' "$1" | tr -c 'A-Za-z0-9._-' '_'
}

# Emits one JSON object per line (the "value" entries) across every page of
# an Azure DevOps REST collection endpoint. On any transport/auth/format
# failure, prints a clear diagnostic to stderr, drops a marker at
# $ERROR_FLAG (checked at the end of the script to force a non-zero exit),
# and returns without emitting further lines for this call.
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

process_repo() {
    local project_name="$1" repo_name="$2" remote_url="$3" project_id="$4" repo_id="$5"
    local workdir
    workdir=$(mktemp -d)
    trap 'rm -rf "$workdir"' RETURN

    local safe_project safe_repo out_dir sbom_path vuln_path secret_path
    safe_project=$(sanitize "$project_name")
    safe_repo=$(sanitize "$repo_name")
    out_dir="$RUN_DIR/$safe_project"
    mkdir -p "$out_dir"
    sbom_path="$out_dir/${safe_repo}.cdx.json"
    vuln_path="$out_dir/${safe_repo}.vuln.sarif.json"
    secret_path="$out_dir/${safe_repo}.secrets.sarif.json"

    local clone_ok=false
    if git clone --quiet --depth 1 --no-tags --shallow-submodules "$remote_url" "$workdir" >/dev/null 2>&1; then
        clone_ok=true
    fi

    local solutions_json="[]"
    if [ "$clone_ok" = true ]; then
        local sln_results=()
        while IFS= read -r -d '' sln; do
            local restore_ok=false build_ok=false
            if timeout "$RESTORE_TIMEOUT" dotnet restore "$sln" >/dev/null 2>&1; then
                restore_ok=true
                if timeout "$BUILD_TIMEOUT" dotnet build --no-restore "$sln" >/dev/null 2>&1; then
                    build_ok=true
                fi
            fi
            local rel_sln="${sln#"$workdir"/}"
            sln_results+=("$(jq -nc --arg path "$rel_sln" --argjson restore "$restore_ok" --argjson build "$build_ok" \
                '{path: $path, restore: $restore, build: $build}')")
        done < <(find "$workdir" -iname '*.sln' -type f -print0)
        if [ "${#sln_results[@]}" -gt 0 ]; then
            solutions_json=$(printf '%s\n' "${sln_results[@]}" | jq -sc '.')
        fi
    fi

    local trivy_ok=false
    if [ "$clone_ok" = true ] && scan_enabled sbom \
        && trivy fs "${TRIVY_SERVER_ARGS[@]}" --timeout "$TRIVY_TIMEOUT" --format cyclonedx --output "$sbom_path" "$workdir" >/dev/null 2>&1; then
        trivy_ok=true
    fi

    local vuln_ok=false
    if [ "$clone_ok" = true ] && scan_enabled vuln \
        && trivy fs "${TRIVY_SERVER_ARGS[@]}" --timeout "$TRIVY_TIMEOUT" --scanners vuln --format sarif --output "$vuln_path" "$workdir" >/dev/null 2>&1; then
        vuln_ok=true
    fi

    local secret_ok=false
    if [ "$clone_ok" = true ] && scan_enabled secret \
        && trivy fs "${TRIVY_SERVER_ARGS[@]}" --timeout "$TRIVY_TIMEOUT" --scanners secret --format sarif --output "$secret_path" "$workdir" >/dev/null 2>&1; then
        secret_ok=true
    fi

    jq -nc \
        --arg project "$project_name" \
        --arg repository "$repo_name" \
        --arg projectId "$project_id" \
        --arg repositoryId "$repo_id" \
        --arg webUrl "$remote_url" \
        --argjson cloned "$clone_ok" \
        --argjson solutions "$solutions_json" \
        --argjson sbomGenerated "$trivy_ok" \
        --arg sbomPath "$([ "$trivy_ok" = true ] && echo "$safe_project/${safe_repo}.cdx.json" || echo "")" \
        --argjson vulnerabilitiesGenerated "$vuln_ok" \
        --arg vulnerabilitiesPath "$([ "$vuln_ok" = true ] && echo "$safe_project/${safe_repo}.vuln.sarif.json" || echo "")" \
        --argjson secretsGenerated "$secret_ok" \
        --arg secretsPath "$([ "$secret_ok" = true ] && echo "$safe_project/${safe_repo}.secrets.sarif.json" || echo "")" \
        '{project: $project, repository: $repository, projectId: $projectId, repositoryId: $repositoryId,
          webUrl: $webUrl, cloned: $cloned, solutions: $solutions,
          sbomGenerated: $sbomGenerated, sbomPath: $sbomPath,
          vulnerabilitiesGenerated: $vulnerabilitiesGenerated, vulnerabilitiesPath: $vulnerabilitiesPath,
          secretsGenerated: $secretsGenerated, secretsPath: $secretsPath}' \
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
    '{
        organization: $org,
        runTimestamp: $runTs,
        projectsScanned: $projects,
        repositoriesConsidered: $repos,
        repositoriesCloned: (map(select(.cloned == true)) | length),
        repositoriesCloneFailed: (map(select(.cloned == false)) | length),
        sbomsGenerated: (map(select(.sbomGenerated == true)) | length),
        vulnerabilityReportsGenerated: (map(select(.vulnerabilitiesGenerated == true)) | length),
        secretReportsGenerated: (map(select(.secretsGenerated == true)) | length),
        solutionsRestored: (map(.solutions[]? | select(.restore == true)) | length),
        solutionsBuilt: (map(.solutions[]? | select(.build == true)) | length),
        results: .
    }' "$RESULTS_FILE" > "$RUN_DIR/summary.json"

echo ""
echo "Scan complete (types: ${SCAN_TYPES})."
echo "  Projects scanned:        $project_count"
echo "  Repositories considered: $repo_count"
echo "  Output directory:        $RUN_DIR"

if [ -f "$ERROR_FLAG" ]; then
    echo "" >&2
    echo "One or more Azure DevOps API requests failed during this run — results above are incomplete. See ERROR lines above for details." >&2
    exit 1
fi
