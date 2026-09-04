# Cursor work report — Google OAuth (Milestone 17)

Date: 2026-09-04  
Repo: `Owiiiii1/JARVIS`  
Host: `/var/www/jarvis`

## Git

| Item | Value |
| --- | --- |
| Branch | `main` |
| Before HEAD | `1cd9bf5` (`feat: add integration framework`) |
| Commit message | `feat: add Google OAuth integration` |
| Working tree before start | clean on `origin/main` |

## Schema / migration

No migration. M16 `integration_accounts.credentials_encrypted` holds the token envelope (`access_token`, `refresh_token`, `expires_at`, `token_type`). `id_token` is not stored. OAuth state is session-based, not a table.

## Routes

| Method | Path | Name |
| --- | --- | --- |
| GET | `/settings/integrations/google/connect` | `integrations.google.connect` |
| GET | `/integrations/google/callback` | `integrations.google.callback` |
| POST | `/settings/integrations/google/disconnect` | `integrations.google.disconnect` |

Canonical callback: `{APP_URL}/integrations/google/callback` (override `GOOGLE_REDIRECT_URI`). Must match Google Cloud Console.

Owner + `integrations_admin` only. Guest → login. Normal user → 403. Connect is throttled. Disconnect is POST + CSRF. Callback is GET (OAuth).

## Google OAuth service

`GoogleOAuthService`: authorization URL, code exchange, refresh, userinfo, revoke. Laravel HTTP client with connect/timeout, no token-endpoint retry. Errors mapped to safe codes (`configuration_missing`, `oauth_access_denied`, `oauth_invalid_state`, `token_exchange_failed`, `refresh_revoked`, `google_unavailable`). No response bodies in logs.

PKCE S256 is implemented for the confidential web client.

## State

Session payload: random state, PKCE verifier, owner user id, created_at. TTL 10 minutes. Consumed once. Bound to the authenticated owner. Return path is always Settings → Integrations.

## Scopes

Requested: `openid email profile`. Calendar/Gmail not requested. Granted scopes stored unique/sorted. UI labels: Identity, Email identity, Profile.

`access_type=offline`. `prompt=consent` only when no usable refresh token.

## Token storage

Encrypted envelope via existing Laravel cast. Client id/secret stay in env (`GOOGLE_CLIENT_ID`, `GOOGLE_CLIENT_SECRET`). Identity is Google `sub`; email is a label.

## Refresh lifecycle

`GoogleCredentialService::getValidAccessToken()` is the only future adapter entry. Refresh when expiry is within `refresh_skew_seconds` (120). Successful OAuth/refresh updates `last_success_at` only. `last_used_at` stays for later API/tool use. Expired access + refresh token = still Connected.

## Race protection

Refresh runs inside a DB transaction with `lockForUpdate`, then re-reads credentials so a second caller uses the new token.

## Reconnect

Same `sub` updates the existing row and merges tokens (preserves refresh if Google omits it). A different `sub` disconnects the previous active account. One active Google account for MVP. Rows are not silently deleted.

## Disconnect / revoke

Try Google revoke, then always wipe local credentials. Revoke failure still disconnects locally and flashes a warning. Email/`sub` remain on the disconnected row.

`invalid_grant` → status `revoked`, credentials cleared, Reconnect required.

## Google provider status

`GoogleIntegrationProvider` is no longer a placeholder. Status is local only (no Google call on Integrations page load): Not configured / Disconnected / Connected / Error / Revoked, plus token health (`healthy` / `refreshable` / `needs_reconnect` / `missing`).

## Admin UI

Settings → Integrations Google card: Connect, Reconnect, Disconnect, email, scope labels, connected date, token health. No tokens, secrets, or client secret field.

## Env / manual setup

Cursor did not write production Google credentials. Card is **Not configured** until Owner adds env values and runs `php artisan config:clear`.

Google Cloud checklist is in `Docs/INTEGRATIONS.md`. Testing-mode refresh-token limits are Google policy; Jarvis does not work around them.

OAuth admin actions are not written to `tool_execution_logs`.

## Tests

`tests/Feature/GoogleOAuthTest.php` uses `Http::fake` only. Existing M16 and Core suites remain green.

Full suite: 169 tests, 168 passed, 1 skipped.

## Build

`npm run build` succeeded.

## Production counts

| Table | Count | Change |
| --- | --- | --- |
| integration_accounts | 0 | unchanged |
| tool_execution_logs | 0 | unchanged |
| users | 1 | unchanged |

No real Google account was connected.

## Worker status

Unchanged: `analysis,memory,default` worker, telegram worker, reminder scheduler. `queue:failed` empty.

## Manual smoke

Not run. Env is unset; live Google consent requires Owner Google Cloud setup. Automated coverage is complete. This report does not claim a live Connect/consent turn.

## Known issues

- Integrations Google card stays Not configured until env is set.
- Calendar/Gmail scopes and tools are not requested or registered.
- Google Testing OAuth clients may issue limited refresh tokens.
- One active Google account only; switching accounts disconnects the previous one.

## Next milestone

Milestone 18 — Google Calendar tools (read/write via Tool Registry + confirmation policy). Reminder Engine stays separate.
