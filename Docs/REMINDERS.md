# Reminders

Собственная подсистема Jarvis. **Не** Google Calendar и не календарное событие.

Owner и обычные Users создают reminders в **своём** User Space. Cross-user reminder обычному user недоступен. Administrative reminder peek для owner — later, не MVP.

Engines общие (Conversation Engine, Reminder Engine, Telegram Adapter). Scope = `user_id`.

---

## Pipeline

```
Conversation AI
  → create_reminder tool
  → ReminderService (Core)
  → reminders (UTC)
  → jarvis:reminders:dispatch (scheduler)
  → ReminderDeliveryService
  → существующий Telegram bot (sendMessage)
  → текущая linked Telegram identity этого User
```

Google Calendar — отдельный owner-only tool layer (M18). «Поставь встречу» ≠ «напомни». Reminder Engine не читает и не пишет Google Calendar.

Natural-language время **не** парсится regex в Core. Модель передаёт structured `run_at_local`; Core валидирует и переводит в UTC.

---

## Delivery (сейчас)

**Только Telegram.**

Не web, не email, не mobile/desktop push. Web Cabinet может **создать** reminder через тот же Conversation Engine; доставка всё равно идёт в Telegram.

### Нет linked Telegram identity

**Reminder не создаётся.**

Jarvis сообщает:

`Для получения напоминаний сначала подключите Telegram.`

Не сохранять scheduled reminder, который невозможно доставить. ADR-046.

Tool result:

```json
{ "success": false, "error": "telegram_not_connected" }
```

### Identity на момент доставки

Reminder принадлежит User, не Telegram identity.

- If User is `disabled` — do not deliver; cancel with `user_disabled`. No new assistant interaction.
- If User перепривязал Telegram — отправить на **текущую** linked identity этого User.
- If identity нет — retry, затем `failed` (`telegram_not_connected`).
- Delivery identity must belong to the same `user_id` as the reminder.

---

## Entity

Таблица `reminders`. Все timestamps в БД — UTC.

| Поле | Смысл |
| --- | --- |
| id | |
| user_id | владелец reminder |
| source_conversation_id | conversation, откуда попросили (nullable FK) |
| source_message_id | inbound user message этого turn (nullable FK) |
| text | что напомнить |
| run_at | абсолютное UTC |
| original_local_time | нормализованное локальное ISO на момент создания |
| timezone | IANA пользователя (`users.timezone`) |
| status | `scheduled` / `processing` / `delivered` / `cancelled` / `failed` |
| delivered_at | |
| cancelled_at | |
| recurrence_rule | nullable; **recurrence не реализован** |
| last_error | |
| metadata | json: `attempts`, `next_retry_at`, `reason` |
| created_at / updated_at | |

Indexes: `user_id`, `status`, `run_at`, `(status, run_at)`.

---

## Timezone

`users.timezone` — IANA, например `Europe/Rome`.

Каждый conversation turn получает динамический контекст:

```text
Current user local time:
2026-09-03T19:56:00+02:00

User timezone:
Europe/Rome
```

Фразы «завтра», «через два часа» интерпретирует Conversation AI. Tool получает `run_at_local` (ISO 8601). Core берёт **wall-clock** и применяет IANA timezone пользователя (DST через DateTimeZone). Если offset в аргументе противоречит IANA на эту дату — побеждает IANA.

`run_at` должен быть в будущем. Прошлое → reminder не создаётся (`past_time`).

Неоднозначное время без часов («напомни завтра сходить в магазин») — модель спрашивает «Во сколько напомнить?» и **не** вызывает tool. Не подставлять 09:00.

Daypart без точных часов («завтра утром») на этом этапе тоже уточняется.

---

## Tool

Первый Jarvis tool: `create_reminder`.

Аргументы модели: `text`, `run_at_local`, опционально `timezone`, `original_time_expression`.

`user_id` и `conversation_id` модель **не** задаёт. Identity из Conversation Turn context.

Доступен при capability `reminders` (owner и user). Capability из LLM arguments не доверяется.

Успех возвращается модели structured JSON; финальную фразу формулирует модель, не Telegram adapter.

Повторяющиеся напоминания не поддерживаются. Не создавать one-time reminder, выдавая его за recurring.

Cancel / list tools — later. Архитектура Tool Registry это позволяет.

M25U.3: Workspace **Reminders panel** is a view/management surface over the same engine. `GET /jarvis/reminders` and `GET /chat/reminders` list the current user’s reminders. `POST .../reminders/{id}/cancel` cancels an owned scheduled/processing reminder. Ownership is `user_id`; foreign ids 404. Active count is a cheap workspace prop; the list is lazy-loaded when the drawer opens. Creation remains conversational (`create_reminder`). Recurrence is still not implemented. Delivery remains Telegram-only. No frontend natural-language scheduler.

---

## Delivery text

В нужный момент Telegram получает системное сообщение, **не** AI turn:

`⏰ Напоминание: {text}`

Gemini не вызывается. Semantic chat history не загрязняется. Достаточно `status` / `delivered_at`.

---

## Scheduler

Команда: `jarvis:reminders:dispatch` каждую минуту, `withoutOverlapping()`.

Выбирает `scheduled` + `run_at <= now UTC` (+ due retry), атомарно `processing` (`lockForUpdate` / `skipLocked`), затем delivered / retry / failed / cancelled.

At-most-once на application level: повторный claim того же row не должен отправить дважды.

Retry: до 3 попыток (`metadata.attempts`, `next_retry_at`). Не бесконечно.

Production cron (deploy user):

```text
* * * * * cd /var/www/jarvis && php artisan schedule:run >> /dev/null 2>&1
```

---

## Capabilities

`reminders` есть у owner и user. Проверка в Core, не в адаптере.

---

## Proactive

Reminder — первый scheduled trigger. Общий Proactive Engine — future: [ARCHITECTURE.md](ARCHITECTURE.md). Не реализовывать autonomous проактивность сейчас.

Связано: [CONVERSATION_ENGINE.md](CONVERSATION_ENGINE.md), [CHANNELS.md](CHANNELS.md), [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md).
