# Cursor Work Report — Milestone 21 GitHub Integration

**Date:** 2026-09-04  
**Host:** `/var/www/jarvis`  
**GitHub:** `Owiiiii1/JARVIS`

## Before HEAD

- `0223b09` `fix: keep Telegram settings only in Integrations` (unrelated UI cleanup immediately before M21)
- Prior docs HEAD: `b3011f3` `docs: plan Jarvis clients voice workspace and GitHub`

## Migration

None. Reused `integration_accounts`, `tool_execution_logs`, `tool_confirmations`.

`php artisan migrate:status`: latest ran `2026_09_04_030000_create_tool_confirmations_table` (batch 14). No new migration executed.

## GitHub OAuth routes

| Method | URI | Name |
| --- | --- | --- |
| GET | `/settings/integrations/github/connect` | `integrations.github.connect` |
| GET | `/integrations/github/callback` | `integrations.github.callback` |
| POST | `/settings/integrations/github/disconnect` | `integrations.github.disconnect` |

Owner-only (`integrations_admin`). Throttle on connect. Default callback `{APP_URL}/integrations/github/callback`.

## Env names (no values written)

Placeholders added only in `.env.example`:

- `GITHUB_CLIENT_ID`
- `GITHUB_CLIENT_SECRET`
- `GITHUB_REDIRECT_URI`

Cursor did not create or write actual credentials. Runtime `GitHubOAuthService::isConfigured()` = no.

## OAuth scopes

Requested: `repo`, `read:org`.

Not requested: `admin:org`, `delete_repo`, `admin:repo_hook`, `admin:public_key`, `gist`, `user:email`, `workflow`.

`repo` is broad and required for private repository read/write through an OAuth App.

## Provider registration

`IntegrationRegistry`: `google`, `telegram`, `elevenlabs`, `github`.

Capability: `UserCapability::GITHUB` (`github`). Owner defaults remain `*`. Regular users do not receive GitHub tools.

## Credential / API services

- `GitHubOAuthService` — authorize URL, code exchange, user fetch, optional refresh, revoke
- `GitHubOAuthState` — random state, PKCE verifier, owner-bound, TTL, one-time
- `GitHubConnectionService` — connect/callback/disconnect
- `GitHubCredentialService` — usable token only; supports non-expiring tokens and refresh-token envelope
- `GitHubApiService` — all GitHub REST HTTP
- `GitHubIntegrationProvider` — card status without live GitHub calls on page load

Envelope stored only in `integration_accounts.credentials_encrypted`. Model remains hidden from array/JSON.

## Tools implemented

**Read:** `list_github_repositories`, `get_github_repository`, `list_github_branches`, `list_github_commits`, `get_github_commit`, `compare_github_refs`, `get_github_file`, `search_github_code`, `list_github_issues`, `get_github_issue`, `list_github_pull_requests`, `get_github_pull_request`, `get_github_pull_request_diff`, `list_github_workflow_runs`, `get_github_workflow_run`.

**Write:** `create_github_issue`, `comment_github_issue`, `create_github_branch`, `create_github_pull_request`.

**Not registered:** merge, delete branch/repo, force push, file writes, workflow file edits, secrets, releases, admin ops.

## Confirmation

Standard M16 external-write policy. GitHub writes are not `alwaysConfirm`. Explicit user command allowed; model-proposed requires persisted confirmation.

## Bounds / rate limit / revoke

Limits live in `config/github.php`. Truncated results set `truncated=true`. File/diff/comment/search caps applied.

GET may retry once on transient errors. Writes retry = 0. Existing branch → `github_conflict`. Existing open PR same head/base returned instead of duplicating.

Rate limit → `github_rate_limited` plus `reset_at` / `remaining` when headers exist.

401 / bad credentials → account revoked, credentials wiped, `github_token_revoked`.

## Integrations UI

GitHub card: Not configured (Connect disabled) / Disconnected / Connected / Reconnect required. Shows login, scopes, connected_at, token health. Connect / Reconnect / Disconnect. Never shows token or client secret.

Google and Telegram cards unchanged in behavior.

## Build / non-test verification

- `npm run build` — succeeded
- `php artisan migrate:status` — no pending M21 migration
- `php artisan route:list --name=github` — 3 routes
- `php artisan queue:failed` — none
- `php artisan schedule:list` — reminders only
- `php artisan config:clear` — ran (safe; new `config/github.php`)
- `composer dump-autoload` — ran
- `vendor/bin/pint --dirty` — passed
- `php artisan test` — **NOT RUN** (Owner deferred)

## Production counts (safe)

- `integration_accounts` = 0
- GitHub accounts = 0
- owners = 1

No tokens, secrets, private file contents, issue bodies, or diffs inspected.

## TESTS NOT RUN — explicitly deferred by Owner

No PHPUnit. No live OAuth. No live repo reads. No live issue/branch/PR creation.

**LIVE GITHUB NOT TESTED.**

**Existing Google validation still deferred.**

## Known issues / residual risk

- GitHub OAuth env is unset; card is Not configured until Owner adds a GitHub OAuth App.
- `repo` scope is broad relative to the implemented write subset.
- Repeated distinct user requests can still create a second issue/comment (no blind HTTP retry; no cross-request issue fingerprint).
- GitHub code search index lag is reported via `incomplete_results` / truncated, not hidden.
- PKCE is sent on authorize/exchange (GitHub-supported); first live connect is the real check.

## Next milestone

M22 — Owner Web Workspace.

M20 combined Google smoke remains deferred by Owner.
