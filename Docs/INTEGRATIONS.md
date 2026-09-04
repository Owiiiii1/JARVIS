# Integrations and Tool Layer

Внешние сервисы не живут внутри Telegram adapter и не вызываются из Inertia. Conversation Engine запрашивает capability; Integration Layer исполняет.

```
Conversation Engine
  → Tool Registry
    → ToolExecutionService (policy + logs)
      → Core tool | Integration provider adapter
```

**Status (M17):** IMPLEMENTED — Integration Registry, encrypted `integration_accounts`, `tool_execution_logs`, confirmation policy skeleton, owner Integrations Admin, **Google OAuth connect/callback/refresh/disconnect**. Calendar / Gmail / ElevenLabs API are **not** implemented. Connecting Google does **not** register AI tools.

Conversation Engine не импортирует Google SDK, ElevenLabs SDK или Telegram SDK.

---

## Кто имеет доступ

Google / ElevenLabs / Integrations admin — **owner only** (`integrations_admin`). ADR-028.

Обычный `user` не видит Integrations, не получает Gmail/Calendar/voice tools, не читает credentials.

**Reminders** — не этот слой и не Calendar. Core Reminder Engine доступен owner и users. [REMINDERS.md](REMINDERS.md).

Проверка permission в Tool Layer / Core, не в UI.

---

## Registry vs accounts

- **Code `IntegrationRegistry`** — available providers and capabilities.
- **DB `integration_accounts`** — connected account state and encrypted credentials.

Не хранить классы провайдеров в DB.

Зарегистрированные keys: `google`, `telegram`, `elevenlabs`.

| Provider | Status | Source of truth |
| --- | --- | --- |
| Google | OAuth identity (M17) | `integration_accounts` encrypted credentials |
| ElevenLabs | placeholder Not configured | `integration_accounts` later |
| Telegram | status bridge | existing `telegram_bot_settings` — **no token copy** |

Telegram integration card never writes `integration_accounts.credentials_encrypted`. ADR-061.

---

## Settings → Integrations (Owner Admin)

Owner-only (`/settings?tab=integrations`, also `/settings/integrations`).

Cards: Google (Connect / Reconnect / Disconnect; Not configured if env missing), Telegram (current bot/webhook/groups status, no token), ElevenLabs (voice later, no API key form).

Recent Tool Executions: time, tool, provider, status, duration, safe error code. No arguments/result bodies. Limit `config/integrations.php` `recent_executions_limit` (50). Retention TBD.

Normal user: 403. No Cabinet Integrations section.

---

## Credentials

`integration_accounts.credentials_encrypted` uses Laravel `encrypted:array`. Adapter-only getter. Hidden from `toArray` / JSON / Inertia. Never logged.

Core does not know Google token field names. Envelope is provider-specific inside the adapter.

---

## Tools

Production tools unchanged:

| Tool | Class | Provider |
| --- | --- | --- |
| `create_reminder` | write (core) | null |
| `search_conversation_history` | read | null |
| `get_project_context` | read | null |
| `search_group_knowledge` | read | null |

UI providers ≠ enabled tools. Google/Gmail/voice tools are not registered.

`ToolExecutionService` wraps every execute: resolve → capability → confirmation policy → log → run → finalize. Multi-step loop is unchanged (max 5 rounds).

Model cannot pass `authorized`, `confirmation`, `user_id`, or `integration_account_id` as rights.

---

## Confirmation policy (M16 skeleton)

| Класс | Решение |
| --- | --- |
| Read | allowed |
| Core write (`create_reminder`) | allowed (existing explicit-request UX) |
| External write + `explicitUserCommand=true` | allowed |
| External write + model-proposed / unknown | confirmation_required |
| Destructive | confirmation_required |

`ToolExecutionContext.explicitUserCommand` is set by the application layer. User-initiated conversation turns currently set `true`. Precise NLP intent detection can evolve later.

Confirmation result: `error=confirmation_required` + human summary. Full confirmation workflow is M18/M19.

---

## Execution logs

`tool_execution_logs`: started/succeeded/failed/denied/confirmation_required. Metadata only safe counts/error codes. No tokens, keys, email bodies, transcripts, or raw arguments.

Integration tools later update `last_used_at` / `last_success_at` / `last_error_*` on the account. Core tools leave `integration_account_id` null.

---

## Google OAuth (M17)

Owner-only (`integrations_admin`). Routes:

- `GET /settings/integrations/google/connect` — starts OAuth (`integrations.google.connect`)
- `GET /integrations/google/callback` — Google redirect (`integrations.google.callback`)
- `POST /settings/integrations/google/disconnect` — CSRF (`integrations.google.disconnect`)

Default callback URL: `{APP_URL}/integrations/google/callback`. Override with `GOOGLE_REDIRECT_URI`. Must match Google Cloud Console exactly.

### Env

```
GOOGLE_CLIENT_ID=
GOOGLE_CLIENT_SECRET=
GOOGLE_REDIRECT_URI=
```

Client id/secret are deployment config, not Admin fields and not `integration_accounts`. After setting env: `php artisan config:clear`.

If missing: card **Not configured**, Connect disabled, connect route safe error.

### Scopes (least privilege)

Requested now: `openid`, `email`, `profile`.

Calendar and Gmail scopes are **not** requested. Add them incrementally in M18/M19 (`include_granted_scopes=true` is already set).

Stored scopes are the **granted** list, unique and sorted. UI shows labels: Identity / Email identity / Profile.

### Flow

1. Owner Connect → Authorization Code + PKCE S256 + `access_type=offline`.
2. `prompt=consent` only when no usable refresh token (first connect / revoked).
3. Session state: random, owner id, PKCE verifier, TTL 10 minutes, one-time. No arbitrary return URL.
4. Callback requires authenticated owner. Invalid/expired/used state → reject, no token exchange.
5. Token exchange + OpenID userinfo. Identity = Google `sub` (not email). Email stored as label.
6. Upsert account. Same `sub` updates the row. A different `sub` disconnects the previous active account (one active Google account for MVP).
7. Envelope: `access_token`, `refresh_token`, `expires_at`, `token_type`. `id_token` is not stored.
8. If Google omits `refresh_token` on reconnect, the existing refresh token is kept.

### Refresh

Future Calendar/Gmail adapters **must** call `GoogleCredentialService::getValidAccessToken($account)`. Do not read `credentials_encrypted` in Core.

- Refresh when `expires_at` is within `refresh_skew_seconds` (default 120).
- `lockForUpdate` prevents parallel refresh.
- Expired access + valid refresh = still **Connected**.
- `invalid_grant` → status `revoked`, credentials wiped, Reconnect required.
- Integrations page does **not** call Google on load.

`last_success_at` updates on connect/refresh. `last_used_at` remains for future API/tool use.

### Disconnect

Attempt Google revoke, then always wipe local credentials. Remote revoke failure still disconnects locally (warning flash). Email/`sub` remain on the row for reconnect metadata.

OAuth admin actions are **not** written to `tool_execution_logs`.

### Google Cloud manual setup

1. Create/select a Google Cloud project.
2. Configure OAuth consent screen (Testing vs Production; Testing refresh tokens may expire per Google policy).
3. Create OAuth client type **Web application**.
4. Authorized redirect URI: exact Jarvis callback (`/integrations/google/callback`).
5. Put Client ID and Client Secret in server env. Do not paste them into Admin.
6. `php artisan config:clear`.
7. Owner: Settings → Integrations → Google → Connect → consent → card shows Connected + email → reload → Disconnect → Reconnect.

Do **not** enable Calendar/Gmail APIs for M17.

Manual production smoke waits until Owner sets credentials. Cursor does not create fake production credentials.

### Google Calendar / Gmail / ElevenLabs

M18 Calendar tools, M19 Gmail tools. ElevenLabs later. Connected Google account ≠ tools in the Conversation registry.

---

## Multi-step tools

Один conversational turn может содержать **несколько** последовательных tool calls. Conversation Engine **не** предполагает `one message = max one tool call`.

---

## Связь с AI roles

- **Owner Conversation AI** — общение owner + tool loop.
- **Default User Conversation AI** — reminder + history search.
- **Owner Analysis AI** — группы/jobs. Не user DM.

ADR-013, ADR-029.
