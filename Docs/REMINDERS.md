# Reminders

Собственная подсистема Jarvis. **Не** Google Calendar.

Owner и Users создают reminders в **своём** space. Cross-user reminder обычному user недоступен.

This document separates **current implementation** from **target architecture**. Do not treat target as shipped.

---

## Current implementation

**Status.** IMPLEMENTED in Core. Delivery and create-path still Telegram-gated. Workspace panel: IMPLEMENTED IN CODE / LIVE BUG (Owner cannot see it in the real user workspace). Recurrence: schema only.

### Pipeline today

```
Conversation AI
  → create_reminder tool
  → ReminderService.assertCanCreate  (requires Telegram identity)
  → reminders (UTC)
  → jarvis:reminders:dispatch (every minute)
  → ReminderDeliveryService
  → Telegram sendMessage
  → current linked Telegram identity of that User
```

Exact create gate (`ReminderService::assertCanCreate`):

If `ChannelIdentity::findTelegramForUser(user_id)` is null → `ReminderException('telegram_not_connected')`.

No web, email, or push delivery.

### Entity (`reminders`)

| Field | Meaning |
| --- | --- |
| user_id | owner of the reminder |
| source_conversation_id / source_message_id | optional provenance |
| text | what to remind |
| run_at | UTC |
| original_local_time / timezone | local intent |
| status | `scheduled` / `processing` / `delivered` / `cancelled` / `failed` |
| delivered_at / cancelled_at | |
| recurrence_rule | nullable; **create tool rejects recurrence** |
| last_error / metadata | |

### Workspace panel (code)

- UI: `resources/js/personal-workspace/RemindersPanel.jsx`
- Routes: `GET {/jarvis|/chat}/reminders`, `POST …/reminders/{id}/cancel`
- Bell: `capabilities.reminders` (Owner and regular users both have the capability)
- Badge: `activeReminderCount`
- Inside panel: if Telegram is not linked, UI warns that delivery is Telegram-only

Owner confirmed: panel is **not visible** in the live user workspace. Treat as a product bug to fix in M25U.3.1, not as “users lack the capability”.

### Delivery rules today

- Disabled user → cancel `user_disabled`
- Identity rebound → send to **current** Telegram identity of that `user_id`
- No identity at send time → retry then `failed`

Google Calendar remains a separate Owner tool. “Поставь встречу” ≠ “напомни”.

Natural-language time is **not** regex-parsed in Core. The model sends structured `run_at_local`; Core validates and stores UTC.

---

## Target architecture

**Old decision (Telegram-required / Telegram-only delivery) is obsolete as the product target.** Current code still matches the old decision. ADR-240 supersedes ADR-039/046 **as target**.

```
Reminder (Core domain object)
    ↓
Core scheduler / state
    ↓
delivery channels (optional, many)
```

Possible delivery channels:

- Web Workspace (in-app)
- Telegram if linked
- future Web Push
- future Mobile Push

**Creating a reminder must not require Telegram.** Telegram is an optional delivery adapter, not the existence condition.

M25U.3.1 (next executable):

- panel visible
- create without Telegram
- persist in Core
- list / cancel own reminders
- Telegram optional
- **no** Web Push yet

Later (Phase B): Web Push, recurrence, snooze/done/edit, Notification Center, relation to Tasks.

---

## Tasks vs reminders

See [TASKS_AND_PRODUCTIVITY.md](TASKS_AND_PRODUCTIVITY.md).

- **Reminder:** when should Jarvis notify me?
- **Task:** what do I need to accomplish?

Do not collapse them into one table.
