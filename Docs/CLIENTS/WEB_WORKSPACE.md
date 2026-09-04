# Owner Web Workspace

**Status.** DOCUMENTED ONLY. Not implemented.

Планируемый owner-facing Personal Workspace. Это **не** Admin Panel и **не** текущий User Cabinet (`/cabinet`).

Workspace остаётся частью `Owiiiii1/JARVIS`: Laravel + Inertia/React, один deployment с backend.

---

## Product role

Jarvis Admin Panel и Jarvis Personal Workspace — разные интерфейсы.

| Surface | Для чего |
| --- | --- |
| Admin Panel | техническое управление: users, AI providers, integrations, Telegram groups, diagnostics, tool logs, system settings |
| Personal Workspace | общение владельца с Jarvis |

Owner **не** должен использовать Admin Panel как основной интерфейс общения.

Центральная сущность Workspace = **conversation**. Text chat и voice chat. Остальные функции вторичны: контекст и настройки вокруг разговора.

---

## Same Core

Workspace использует тот же Owner Space и те же engines, что Telegram:

- conversations / messages
- Conversation Engine
- Memory Engine
- projects
- Telegram group knowledge (explicit tools)
- Reminder Engine
- Google Calendar / Gmail tools
- Tool Registry + confirmations
- future GitHub tools
- future Voice runtime

Это **не** новый AI assistant и **не** новая memory.

Owner может начать чат в Telegram, продолжить тот же `conversation_id` в Workspace, затем голосом на Desktop/Mobile. New Chat только по явному выбору. Отдельная voice conversation автоматически не создаётся.

---

## Route

Рабочие имена:

- `/workspace`
- `/jarvis`

Финальный route — `TBD`. Не путать с `/cabinet` (User Space) и admin routes.

Auth: existing owner web session. User (`role=user`) — deny. Impersonation остаётся admin capability, не workspace product path.

---

## UI

### Center

Conversation.

**Text mode**

- messages
- tool confirmations
- attachments later
- streaming later

**Voice mode**

- realtime voice session
- live transcript
- same selected conversation
- same tools / memory / context
- interrupt / barge-in
- Orb visualization — [VOICE_UI.md](VOICE_UI.md)

### Secondary panels

Не конкурируют визуально с conversation:

- conversations
- projects
- reminders
- integrations status
- Gmail / Calendar connection status
- memory / profile
- personal General Prompt / settings
- voice settings

No Gmail inbox admin UI. No Calendar admin grid as the primary surface. Integrations connect/enable remain Admin (or a thin status + deep-link to Admin).

---

## Text chat

Same path as Cabinet/Telegram conceptually: persist inbound → `ConversationTurnService` / Conversation Engine → persist outbound.

Web inbound: `channel=web` (or a workspace-specific channel label `TBD`) + client idempotency key. Messages from Telegram and Workspace share one chronological history.

Tool confirmations reuse `tool_confirmations` + Confirm / Cancel. Send-email preview stays recipients / subject / bounded body.

---

## Voice

Voice is a **mode** on the selected conversation. See [VOICE_ARCHITECTURE.md](../VOICE_ARCHITECTURE.md) and [VOICE_UI.md](VOICE_UI.md).

Web Voice Runtime talks to Core voice sessions. Orb consumes `VoiceVisualizationState` only — not a vendor SDK.

---

## API / realtime boundary

M22 can ship Workspace on Inertia (same-origin session), same as Cabinet.

Public versioned client API ([CLIENT_API.md](CLIENT_API.md)) is required before Desktop/Mobile. Workspace must not invent a second engine. Streaming/voice transport `TBD` (SSE / WebSocket / WebRTC).

Clients never hold Google/Gmail credentials, never run tools locally, never select the LLM vendor.

---

## Out of scope now

- Implementing `/workspace` or `/jarvis`
- Three.js Orb
- Voice provider
- Changing production routes
