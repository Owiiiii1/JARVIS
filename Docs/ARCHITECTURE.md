# Архитектура

Целевая модульная архитектура. Реализация наращивается по фазам; границы модулей фиксируются сразу.

```
[Telegram DM] [Telegram Groups] [Mobile] [Desktop]
        \            |              /       /
         Channel Layer (один Telegram adapter)
                |
           Jarvis Core
      /     |      |       \
 Memory  Groups  AI Layer   Admin
 Engine  module  conversation
                 + analysis
                |
              Database
```

---

## Core Backend

Центральное ядро. Единственное место, где принимается решение «что ответить» на уровне оркестрации (не на уровне канала).

Отвечает за:

- users и привязку внешних identity;
- conversations и messages (kind: `direct` | `group`);
- Telegram Groups: авторегистрация, group conversations, политики;
- memory и topics (с Phase 2; в Phase 1 — заготовки интерфейсов);
- AI orchestration: вызов AI Layer **по роли** (`conversation` / `analysis`), не провайдера из канала;
- context building (делегирует AI Layer / Context Builder);
- configuration (читает централизованные настройки);
- channel abstraction (вход/выход нормализованных сообщений);
- APIs для будущих клиентов.

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
| Prompt management | system/general prompt, шаблоны ролей | 1 |
| Context builder | сбор context package | 1 (простой), 2 (полный) |
| Topic classifier | тема(ы) сообщения | 2 |
| Memory retriever | выбор релевантной памяти | 1 recent / 2 selective |
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

Личные каналы работают с одним user identity (через `channel_identities`) и **одной personal history/memory**.

Разговор, начатый в Telegram DM, доступен в mobile/desktop как те же personal `conversations` / `messages`.

Тот же Telegram adapter принимает **group updates**. Они не идут в personal reply path: регистрация группы, persist, optional analysis. См. [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md).

**AI logic нельзя размещать в Telegram-specific (или любом channel-specific) коде.** ADR-001, ADR-002.

Подробно: [CHANNELS.md](CHANNELS.md), [TELEGRAM_GROUPS.md](TELEGRAM_GROUPS.md).

---

## Admin Panel

Технический интерфейс управления, не второй мозг.

На ранних этапах **не** нужен полноценный ручной редактор памяти.

Нужно прежде всего:

- role-based AI: отдельно Conversation AI и Analysis AI (provider, model, prompt, parameters);
- Telegram bot settings;
- **Telegram Groups**: список автообнаруженных групп, страница-чат, отправка через adapter;
- позднее — настройки других каналов;
- debugging / monitoring;
- просмотр personal conversations/messages;
- позднее — диагностика памяти, topics и group knowledge.

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

`Admin writes settings/prompts` → `DB` → `Core/AI Layer reads at runtime`

### Клиентские приложения

`Mobile/Desktop` → `HTTP/API (+ realtime TBD)` → `Core` (тот же engine, что у Telegram)

---

## Что сознательно отложено (`TBD`)

- Конкретная очередь/worker runtime (Redis, database queue, иное).
- Обязательная Vector DB.
- Точный протокол realtime (WebSocket / WebRTC / HTTP streaming).
- Механизм auth для mobile/desktop (token flavour, session model).
- Набор tools/actions.
- Мультипользовательский multi-tenant режим сверх «один владелец Jarvis». На старте предполагается **один основной пользователь** (владелец инстанса); модель `users` всё равно нужна для админов и будущих identity.

---

## Связь с фазами

Phase 1 реализует Core + Telegram adapter (DM + Groups persist/UI) + AI Layer с ролями conversation/analysis + админ-конфиг.  
Phase 2 наполняет Memory Engine и group analysis (group knowledge ≠ personal memory).  
Phase 3 добавляет API-адаптеры и Voice I/O.  
Phase 4 наращивает conversational intelligence **над** тем же engine.
