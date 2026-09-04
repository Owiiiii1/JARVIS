# Архитектура

Целевая модульная архитектура. Реализация наращивается по фазам; границы модулей фиксируются сразу.

```
[Telegram] [User /chat] [Owner /jarvis] [Desktop] [Mobile] [Voice mode]
        \         |              |             |        /         |
                     Channel / Client Layer
                     Channel / Client Layer
                              |
                         Jarvis Core
         /     |      |       |        |          \
      Users  Memory Groups  AI Layer  Tools      Admin
      spaces Engine         Owner     Google/    (technical)
      +caps                 Conv/     Gmail      not chat
                            Analysis  Calendar
                            User Conv GitHub (M21)
                                      Storage
                                      Web Research (M22.3)
                                      Reminders
                              |
                           Database
```

---

## Core Backend

Центральное ядро. Единственное место, где принимается решение «что ответить» на уровне оркестрации (не на уровне канала).

Отвечает за:

- Owner Space / User Spaces; capabilities поверх role;
- users: role, access_code, timezone, status;
- channel identities (Telegram pairing кодом; без auto-create User);
- привязку внешних identity;
- conversations и messages (kind: `direct` | `group`; personal всегда с `user_id`);
- message_attachments (private, owned, ephemeral screenshots by default);
- stored_files / stored_file_chunks (persistent Storage per `user_id`; retrieval, not auto-context; Storage page owner-only);
- voice_sessions (modality over an existing conversation; no voice_messages / voice_memory);
- Web Research tools (`search_web`, `fetch_web_page`) via provider abstraction; no web content mirror tables;
- ContextBudgetManager: one LLM request is token-bounded regardless of DB size;
- Telegram Groups: авторегистрация, group conversations; **owner-only** admin;
- Tool / Integration Layer (owner-only: Google, GitHub, ElevenLabs placeholder, Web Research; Telegram channel отдельно);
- memory и topics (с Phase 2; retrieval всегда scoped);
- AI: Owner Conversation / Owner Analysis / Default User Conversation;
- Reminder Engine; Projects (owner); Proactive placeholder;
- context: summary-first, raw-on-demand, centrally budgeted (`ContextBudgetManager`); Telegram `active_conversation_id`;
- configuration (platform + per-user);
- channel abstraction;
- APIs, User Cabinet и Owner Workspace / Desktop / Mobile для тех же accounts;
- authorization / ownership на user resources.

Не отвечает за:

- парсинг Telegram update;
- UI админки и Orb shaders;
- конкретный HTTP SDK OpenAI/Anthropic/Gemini;
- захват микрофона на клиенте.

---

## AI Layer

Отдельный логический слой. Ядро говорит: «собери контекст и получи ответ». AI Layer не знает, Telegram это или desktop.

Предусмотреть компоненты:

| Компонент | Роль | Phase |
| --- | --- | --- |
| LLM Provider abstraction | единый контракт chat/complete | 1 |
| Prompt management | platform prompt роли + User General Prompt | 1 |
| Context builder | hierarchy package + ContextBudgetManager | 1 (простой), 2 (полный), M22.3 (budget) |
| Topic classifier | тема(ы) сообщения **этого** user | 2 |
| Memory retriever | память только scoped owner | 1 recent / 2 selective |
| Memory extractor | факты из диалога | 2 |
| Summarizer | сжатие длинных разговоров | 2 |
| Tool/function calling | действия во внешнем мире | later; ядро не блокирует |
| Response generator | финальный ответ пользователю | 1 |
| Analysis jobs | группы, extraction, summaries | конфиг с Phase 1; тяжёлая работа с Phase 2 |

Не привязывать систему к одной модели или одному provider. Три независимых config: **Owner Conversation AI**, **Owner Analysis AI**, **Default User Conversation AI**. Одна глобальная модель на весь Jarvis запрещена. User не наследует Owner Conversation. ADR-013, ADR-034, ADR-035.

См. [AI_PROVIDER_ARCHITECTURE.md](AI_PROVIDER_ARCHITECTURE.md), [CONTEXT_BUDGET.md](CONTEXT_BUDGET.md), [WEB_RESEARCH.md](WEB_RESEARCH.md).

Classification и extraction **не обязаны** быть одним LLM-вызовом.

---

## Channel Layer

Адаптеры поверх одного ядра. Каналы: Telegram, User Cabinet, Owner Workspace (`/jarvis`), Desktop, Mobile. Voice — mode, не отдельный space. [CHANNELS.md](CHANNELS.md), [CLIENTS/CLIENT_API.md](CLIENTS/CLIENT_API.md).

Каждый адаптер:

1. принимает нативное событие канала;
2. нормализует в `InboundMessage` (user external id, channel type, text/media refs, timestamps);
3. передаёт в Core;
4. получает `OutboundMessage`;
5. отправляет в канал.

Личный канал резолвит `user_id` через `channel_identities` и работает только с **его** personal history/memory.

Разговор пользователя в Telegram DM / Cabinet виден в mobile/desktop как его же `conversations` / `messages`. Чужой user не видит этот набор.

Web Cabinet — User Space клиент. Owner Personal Workspace — `/jarvis`, не Admin. [USERS_AND_CABINET.md](USERS_AND_CABINET.md).

Тот же Telegram adapter принимает **group updates**. Они не идут в personal reply path: регистрация группы, persist, optional analysis. См. [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md).

**AI logic нельзя размещать в Telegram-specific (или любом channel-specific) коде.** ADR-001, ADR-002.

Подробно: [CHANNELS.md](CHANNELS.md), [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md).

---

## Admin Panel

Технический интерфейс управления. **Не** основной чат owner и не второй мозг. ADR-086.

На ранних этапах **не** нужен полноценный ручной редактор памяти.

Нужно прежде всего:

- **только owner**;
- **Users**: каталог Jarvis (не «admin accounts»), User Card, access_code, Telegram link, Chats / Topics / AI Settings, impersonation;
- три AI config: Owner Conversation / Owner Analysis / Default User Conversation;
- Telegram bot settings и/или Integrations overview того же source of truth;
- Settings → Integrations: Google, GitHub, ElevenLabs status, Telegram, **Web Research**, **Voice/Speech** (STT/TTS provider + configured status; no secrets, no Test Connection);
- **Telegram Groups** owner-only;
- diagnostics / logs.

Просмотр чатов user — privileged read/debug, не «писать как пользователь».

Не одна «глобальная модель на всё Jarvis». Исходящие в группу — не из UI в Bot API, а через Group Messaging Service → adapter. ADR-015.

Админка не вызывает LLM «сама по себе» для пользовательских ответов и не хранит параллельную копию prompt, которой ядро не видит. ADR-009.

Существующий Laravel Admin Kit — подходящая поверхность для этих экранов; целевая логика ответов живёт в Core, не в Inertia-страницах.

---

## Потоки данных

### Telegram без pairing

`Webhook` → Nutgram → нет identity → `/start` или разбор access_code → системный ответ. **Нет** User create. **Нет** Conversation AI.

### Telegram pairing успех

`identity persist` → Conversation Engine → **Conversation AI** greeting

### Входящее личное сообщение (уже paired или Cabinet)

`Channel Adapter` → `Core.receive` → persist raw → Conversation Engine → **Conversation AI** (+ tools если owner) → persist reply → Adapter.send`

### Входящее сообщение Telegram-группы

`Telegram Adapter` → normalize → discover/register group → persist raw → **нет автоответа** (пока policy не требует) → optional async **Analysis AI**

Полный цикл: [CONVERSATION_ENGINE.md](CONVERSATION_ENGINE.md), [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md).

### Исходящее в группу из админки

`Admin UI` → `Group Messaging Service` → `Telegram Adapter` → Bot API → persist raw

### Конфигурация

`Owner пишет три AI config` + User General Prompt → `resolveConversationAI(user)` / Owner Analysis для jobs

### Proactive Engine (placeholder)

`event/trigger` → policy → relevance → Conversation/Notification → Telegram. Reminders — первый scheduled trigger. Autonomous proactive **не** MVP.

### Cabinet / Workspace / клиенты

`Cabinet | Workspace | Desktop | Mobile | Voice mode` → auth → ownership check → `Core` (тот же engine, тот же `conversation_id`). Voice is a modality: STT text enters `ConversationTurnService`.

---

## Что сознательно отложено (`TBD`)

- Конкретная очередь/worker runtime (Redis, database queue, иное).
- Обязательная Vector DB.
- Точный протокол realtime (WebSocket / WebRTC / HTTP streaming).
- Механизм auth token flavour для Desktop/Mobile.
- Алфавит access_code кроме зарезервированного `2000`.
- Multi-tenant «много независимых инстансов». На одном инстансе: один `owner` + много `user`.

---

## Связь с фазами

Исполнение: [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md). Spaces: [USERS_AND_CABINET.md](USERS_AND_CABINET.md). Reminders: [REMINDERS.md](REMINDERS.md). Projects: [PROJECTS.md](PROJECTS.md).
