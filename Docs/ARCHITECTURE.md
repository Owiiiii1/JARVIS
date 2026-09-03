# Архитектура

Целевая модульная архитектура. Реализация наращивается по фазам; границы модулей фиксируются сразу.

```
[Telegram DM] [Cabinet] [Mobile] [Desktop] [Telegram Groups]
        \         |         |        /            |
              Channel / Client Layer
                      |
                 Jarvis Core
        /      |       |        |        \
     Users  Memory  Groups   AI Layer    Admin
     +auth  Engine  module   conversation
                             + analysis
                             + user override
                      |
                   Database
```

---

## Core Backend

Центральное ядро. Единственное место, где принимается решение «что ответить» на уровне оркестрации (не на уровне канала).

Отвечает за:

- users, профили, роли/permissions (owner ≠ второй Core);
- привязку внешних identity;
- conversations и messages (kind: `direct` | `group`; personal всегда с `user_id`);
- Telegram Groups: авторегистрация, group conversations, политики (feature permission);
- memory и topics (с Phase 2; retrieval всегда scoped);
- AI orchestration: `resolve(role, user_id)` — platform default + user override;
- context building: prompt hierarchy (platform → channel → user prompt → memory → conversation);
- configuration (platform + per-user);
- channel abstraction;
- APIs и Web Cabinet для тех же accounts;
- authorization / ownership на user resources.

Не отвечает за:

- парсинг Telegram update;
- UI админки;
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
| Context builder | hierarchy package, всегда с `user_id` | 1 (простой), 2 (полный) |
| Topic classifier | тема(ы) сообщения **этого** user | 2 |
| Memory retriever | память только scoped owner | 1 recent / 2 selective |
| Memory extractor | факты из диалога | 2 |
| Summarizer | сжатие длинных разговоров | 2 |
| Tool/function calling | действия во внешнем мире | later; ядро не блокирует |
| Response generator | финальный ответ пользователю | 1 |
| Analysis jobs | группы, extraction, summaries | конфиг с Phase 1; тяжёлая работа с Phase 2 |

Не привязывать систему к одной модели или одному provider. Минимум две независимые роли: **`conversation`** и **`analysis`**. Одна глобальная модель на весь Jarvis запрещена как архитектура (физически на MVP они могут совпасть, конфиги всё равно раздельные). ADR-013.

См. [AI_PROVIDER_ARCHITECTURE.md](AI_PROVIDER_ARCHITECTURE.md).

Classification и extraction **не обязаны** быть одним LLM-вызовом.

---

## Channel Layer

Адаптеры поверх одного ядра. Планируемые каналы: Telegram, Mobile App, Desktop App, потенциально другие.

Каждый адаптер:

1. принимает нативное событие канала;
2. нормализует в `InboundMessage` (user external id, channel type, text/media refs, timestamps);
3. передаёт в Core;
4. получает `OutboundMessage`;
5. отправляет в канал.

Личный канал резолвит `user_id` через `channel_identities` и работает только с **его** personal history/memory.

Разговор пользователя в Telegram DM / Cabinet виден в mobile/desktop как его же `conversations` / `messages`. Чужой user не видит этот набор.

Web Cabinet — клиент того же Core. [USERS_AND_CABINET.md](USERS_AND_CABINET.md).

Тот же Telegram adapter принимает **group updates**. Они не идут в personal reply path: регистрация группы, persist, optional analysis. См. [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md).

**AI logic нельзя размещать в Telegram-specific (или любом channel-specific) коде.** ADR-001, ADR-002.

Подробно: [CHANNELS.md](CHANNELS.md), [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md).

---

## Admin Panel

Технический интерфейс управления, не второй мозг.

На ранних этапах **не** нужен полноценный ручной редактор памяти.

Нужно прежде всего:

- **Users**: таблица, User Card, чаты / topics / AI settings пользователя, Open Cabinet (impersonation);
- role-based AI: platform Conversation AI и Analysis AI; per-user override;
- Telegram bot settings;
- **Telegram Groups**: список автообнаруженных групп, страница-чат, отправка через adapter;
- позднее — настройки других каналов;
- debugging / monitoring;
- позднее — диагностика памяти, topics и group knowledge.

Просмотр чатов user — privileged read/debug, не «писать как пользователь».

Не одна «глобальная модель на всё Jarvis». Исходящие в группу — не из UI в Bot API, а через Group Messaging Service → adapter. ADR-015.

Админка не вызывает LLM «сама по себе» для пользовательских ответов и не хранит параллельную копию prompt, которой ядро не видит. ADR-009.

Существующий Laravel Admin Kit — подходящая поверхность для этих экранов; целевая логика ответов живёт в Core, не в Inertia-страницах.

---

## Потоки данных

### Входящее личное сообщение

`Channel Adapter` → `Core.receive` → persist raw → Conversation Engine → **Conversation AI** → persist reply → Adapter.send`

### Входящее сообщение Telegram-группы

`Telegram Adapter` → normalize → discover/register group → persist raw → **нет автоответа** (пока policy не требует) → optional async **Analysis AI**

Полный цикл: [CONVERSATION_ENGINE.md](CONVERSATION_ENGINE.md), [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md).

### Исходящее в группу из админки

`Admin UI` → `Group Messaging Service` → `Telegram Adapter` → Bot API → persist raw

### Конфигурация

`Admin writes platform settings/prompts` + `user_ai_settings` → `DB` → `AI Layer.resolve(role, user_id)`

### Cabinet / клиенты

`Cabinet или Mobile/Desktop` → auth (cabinet vs admin context) → ownership check → `Core` (тот же engine)

---

## Что сознательно отложено (`TBD`)

- Конкретная очередь/worker runtime (Redis, database queue, иное).
- Обязательная Vector DB.
- Точный протокол realtime (WebSocket / WebRTC / HTTP streaming).
- Механизм auth (два context: admin vs cabinet; token flavour для mobile/desktop).
- Набор tools/actions.
- Multi-tenant «много независимых инстансов Jarvis». На одном инстансе — **много users** с изоляцией; owner — роль, не единственная запись.

---

## Связь с фазами

Phase 1: Core + Telegram + Groups persist/UI + AI roles + **контракты multi-user** (`user_id`, много conversations, hierarchy/override слоты). Users / Cabinet — инкремент после persist, не Phase 4.  
Phase 2: Memory Engine per-user + group analysis (group knowledge ≠ personal memory).  
Phase 3: API, mobile, desktop, voice — те же accounts.  
Phase 4: conversational intelligence **над** тем же engine.

Users / Cabinet: [USERS_AND_CABINET.md](USERS_AND_CABINET.md).
