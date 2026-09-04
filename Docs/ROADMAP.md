# Дорожная карта

Четыре фазы. Каждая даёт работающий инкремент. Не делать Phase 4 внутри Phase 1.

Исполняемые вехи: [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md). Фазы ниже — группировка, не замена вех.

---

## Phase 1 — Telegram + persistent conversations

**Результат.** Owner проходит pairing (`2000`) и ведёт Telegram DM с persist + Owner Conversation AI. Chat Selector. Затем Cabinet, User Spaces, Reminders. Groups persist — следующая группа вех, не Google.

### Goals

- Первый канал: Telegram adapter (DM + groups).
- Raw messages и conversations в БД (`kind=direct|group`, `user_id` на personal).
- Личный ответ через **Owner Conversation AI** или **Default User Conversation AI** (`resolveConversationAI(user)`).
- Telegram Chat Selector + каталог chats общий с Cabinet; pairing создаёт `Основной`.
- Reminders (Core, Telegram-only delivery) — после работающего DM, до Google.
- Telegram Groups: discovery, persist, админ-чат, outbound через adapter, passive monitoring.
- Админка: три AI config; Telegram bot; Telegram Groups.
- Pairing + access_code; owner admin vs user cabinet routing.

### Основные компоненты

- Core (users, identities, conversations, messages, telegram_groups, user_ai_settings слот)
- Telegram adapter
- Groups module + Group Messaging Service
- Recent-window retriever **per user + conversation**
- Owner Conversation / Owner Analysis / Default User Conversation — без inheritance owner→user
- Admin config

### Prerequisites

- Работающий backend и админка окружения
- Telegram bot token
- Выбранные реализации для трёх AI configs (могут совпасть физически; конфиги раздельные)

### Definition of done

См. Phase 1 в [DEVELOPMENT_PHASES.md](DEVELOPMENT_PHASES.md). Коротко: рестарт не обнуляет личный контекст; AI не в Telegram-коде; группа без ручного ID; Jarvis в группу сам не пишет.

### Не входит

- Topics / extraction / vector search / глубокий group analysis
- Автоответы и сложные group policies
- Смешение group history с personal memory или context разных users
- Обязательный полный Cabinet в первом Telegram-срезе
- Mobile, Desktop, Voice
- Tools как продукт
- Human-like barge-in

---

## Users & Cabinet (после persist Phase 1)

**Результат.** Несколько изолированных пользователей; Web Cabinet как ChatGPT; админ видит User Card.

### Goals

- Admin Users / User Card / Chats (read) / Topics / AI Settings / impersonated Open Cabinet
- Cabinet: список чатов, New Chat, Profile, timezone, свой General Prompt
- Тот же каталог chats, что Telegram Chat Selector
- Reminders (Telegram-only delivery)
- Default User Conversation AI ≠ Owner Conversation AI
- Ownership на всех user endpoints

См. [USERS_AND_CABINET.md](USERS_AND_CABINET.md). Не ждать Phase 4.

---

## Phase 2 — Structured Memory

**Результат.** У Jarvis большая долговременная память, в модель уходит только релевантное.

### Goals

- Topics, memories, summaries, revisions
- Selective context package (не вся личная история и не вся лента группы)
- Пересчёт derived слоя с raw
- Classifier / extractor как отдельные шаги (**Owner Analysis AI**)
- Projects (Owner Space, ≠ Topic) — relations, не копии
- Group Knowledge Search tool для owner personal chat
- Cross-chat: summary-first / raw-on-demand
- Hierarchical analysis больших group histories
- Group knowledge с provenance ≠ personal memory

### Основные компоненты

- Memory engine (personal per `user_id` + group + optional global)
- Расширенный context builder
- Owner Analysis AI на jobs; позже вложенные слоты без смены бизнес-логики
- Диагностика памяти и group knowledge в админке (базовая)

### Prerequisites

- Phase 1 persist, adapter, telegram_groups
- Те же таблицы messages (direct + group)

### Definition of done

Вся история не пихается в prompt. Факты трассируются к raw. Смена модели не уничтожает архив. Group analysis не пишет в user profile.

### Не входит

- Обязательная Vector DB
- Mobile/Desktop/Voice как must
- Полноценный ручной редактор графа памяти

---

## Phase 3 — Workspace + native clients + Voice

**Результат.** Owner общается с Jarvis в Personal Workspace (не в Admin). Тот же Core на Desktop (Tauri) и Mobile (Flutter), включая Voice Mode + Orb. GitHub — owner Integration Framework tools.

### Goals

- Combined Google smoke / hardening (validation; deferred by Owner)
- GitHub integration (M21 implemented, not live-validated)
- Owner Web Workspace (`/jarvis`, M22 implemented / not validated; M22.1 images + artifacts implemented / not validated)
- Versioned Client API
- Voice Runtime ≠ Voice UI
- Desktop repo `Owiiiii1/JARVIS-Desktop`
- Mobile repo `Owiiiii1/JARVIS-Mobile`
- Те же accounts, `conversation_id`, **личная** memory

### Основные компоненты

- [CLIENTS/WEB_WORKSPACE.md](CLIENTS/WEB_WORKSPACE.md)
- [CLIENTS/CLIENT_API.md](CLIENTS/CLIENT_API.md)
- [CLIENTS/DESKTOP_APP.md](CLIENTS/DESKTOP_APP.md)
- [CLIENTS/MOBILE_APP.md](CLIENTS/MOBILE_APP.md)
- [VOICE_ARCHITECTURE.md](VOICE_ARCHITECTURE.md) + [CLIENTS/VOICE_UI.md](CLIENTS/VOICE_UI.md)

### Prerequisites

- M19 Gmail + M18 Calendar implemented
- Live Google smoke before relying on mail/calendar in Workspace
- Auth / realtime transport `TBD`

### Definition of done

Смена клиента не меняет «личность» и историю **этого** user. Голосовая реплика пишется в выбранный `conversation_id`. Admin остаётся технической панелью.

### Не входит

- Полный Phase 4 initiative/barge-in качества
- Отдельный голосовой продукт
- Tauri/Flutter внутри Laravel repo
- Overbuilt mobile ecosystem

---

## Phase 4 — Human-like conversational system

**Результат.** Общение естественное и непрерывное: не только пары вопрос–ответ.

### Goals

- Latency, turn-taking, interruptibility
- References и неполные фразы
- Initiative и clarification в рамках настроек
- Тон/личность стабильны на всех каналах

### Основные компоненты

- Conversational intelligence layer
- Улучшенный working context
- Voice barge-in доведённый до продукта
- Политики пауз и смены темы

### Prerequisites

- Phase 2 retrieval
- Phase 3 клиенты и голос
- Зрелый system prompt / profile

### Definition of done

См. [HUMAN_LIKE_ASSISTANT.md](HUMAN_LIKE_ASSISTANT.md). Пользователь не пересказывает вчерашний день с нуля.

### Не входит

- Переписывание storage
- Привязка к одному LLM или speech vendor

---

## Принцип приоритизации

1. Сначала простая рабочая система (Phase 1) с spaces, Chat Selector и multi-user контрактами.
2. Reminders до Google. Users / Cabinet сразу после persist.
3. Затем memory, Projects, group analysis/search (Phase 2).
4. Затем Google smoke, GitHub, Owner Workspace, Voice, native clients (Phase 3) на тех же accounts.
5. Proactive Engine — future, не MVP. Затем естественность (Phase 4).

Post-M19 executable order: [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) (M20–M28).

Не over-engineer Phase 1. Не принимать решений, которые ломают этот порядок (например, AI внутри Telegram handler).
