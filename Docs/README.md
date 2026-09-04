# Jarvis documentation

**Start here**

1. [CURRENT_STATE.md](CURRENT_STATE.md) — what is actually running
2. [ROADMAP.md](ROADMAP.md) — product direction
3. [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) — next executable work + concise history
4. [ARCHITECTURE.md](ARCHITECTURE.md) — module boundaries
5. [DECISIONS.md](DECISIONS.md) — durable ADRs

Do not treat old milestone write-ups as current requirements. If a doc disagrees with code, **code wins**.

---

## Status vocabulary

| Status | Meaning |
| --- | --- |
| PLANNED | Intended; not in product code |
| IMPLEMENTED | Present in production code |
| IMPLEMENTED / NOT VALIDATED | Code exists; Owner has not confirmed this function |
| MANUAL PARTIAL | Owner confirmed part of the flow only |
| MANUAL PASS | Owner confirmed the function in production |
| DEFERRED | Explicitly not current work |
| CANCELLED | Will not be built |
| HISTORICAL / SUPERSEDED | Kept for trace; do not implement |

---

## Domain docs

| Topic | Doc |
| --- | --- |
| Users / workspaces | [USERS_AND_CABINET.md](USERS_AND_CABINET.md), [USER_ADMINISTRATION.md](USER_ADMINISTRATION.md) |
| Channels / clients | [CHANNELS.md](CHANNELS.md), [CLIENTS/WEB_WORKSPACE.md](CLIENTS/WEB_WORKSPACE.md), [CLIENTS/MOBILE_APP.md](CLIENTS/MOBILE_APP.md), [CLIENTS/CLIENT_API.md](CLIENTS/CLIENT_API.md) |
| Conversation / memory | [CONVERSATION_ENGINE.md](CONVERSATION_ENGINE.md), [MEMORY_ARCHITECTURE.md](MEMORY_ARCHITECTURE.md), [CONTEXT_BUDGET.md](CONTEXT_BUDGET.md) |
| Voice | [VOICE_ARCHITECTURE.md](VOICE_ARCHITECTURE.md), [CLIENTS/VOICE_UI.md](CLIENTS/VOICE_UI.md), [HUMAN_LIKE_ASSISTANT.md](HUMAN_LIKE_ASSISTANT.md) |
| Reminders / tasks | [REMINDERS.md](REMINDERS.md), [TASKS_AND_PRODUCTIVITY.md](TASKS_AND_PRODUCTIVITY.md) |
| Personalization | [ASSISTANT_PERSONALIZATION.md](ASSISTANT_PERSONALIZATION.md) |
| Storage / research | [STORAGE.md](STORAGE.md), [WEB_RESEARCH.md](WEB_RESEARCH.md) |
| Integrations | [INTEGRATIONS.md](INTEGRATIONS.md), [PROJECTS.md](PROJECTS.md), [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md) |
| Schema / HTTP | [DATABASE.md](DATABASE.md), [API.md](API.md) |
| History of phases | [DEVELOPMENT_PHASES.md](DEVELOPMENT_PHASES.md) (archive) |

Desktop client documentation was removed. Cancellation: ADR-235 in [DECISIONS.md](DECISIONS.md).
