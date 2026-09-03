# Integrations and Tool Layer

Внешние сервисы не живут внутри Telegram adapter и не вызываются из Inertia. Conversation Engine запрашивает capability; Integration Layer исполняет.

```
Conversation Engine
  → Tool Registry / Integration Layer
    → provider adapter (Google / Telegram / ElevenLabs / …)
```

Фактическое состояние: отдельного слоя нет. Telegram settings — PARTIAL. Google / ElevenLabs — отсутствуют. [CURRENT_STATE.md](CURRENT_STATE.md).

---

## Кто имеет доступ

**Owner only** на старте. ADR-028.

Обычный `user` не видит Integrations, не получает Gmail/Calendar tools в своём Conversation package, не читает чужие OAuth tokens.

Проверка permission в Tool Layer / Core, не в UI.

---

## Providers (первый набор)

| Provider | Адаптеры | Назначение |
| --- | --- | --- |
| Google | Calendar, Gmail | owner tools |
| Telegram | Bot API (уже channel adapter) | DM, groups, outbound |
| ElevenLabs | STT / TTS / realtime | Phase 3 voice |

Новый provider = новый adapter + registry row. Jarvis Core не импортирует vendor SDK в Conversation Engine.

Telegram **channel** (получить/отправить сообщение) остаётся Channel Layer. Telegram **как integration card** в Settings — обзор статуса того же source of truth (`telegram_bot_settings`), не вторая копия token.

---

## Settings → Integrations (Owner Admin)

Раздел админки. Минимум карточки:

### Google

- Connected / Disconnected;
- connected Google account;
- scopes;
- Connect / Reconnect / Disconnect;
- last successful use;
- diagnostic status.

OAuth 2.0. Access/refresh tokens: encrypted at rest, не в UI, не в логах. Scopes — минимально необходимые. Disconnect отзывает/удаляет локальные credentials насколько позволяет API.

### ElevenLabs

- status;
- credential/config;
- voice settings later.

### Telegram

Либо отдельная вкладка Settings (как сейчас), либо строка в Integrations overview, читающая те же поля. **Один** source of truth.

---

## Google Calendar (owner tools)

Conversation AI вызывает через Tool Registry:

- list calendars;
- read events;
- search events;
- free/busy;
- create / update / delete event.

Write-actions только через tools, с permission owner. Confirmation/safety для разрушительных write — `TBD` (особенно delete).

---

## Gmail (owner tools)

- search messages;
- read message / thread;
- inbox / new mail inspect;
- create draft;
- reply;
- send;
- labels при необходимости.

Write (send/reply) — Tool Layer + явная safety boundary где уместно (`TBD` confirm).

Не встраивать Gmail SDK в Nutgram handlers.

---

## Контракт tool

Концептуально:

- name / capability id;
- allowed roles (`owner` на старте);
- input schema;
- adapter;
- execution log (без секретов);
- success / error для модели.

Conversation AI может запросить tool; Analysis AI — нет, если job не объявлен отдельно.

---

## Хранение

Концептуальные сущности (имена не финальные):

- `integration_accounts` — provider, owner user_id, status, external account, scopes, connected_at, last_used_at;
- encrypted credential blob / token columns;
- `tool_execution_logs` — capability, actor, success, latency; **не** raw tokens.

---

## Связь с AI roles

- **Conversation AI** — общение, tool calls, owner Calendar/Gmail.
- **Analysis AI** — группы, classification, extraction, summaries. Не ходит в Gmail/Calendar по умолчанию.

ADR-013, ADR-029.
