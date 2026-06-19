#!/bin/bash
set -e

# --- Auth check (skipped for login mode) ---
if [ "$1" != "--login" ]; then
    if [ -z "$ANTHROPIC_API_KEY" ] && [ -z "$CLAUDE_CODE_OAUTH_TOKEN" ] && [ ! -f "$HOME/.claude/.credentials.json" ]; then
        echo "ERROR: No Claude authentication found."
        echo ""
        echo "Run first:  invoke-claude.ps1 -Mode login"
        exit 1
    fi
fi

# --- Login mode: bare claude triggers the browser OAuth flow ---
if [ "$1" = "--login" ]; then
    exec claude
fi

# --- Suppress all onboarding prompts ---
# These are internal state flags; writing them prevents the setup wizard, theme picker,
# trust dialog, and effort picker from blocking non-interactive runs.
CLAUDE_CONFIG='{
  "numStartups": 10,
  "installMethod": "npm",
  "autoUpdates": false,
  "hasCompletedOnboarding": true,
  "hasTrustDialogAccepted": true,
  "hasTrustDialogHooksAccepted": true,
  "hasCompletedProjectOnboarding": true,
  "hasAcknowledgedCostThreshold": true,
  "effortCalloutV2Dismissed": true,
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

# --- Clone repo if REPO_URL is set ---
if [ -n "$REPO_URL" ]; then
    echo "Cloning ${REPO_URL} (branch: ${REPO_BRANCH:-main})..."

    if [ -n "$GITHUB_TOKEN" ]; then
        # Embed token in credential store so clone and subsequent push both work
        git config --global credential.helper store
        # x-access-token is GitHub's documented dummy username for PAT-based HTTPS auth
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

# --- Shell (interactive) mode ---
if [ "$1" = "--shell" ]; then
    exec claude --dangerously-skip-permissions
fi

# --- Task mode ---
TASK="${CLAUDE_TASK:-}"
if [ -z "$TASK" ]; then
    echo "ERROR: No task specified. Set CLAUDE_TASK env var or use -Mode shell for an interactive session."
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
