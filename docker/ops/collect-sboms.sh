#!/bin/bash
# Collects SBOMs (CycloneDX, via Trivy) for every non-disabled Git repository
# across every project in an Azure DevOps organization.
#
# For .NET repos, each *.sln found is restored and built first so Trivy can
# read resolved package versions from project.assets.json instead of falling
# back to the version ranges declared in *.csproj. Restore/build failures are
# non-fatal — Trivy still runs and produces range-based results.
#
# Required environment: AZDO_ORG, AZDO_PAT
# Optional environment: AZDO_PROJECT_FILTER, AZDO_REPO_FILTER (regex), OUTPUT_DIR
set -uo pipefail

: "${AZDO_ORG:?AZDO_ORG must be set}"
: "${AZDO_PAT:?AZDO_PAT must be set}"

API_VERSION="7.1"
OUTPUT_DIR="${OUTPUT_DIR:-/output}"
PROJECT_FILTER="${AZDO_PROJECT_FILTER:-}"
REPO_FILTER="${AZDO_REPO_FILTER:-}"
RESTORE_TIMEOUT="${AZDO_RESTORE_TIMEOUT:-600}"
BUILD_TIMEOUT="${AZDO_BUILD_TIMEOUT:-900}"

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

sanitize() {
    printf '%s' "$1" | tr -c 'A-Za-z0-9._-' '_'
}

# Emits one JSON object per line (the "value" entries) across every page of
# an Azure DevOps REST collection endpoint.
azdo_get_all() {
    local url="$1"
    local sep="?"
    [[ "$url" == *'?'* ]] && sep="&"
    local token=""
    local headers body
    headers=$(mktemp)
    body=$(mktemp)
    while :; do
        local page_url="$url"
        [ -n "$token" ] && page_url="${url}${sep}continuationToken=${token}"
        curl -sS --netrc-file "$NETRC_FILE" -D "$headers" "$page_url" -o "$body"
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

    local safe_project safe_repo out_dir sbom_path
    safe_project=$(sanitize "$project_name")
    safe_repo=$(sanitize "$repo_name")
    out_dir="$RUN_DIR/$safe_project"
    mkdir -p "$out_dir"
    sbom_path="$out_dir/${safe_repo}.cdx.json"

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
    if [ "$clone_ok" = true ] && trivy fs --format cyclonedx --output "$sbom_path" "$workdir" >/dev/null 2>&1; then
        trivy_ok=true
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
        '{project: $project, repository: $repository, projectId: $projectId, repositoryId: $repositoryId,
          webUrl: $webUrl, cloned: $cloned, solutions: $solutions, sbomGenerated: $sbomGenerated, sbomPath: $sbomPath}' \
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
        solutionsRestored: (map(.solutions[]? | select(.restore == true)) | length),
        solutionsBuilt: (map(.solutions[]? | select(.build == true)) | length),
        results: .
    }' "$RESULTS_FILE" > "$RUN_DIR/summary.json"

echo ""
echo "SBOM scan complete."
echo "  Projects scanned:        $project_count"
echo "  Repositories considered: $repo_count"
echo "  Output directory:        $RUN_DIR"
