# Integrations and Tool Layer

Внешние сервисы не живут внутри Telegram adapter и не вызываются из Inertia. Conversation Engine запрашивает capability; Integration Layer исполняет.

```
Conversation Engine
  → Tool Registry
    → ToolExecutionService (policy + logs)
      → Core tool | Integration provider adapter
```

**Status (M18):** IMPLEMENTED — Integration Registry, encrypted `integration_accounts`, `tool_execution_logs`, persisted `tool_confirmations`, owner Integrations Admin, Google OAuth (identity + incremental Calendar scopes), **Google Calendar tools**. Gmail / ElevenLabs API are **not** implemented. Identity connection without Calendar scope does not call Calendar API.

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
| Google | OAuth identity + Calendar tools (M18) | `integration_accounts` encrypted credentials |
| ElevenLabs | placeholder Not configured | `integration_accounts` later |
| Telegram | status bridge | existing `telegram_bot_settings` — **no token copy** |

Telegram integration card never writes `integration_accounts.credentials_encrypted`. ADR-061.

---

## Settings → Integrations (Owner Admin)

Owner-only (`/settings?tab=integrations`, also `/settings/integrations`).

Cards: Google (Connect / Reconnect / Disconnect / Enable Calendar; Identity vs Calendar vs Gmail capability states; Not configured if env missing), Telegram (current bot/webhook/groups status, no token), ElevenLabs (voice later, no API key form). Gmail is never shown as ready.

Recent Tool Executions: time, tool, provider, status, duration, safe error code. No arguments/result bodies. Limit `config/integrations.php` `recent_executions_limit` (50). Retention TBD.

Normal user: 403. No Cabinet Integrations section.

---

## Credentials

`integration_accounts.credentials_encrypted` uses Laravel `encrypted:array`. Adapter-only getter. Hidden from `toArray` / JSON / Inertia. Never logged.

Core does not know Google token field names. Envelope is provider-specific inside the adapter.

---

## Tools

| Tool | Class | Provider |
| --- | --- | --- |
| `create_reminder` | write (core) | null |
| `search_conversation_history` | read | null |
| `get_project_context` | read | null |
| `search_group_knowledge` | read | null |
| `list_google_calendars` | read | google |
| `list_calendar_events` | read | google |
| `get_calendar_event` | read | google |
| `search_calendar_events` | read | google |
| `google_calendar_freebusy` | read | google |
| `create_calendar_event` | write | google |
| `update_calendar_event` | write | google |
| `delete_calendar_event` | destructive | google |
| `confirm_tool_action` | write (core) | null (only when a pending confirmation exists) |
| `cancel_tool_action` | write (core) | null (only when a pending confirmation exists) |

Calendar tools require capability `google_calendar` (owner). Definitions stay available when disconnected; runtime returns `google_not_connected` or `calendar_scope_required`. Normal users do not receive Calendar tools. Gmail/voice tools are not registered.

`ToolExecutionService` wraps every execute: resolve → capability → confirmation policy → log → run → finalize. Multi-step loop is unchanged (max 5 rounds).

Model cannot pass `authorized`, `confirmation`, `user_id`, or `integration_account_id` as rights.

---

## Confirmation policy

| Класс | Решение |
| --- | --- |
| Read | allowed |
| Core write (`create_reminder`) | allowed (existing explicit-request UX) |
| External write + `explicitUserCommand=true` | allowed |
| External write + model-proposed / unknown | confirmation_required |
| Destructive | confirmation_required |

`ToolExecutionContext.explicitUserCommand` is set by the application layer. User-initiated conversation turns currently set `true`. Precise NLP intent detection can evolve later.

Confirmation result: `error=confirmation_required` + human summary + `confirmation_id`. A `tool_confirmations` row is persisted (encrypted arguments, TTL 10 minutes, user+conversation bound).

Destructive Calendar delete always requires confirmation, even when the user wrote «удали». Model cannot invent a token or self-confirm. Application layer accepts only conservative phrases (`да` / `yes` / `confirm` / `подтверждаю` / `удалить`) or Web/Telegram Confirm buttons (they send `да`). Cancel: `нет` / `no` / `cancel` / `отмена`. Expired or cancelled rows cannot execute. Confirmed execute is one-time.

`confirm_tool_action` / `cancel_tool_action` are exposed only while a pending row exists and still require the server-side affirmative/cancel signal.

---

## Execution logs

`tool_execution_logs`: started/succeeded/failed/denied/confirmation_required. Metadata only safe counts/error codes. No tokens, keys, email bodies, transcripts, or raw arguments.

Calendar tool success/failure updates `last_used_at` / `last_success_at` / `last_error_*` on the Google account. Core tools leave `integration_account_id` null. Metadata may include `result_count`, `operation`, `truncated`, `confirmation_id` — never event titles, descriptions, attendees, or tokens.

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

Identity connect requests only: `openid`, `email`, `profile`.

Calendar incremental connect (`?intent=calendar`) adds `https://www.googleapis.com/auth/calendar` (list + events + freebusy + write). Gmail scopes are **not** requested. `include_granted_scopes=true`. Stored scopes = union of previously granted + newly granted. Identity scopes are not dropped.

UI labels: Identity / Email identity / Profile / Calendar.

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

`GoogleCalendarService` (and any later Gmail adapter) **must** call `GoogleCredentialService::getValidAccessToken($account)`. Do not read `credentials_encrypted` in Core or tools.

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

Enable the Google Calendar API in Google Cloud before using Calendar tools. Do **not** enable Gmail for M18.

Manual production Google smoke is deferred until Google integration milestones are complete. Cursor does not connect real Google or mutate real events.

### Google Calendar (M18)

Live Google Calendar is the source of truth. No local `calendar_events` cache, no sync cron, no webhook.

Adapter: `GoogleCalendarService` via Laravel HTTP client (`config/google_calendar.php` bounds and timeouts). Safe GET retry only. Tools never call Google HTTP.

Account resolution: current owner → `IntegrationAccountService` → active Google account. Model cannot pass `integration_account_id`.

Timezone: owner `users.timezone` is the authoritative fallback. Naive datetimes are interpreted in that IANA zone and sent to Google as wall time + `timeZone` (DST-safe). All-day events stay dates (`all_day=true`), not fake midnight meetings.

Create uses a server-derived Google-compatible event id (`jvs` + hex) from user/conversation/tool-call id. The model cannot supply the idempotency key. Same ToolCall retry reuses the id; 409 returns the existing event.

Update is PATCH of supplied fields only. `etag` / If-Match when present; conflict → `calendar_conflict`. Recurrence: read metadata only; no RRULE authoring; update/delete use the explicit instance id. Google Meet / `conferenceData` is not created.

`send_updates`: `none` (default), `all`, `externalOnly`. Use `all` only when the user explicitly asked to invite.

Search/list/freebusy are bounded (config max events, 31-day freebusy, default search window −90 / +365 days). Pagination stops at the cap and sets `truncated`.

Reminder Engine remains a separate subsystem. «Напомни» ≠ Calendar event.

### Gmail / ElevenLabs

M19 Gmail tools. ElevenLabs later. Identity or Calendar connected ≠ Gmail ready.

---

## Multi-step tools

Один conversational turn может содержать **несколько** последовательных tool calls. Conversation Engine **не** предполагает `one message = max one tool call`.

---

## Связь с AI roles

- **Owner Conversation AI** — общение owner + tool loop.
- **Default User Conversation AI** — reminder + history search.
- **Owner Analysis AI** — группы/jobs. Не user DM.

ADR-013, ADR-029.
