#!/bin/bash
set -e

# --- Login mode: bare claude triggers the browser OAuth flow ---
if [ "$1" = "--login" ]; then
    exec claude
fi

# --- Auth check: warn only (ops shell is useful without Claude) ---
if [ -z "$ANTHROPIC_API_KEY" ] && [ -z "$CLAUDE_CODE_OAUTH_TOKEN" ] && [ ! -f "$HOME/.claude/.credentials.json" ]; then
    echo "WARNING: No Claude authentication found — 'claude' commands will not work."
    echo "         To authenticate: invoke-ops.ps1 -Mode login"
    echo ""
else
    # Suppress all onboarding prompts so 'claude' runs cleanly when invoked manually.
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

# --- Drop to interactive shell ---
exec /bin/bash
