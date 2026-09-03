# Reminders

Собственная подсистема Jarvis. **Не** Google Calendar и не календарное событие.

Owner и обычные Users создают reminders в **своём** User Space. Cross-user reminder обычному user недоступен. Administrative reminder peek для owner — later, не MVP.

Engines общие (Conversation Engine, Reminder Engine, Telegram Adapter). Scope = `user_id`.

---

## Pipeline

```
Conversation AI
  → Reminder Tool
  → Reminder Engine
  → DB (UTC)
  → Scheduler / Worker
  → Telegram Adapter
  → тот же User
```

Google Calendar — другой tool (owner-only). «Поставь встречу» ≠ «напомни».

---

## Delivery (сейчас)

**Только Telegram.**

Не web, не email, не mobile/desktop push.

Если User просит reminder, а Telegram identity не связан:

Conversation AI / системный слой сообщает, что для доставки нужно подключить Telegram. Reminder можно сохранить как scheduled, но delivery fail/pending до pairing — `TBD` (предпочтительно не обещать доставку без канала).

---

## Entity (концептуально)

| Поле | Смысл |
| --- | --- |
| id | |
| user_id | владелец reminder |
| source_conversation_id | откуда попросили |
| source_message_id | |
| text | что напомнить |
| run_at | абсолютное UTC |
| original_local_time / context | для реконструкции фразы |
| timezone | IANA на момент создания |
| status | scheduled / processing / delivered / cancelled / failed (`TBD` точный enum) |
| delivered_at | |
| cancelled_at | |
| recurrence_rule | nullable |
| created_at / updated_at | |

---

## Timezone

`users.timezone` — IANA, например `Europe/Rome`.

Фразы «завтра», «сегодня вечером», «в 11», «через два часа» интерпретируются в timezone **этого** User. В БД — UTC. Timezone хранить, чтобы recurrence и intent можно было восстановить.

---

## Capabilities

`reminders` есть у owner и user. Проверка в Core, не в адаптере.

---

## Proactive

Reminder — первый scheduled trigger. Общий Proactive Engine — future: [ARCHITECTURE.md](ARCHITECTURE.md). Не реализовывать autonomous проактивность сейчас.

Связано: [CONVERSATION_ENGINE.md](CONVERSATION_ENGINE.md), [CHANNELS.md](CHANNELS.md), [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md).
