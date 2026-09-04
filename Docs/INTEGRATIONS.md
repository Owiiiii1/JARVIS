# Integrations and Tool Layer

Внешние сервисы не живут внутри Telegram adapter и не вызываются из Inertia. Conversation Engine запрашивает capability; Integration Layer исполняет.

```
Conversation Engine
  → Tool Registry
    → ToolExecutionService (policy + logs)
      → Core tool | Integration provider adapter
```

**Status (M16):** IMPLEMENTED — Integration Registry, `integration_accounts`, encrypted credentials, `tool_execution_logs`, confirmation policy skeleton, owner Integrations Admin. Google OAuth / Calendar / Gmail / ElevenLabs API are **not** implemented.

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

| Provider | M16 | Source of truth |
| --- | --- | --- |
| Google | placeholder Disconnected | `integration_accounts` when later connected |
| ElevenLabs | placeholder Not configured | `integration_accounts` later |
| Telegram | status bridge | existing `telegram_bot_settings` — **no token copy** |

Telegram integration card never writes `integration_accounts.credentials_encrypted`. ADR-061.

---

## Settings → Integrations (Owner Admin)

Owner-only (`/settings?tab=integrations`, also `/settings/integrations`).

Cards: Google (Connect disabled — next milestone), Telegram (current bot/webhook/groups status, no token), ElevenLabs (voice later, no API key form).

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

## Google Calendar / Gmail / ElevenLabs

Still future milestones. M16 does not call those APIs and does not create OAuth URLs.

---

## Multi-step tools

Один conversational turn может содержать **несколько** последовательных tool calls. Conversation Engine **не** предполагает `one message = max one tool call`.

---

## Связь с AI roles

- **Owner Conversation AI** — общение owner + tool loop.
- **Default User Conversation AI** — reminder + history search.
- **Owner Analysis AI** — группы/jobs. Не user DM.

ADR-013, ADR-029.
