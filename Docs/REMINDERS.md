# Reminders

Собственная подсистема Jarvis. **Не** Google Calendar.

Owner и Users создают reminders в **своём** space. Cross-user reminder обычному user недоступен.

**Status.** M25U.3.1 IMPLEMENTED / NOT VALIDATED. Reminder existence is channel-independent. Telegram is an optional delivery adapter. Recurrence is not implemented. Web Push is not implemented.

---

## Current implementation

```
Conversation AI
  → create_reminder tool
  → ReminderService.validateCreate  (Core invariants only; Telegram is not required)
  → reminders row, status=scheduled
  → visible in Web panel on /jarvis and /chat
  → jarvis:reminders:dispatch (every minute)
  → ReminderDeliveryService
       Telegram linked → sendMessage → delivered
       Telegram absent → stay scheduled, delivery_state=no_channel, next_retry_at +30 minutes
       Telegram send error → bounded retry (max 3) then failed
```

Create does **not** require `ChannelIdentity` Telegram. Ordinary user and Owner can persist a reminder without Telegram.

### Entity (`reminders`)

No migration for M25U.3.1. Existing columns and `metadata` JSON.

| Field | Meaning |
| --- | --- |
| user_id | owner of the reminder |
| source_conversation_id / source_message_id | optional provenance |
| text | what to remind |
| run_at | UTC |
| original_local_time / timezone | local intent |
| status | `scheduled` / `processing` / `delivered` / `cancelled` / `failed` |
| delivered_at / cancelled_at | |
| recurrence_rule | nullable; **create tool still rejects recurrence** |
| last_error / metadata | delivery bookkeeping; `last_error` is null for no-channel |

### Metadata keys (no-channel / delivery)

| Key | Meaning |
| --- | --- |
| `attempts` | Telegram send attempts only. **Not** incremented when there is no channel |
| `delivery_state` | `no_channel` \| `error` \| `delivered` |
| `delivery_channel` | `telegram` or `null` |
| `next_retry_at` | UTC `Y-m-d H:i:s`. No-channel recheck: **30 minutes**. Telegram send retry: 1 then 2 minutes |
| `last_error_class` | set only for real Telegram send failures |

Absence of a delivery adapter is **not** a reminder failure. Status stays `scheduled`. The row is still a valid due Core reminder.

If the user later links Telegram, the next bounded recheck can deliver an overdue reminder.

### Workspace panel

- UI: `resources/js/personal-workspace/RemindersPanel.jsx`
- Routes: `GET {/jarvis|/chat}/reminders` (`jarvis.reminders.index` / `chat.reminders.index`), `POST …/reminders/{id}/cancel`
- Header entry (Owner and `role=user`, when `capabilities.reminders=true`): Bell + **Напоминания** on desktop (`sm+`); compact Bell + `aria-label` + badge on mobile
- Owner context drawer also has a Напоминания section; it is extra, not a substitute for the header control
- Panel opens with zero reminders and without Telegram
- JSON includes `is_due`, `delivery_state`, `delivery_channel`, `delivery_available`, `telegram_connected`. Full `metadata` is not leaked
- Informational notice when Telegram is absent: reminder is saved in Jarvis; Telegram delivery is unavailable. It does **not** say the reminder does not work

Due in Web: `status` is `scheduled` or `processing` **and** `run_at <= now`. Labels: «Срок наступил», and «Telegram не подключён» when there is no channel.

### Delivery rules

- Disabled user → cancel `user_disabled` (unchanged)
- Identity rebound → send to **current** Telegram identity of that `user_id`
- No Telegram identity at send time → `delivery_state=no_channel`, stay `scheduled`, recheck in 30 minutes, attempts unchanged
- Telegram connected but API/send fails → retry then `failed` after 3 attempts

Google Calendar remains a separate Owner tool. “Поставь встречу” ≠ “напомни”.

Natural-language time is **not** regex-parsed in Core. The model sends structured `run_at_local`; Core validates and stores UTC.

Web Push / browser notifications are **not** implemented. A due reminder without Telegram is visible in the Web panel; Jarvis cannot notify a closed browser tab.

---

## Target leftovers (not this milestone)

- Recurrence
- Web Push / browser notifications
- snooze / edit / done
- Notification Center
- Tasks

---

## Tasks vs reminders

See [TASKS_AND_PRODUCTIVITY.md](TASKS_AND_PRODUCTIVITY.md).

- **Reminder:** when should Jarvis notify me?
- **Task:** what do I need to accomplish?

Do not collapse them into one table.
