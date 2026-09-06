# Phase B.1 — Reminders 2.0

**Status.** IMPLEMENTED / NOT VALIDATED. Not MANUAL PASS. No live Web Push or live Telegram delivery was performed.

## Starting HEAD

`24370378358cdf17993d22d7e91303c797ff6e57` — `feat: decouple reminders from Telegram` (`origin/main`). Working tree was clean.

## Schema changes

Additive only. No `migrate:fresh`, no destructive column drops in `up()`.

| Migration | Change |
| --- | --- |
| `2026_09_06_103407_add_completed_at_to_reminders_table` | `reminders.completed_at` nullable timestamp |
| `2026_09_06_103408_create_reminder_deliveries_table` | `reminder_id`, `channel`, `status`, `attempts`, `delivered_at`, `last_error`, `next_retry_at`, timestamps; unique `(reminder_id, channel)` |
| `2026_09_06_103409_create_reminder_occurrences_table` | `reminder_id`, `run_at`, `status`, `delivered_at`, `completed_at`, `delivery_snapshot` JSON |
| `2026_09_06_103410_create_push_subscriptions_table` | `user_id`, `endpoint` unique, encrypted `p256dh`/`auth`, `user_agent`, `is_active`, `revoked_at`, `last_used_at` |

`reminders.status` remains `string(32)`. New value: `completed`. Existing values unchanged.

## Reminder lifecycle

Core: `scheduled` → due/`processing` → `delivered` | `completed` | `cancelled` | `failed`.

- Delivered = at least one adapter notified.
- Done (`completed`) = user closed it. Not the same as delivered.
- Cancel stops the row / series.
- No delivery channel: stay `scheduled` / due, `delivery_state=no_channel`, 30-minute recheck. Not a failure.

## Delivery architecture

Adapters: Telegram, Web Push. Independent. One reminder may use both, one, or neither.

`ReminderDeliveryService::deliverToChannels` runs both, then `ReminderDeliveryState::applyAttempts` sets Core status. Per-channel rows in `reminder_deliveries`. A Telegram failure does not void a Push success (and the reverse).

## Web Push

- Package: `minishlink/web-push` ^11
- VAPID: `config/reminders.php` ← `VAPID_PUBLIC_KEY`, `VAPID_PRIVATE_KEY`, `VAPID_SUBJECT`
- Command: `php artisan jarvis:reminders:vapid` (creates keys **once**, writes `.env`, does not rotate)
- Private key is never sent to the frontend
- Service worker: `/reminder-sw.js`
- Subscribe only after «Включить уведомления»
- Click: `postMessage` + `focus` existing same-origin client, else `clients.openWindow` on allowlisted `/jarvis` or `/chat` URL built server-side
- Payload: `reminder_id`, `title`, `body` (≤120), `url`, `timestamp`
- Multi-device: all active subscriptions for the user
- 404/410: subscription revoked, no retry of that endpoint

## Telegram compatibility

Existing `TelegramBotManager::sendTextMessage` via `TelegramReminderSender`. Retry max 3, 1 then 2 minutes. Optional; create still does not require Telegram.

## Reminder Center v2

Workspace drawer sections: Due / Сегодня / Предстоящие / История. Edit, snooze, Done, Cancel. Delivery labels. Source conversation link when owned. Notification enable control.

## Edit / Snooze / Done / Cancel

- Edit: text, `run_at`, timezone, recurrence. Owned only. Not cancelled/completed. Resets delivery retry.
- Snooze: +10m, +1h, tomorrow (same local wall clock next calendar day), custom. Same row, new `run_at`, `scheduled`, delivery reset.
- Done: `completed` (one-shot) or complete this occurrence + advance (recurring).
- Cancel: existing semantics; UI labels distinguish Done vs Cancel.

## Recurrence

Format: `daily` | `weekdays` | `weekly` | `monthly`. Same row advances `run_at`. History in `reminder_occurrences`. DST: local wall clock in IANA timezone (Europe/Rome 09:00 stays 09:00). No RRULE package.

## AI tools

`create_reminder` (recurrence allowed), `list_reminders`, `update_reminder`, `snooze_reminder`, `complete_reminder`, `cancel_reminder`. Ambiguous selection returns `ambiguous` + candidates and does not mutate.

## Ownership

`user_id` from Auth. Foreign reminder/subscription → `not_found` 404. Push subscribe cannot set arbitrary `user_id`.

## Tests

Isolated unit tests only. Production MySQL was not used as a test database. Feature `RemindersTest` was not executed.

Covered: create validation without channels; no-channel due stays valid; edit/snooze/done/cancel; daily/weekdays/weekly/monthly + Europe/Rome DST; occurrence history; Telegram only / Push only / both / neither / mixed success-fail; retry then fail; subscription ownership; expired subscription deactivated; bounded payload; tool list/update/snooze/done/cancel/recurrence; ambiguous selection does not mutate.

## Build/static checks

`vendor/bin/pint --dirty --format agent` on PHP changes. `npm run build` after frontend / service worker work.

## Production safety

No `migrate:fresh`, `RefreshDatabase`, truncate, live Telegram, live Web Push to real devices, live Gemini/ElevenLabs. Additive `php artisan migrate` only.

## Known limitations

- Tasks / Notification Center / Daily Brief / proactive engine / mobile app: not this milestone (Phase B.2)
- Recurrence is four simple frequencies, not RFC 5545 RRULE
- Service worker is reminder-only, not a full PWA
- No live Owner validation of push permission, background notification, or Telegram+Push together

## Owner manual checklist

A. Workspace → Напоминания → **Включить уведомления** (user gesture; HTTPS). Confirm state «Уведомления включены».

B. Create a reminder for +2 minutes **without** Telegram. Expect a browser notification when due.

C. Close or background the tab. Expect the notification still.

D. Click the notification. JARVIS should focus or open; reminders panel should open (`?reminder=`).

E. Snooze **+10 мин**. Confirm new time and `scheduled`.

F. Edit text / time.

G. **Готово** (Done). Confirm it leaves the active list. Distinct from **Отменить**.

H. Recurring daily at a local time. After fire, next day same local clock; history keeps the occurrence.

I. Telegram-linked user: both Telegram message and Web Push.

J. Ordinary user cannot see or mutate another user’s reminders.
