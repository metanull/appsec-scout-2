# AppSec Scout — MCP Server

AppSec Scout embeds a [Model Context Protocol](https://modelcontextprotocol.io) server so a
Claude client (Claude Code or Claude Desktop) can triage findings per software asset, system or
container across every source. It is built on the first-party `laravel/mcp` package and is
registered as the local server `appsec-scout` (`app-laravel/routes/ai.php`).

## How it runs

- **Transport: stdio only.** The client starts one server process per session with
  `docker compose exec` into the running `app` container. There is no HTTP endpoint, so claude.ai
  web connectors (which need a public HTTPS URL) are not supported.
- **Identity.** The server acts as the AppSec Scout user named by the `MCP_USER_EMAIL`
  environment variable of that process. There is no browser login and no TOTP step: anyone who
  can `docker exec` into the stack already controls it. Disabled users are refused.
- **Permissions.** Every tool checks the same permission the equivalent UI action checks, using
  that user's role. Give the MCP user the narrowest role that fits (e.g. *Triage* for state and
  severity changes, *Plan* for work items).
- **Credential flows** are unchanged. User-triggered actions (state, severity, comments, work
  items) run synchronously as the MCP user, with that user's personal credentials (Profile →
  Integrations). Data collection is queued as background jobs that use the system credentials.
- **Audit.** Every write is recorded in the audit log under the MCP user, with `channel: mcp` in
  its payload. Unexpected tool failures are recorded in the Error log under channel `mcp`.
- **Billing.** Tool calls run inside your Claude client session, so they count against that
  client's plan (for example a Claude Max subscription). No Anthropic API key is involved.

## Prerequisites

- The stack is running (`.\scripts\appsec-scout.ps1`).
- The `app` image contains the MCP server. That is the case when the image was built from this
  repository after the MCP server was added, or when the published GHCR image is newer than it.
- An AppSec Scout user exists for the MCP server to act as.

## Register the server

Replace `<repo>` with the repository path (e.g. `E:/appsec-scout-2`) and `<email>` with the
user's email. `--project-directory` makes Docker Compose read the repository's `.env`, so the
same `COMPOSE_FILE` mode (MySQL / PostgreSQL / GHCR) as the running stack applies.

**Claude Code** (from any terminal, logged in with your Claude subscription):

```bash
claude mcp add appsec-scout --scope user -- docker compose --project-directory <repo> exec -T -e MCP_USER_EMAIL=<email> app php -d display_errors=stderr artisan mcp:start appsec-scout
```

**Claude Desktop** — add to `claude_desktop_config.json` and restart the app:

```json
{
  "mcpServers": {
    "appsec-scout": {
      "command": "docker",
      "args": [
        "compose", "--project-directory", "<repo>",
        "exec", "-T", "-e", "MCP_USER_EMAIL=<email>",
        "app", "php", "-d", "display_errors=stderr", "artisan", "mcp:start", "appsec-scout"
      ]
    }
  }
}
```

Both flags matter: stdout carries the protocol, so `-T` (no pseudo-terminal) and
`display_errors=stderr` (PHP warnings off stdout) keep it clean.

Check the connection by asking Claude to call the `whoami` tool: it returns the user, its roles
and its permissions.

## Troubleshooting

| Symptom | Cause |
|---|---|
| `MCP_USER_EMAIL is not set` | The `-e MCP_USER_EMAIL=...` argument is missing from the launch command. |
| `No user with email …` / `User … is disabled` | Create or re-enable the user under Users. |
| `Permission denied: this tool requires the … permission` | The MCP user's role lacks that permission; change the role or use another user. |
| `MCP Server with name [appsec-scout] not found` | The running image predates the MCP server; rebuild or pull a newer image. |
| The client reports the server failed to start | The stack is not running, or the `<repo>` path is wrong; run the launch command in a terminal to see the error. |
| A tool returns an error message | The message is the cause; unexpected failures are also in the Error log (channel `mcp`). |
