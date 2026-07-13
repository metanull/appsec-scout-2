#!/bin/bash
set -e

# --- Login mode: bare claude triggers the browser OAuth flow ---
if [ "$1" = "--login" ]; then
    exec claude
fi

# --- SBOM scan mode: collects SBOMs from every repo in an AzDO organization ---
if [ "$1" = "--sbom-scan" ]; then
    exec /usr/local/bin/collect-sboms.sh
fi

AUTHENTICATED=false
if [ -n "$ANTHROPIC_API_KEY" ] || [ -n "$CLAUDE_CODE_OAUTH_TOKEN" ] || [ -f "$HOME/.claude/.credentials.json" ]; then
    AUTHENTICATED=true
fi

# --- Auth check: --claude-shell/--claude-task actually need Claude and fail fast
#     without it; the plain bash shell is still useful unauthenticated, so it only warns. ---
if [ "$1" = "--claude-shell" ] || [ "$1" = "--claude-task" ]; then
    if [ "$AUTHENTICATED" = false ]; then
        echo "ERROR: No Claude authentication found." >&2
        echo "" >&2
        echo "Run first:  invoke-ops.ps1 -Claude -Login" >&2
        exit 1
    fi
elif [ "$AUTHENTICATED" = false ]; then
    echo "WARNING: No Claude authentication found — 'claude' commands will not work."
    echo "         To authenticate: invoke-ops.ps1 -Claude -Login"
    echo ""
fi

# --- Suppress onboarding/trust prompts so 'claude' runs non-interactively ---
# These are internal ~/.claude.json state flags, not documented settings.json keys.
if [ "$AUTHENTICATED" = true ]; then
    CLAUDE_CONFIG='{
  "hasCompletedOnboarding": true,
  "hasTrustDialogAccepted": true,
  "hasTrustDialogHooksAccepted": true,
  "hasCompletedProjectOnboarding": true,
  "hasAcknowledgedCostThreshold": true,
  "theme": "dark",
  "projects": {
    "/workspace": {
      "hasTrustDialogAccepted": true,
      "hasTrustDialogHooksAccepted": true,
      "hasCompletedProjectOnboarding": true
    }
  }
}'
    echo "$CLAUDE_CONFIG" > "$HOME/.claude.json"
    echo "$CLAUDE_CONFIG" > "$HOME/.claude/.config.json"
    echo "$CLAUDE_CONFIG" > "$HOME/.claude/claude.json"
fi

# --- Clone repo if REPO_URL is set ---
if [ -n "$REPO_URL" ]; then
    echo "Cloning ${REPO_URL} (branch: ${REPO_BRANCH:-main})..."

    if [ -n "$GITHUB_TOKEN" ]; then
        git config --global credential.helper store
        REPO_HOST=$(echo "$REPO_URL" | sed -E 's|https?://([^/]+)/.*|\1|')
        printf 'https://x-access-token:%s@%s\n' "$GITHUB_TOKEN" "$REPO_HOST" > "$HOME/.git-credentials"
        chmod 600 "$HOME/.git-credentials"
    fi

    git clone --depth 1 --branch "${REPO_BRANCH:-main}" "$REPO_URL" /workspace
fi

# --- Git identity ---
if [ -n "$GIT_USER_NAME" ]; then
    git config --global user.name "$GIT_USER_NAME"
fi
if [ -n "$GIT_USER_EMAIL" ]; then
    git config --global user.email "$GIT_USER_EMAIL"
fi

# --- Claude interactive session ---
if [ "$1" = "--claude-shell" ]; then
    exec claude --dangerously-skip-permissions
fi

# --- Claude autonomous task: run the task, then commit/push/PR any changes ---
if [ "$1" = "--claude-task" ]; then
    TASK="${CLAUDE_TASK:-}"
    if [ -z "$TASK" ]; then
        echo "ERROR: No task specified. Set CLAUDE_TASK, or use -Claude alone for an interactive session." >&2
        exit 1
    fi

    # Create the working branch before Claude starts so it is aware of it
    BRANCH_NAME=""
    if [ -n "$GITHUB_TOKEN" ] && [ -n "$REPO_URL" ]; then
        TIMESTAMP=$(date +%Y%m%d%H%M%S)
        BRANCH_NAME="claude/task-${TIMESTAMP}"
        git -C /workspace checkout -b "$BRANCH_NAME"
    fi

    echo "Running task: $TASK"
    claude --print --dangerously-skip-permissions "$TASK"
    CLAUDE_EXIT=$?

    if [ $CLAUDE_EXIT -ne 0 ]; then
        echo "Claude exited with code $CLAUDE_EXIT"
        exit $CLAUDE_EXIT
    fi

    # --- Commit any uncommitted changes left by Claude ---
    if [ -n "$BRANCH_NAME" ]; then
        cd /workspace

        # Stage everything (Claude may have left untracked or unstaged changes)
        git add -A

        if ! git diff --cached --quiet; then
            git commit -m "$(printf 'Claude: %.72s' "$TASK")"
        fi

        # Count commits ahead of the base branch
        LOCAL_COMMITS=$(git rev-list "origin/${REPO_BRANCH:-main}..HEAD" --count 2>/dev/null || echo 0)

        if [ "$LOCAL_COMMITS" -gt 0 ]; then
            git push origin "$BRANCH_NAME"

            gh pr create \
                --title "$(printf 'Claude: %.72s' "$TASK")" \
                --body "$(printf '## Task\n\n%s\n\n---\n🤖 Generated autonomously by Claude Code' "$TASK")" \
                --base "${REPO_BRANCH:-main}"

            echo ""
            echo "PR created successfully."
        else
            echo "No changes produced by Claude — no PR created."
        fi
    fi

    exit 0
fi

# --- Drop to interactive shell (default) ---
exec /bin/bash
