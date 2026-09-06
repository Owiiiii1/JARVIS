# M25U.3.1 — Channel-independent Reminders

## Starting HEAD

- Branch: `main`
- Local HEAD = `origin/main` = `16a647e50527be06a1c475e689146a09cc9d49bf` (`chore: add Laravel Boost development tooling`)
- Working tree: clean
- Production DB `jarvis` was not written by this work. No `migrate:fresh`, no `RefreshDatabase`, no live Telegram / AI / Gemini / ElevenLabs.

## Current reminder architecture found

Before this change, Core already had `reminders`, `ReminderService`, `ReminderDispatchService` (`jarvis:reminders:dispatch` every minute), `ReminderDeliveryService`, `CreateReminderTool`, workspace routes, and `RemindersPanel`.

Create was Telegram-gated: `ReminderService::assertCanCreate()` threw `telegram_not_connected` when `ChannelIdentity::findTelegramForUser` was null. The same requirement was in `ConversationContextBuilder`, `AiFailureFallback`, and the tool description. Delivery treated missing Telegram as a send failure (`failOrRetry` → `failed` after 3 attempts). The header Bell was icon-only; Owner had a second English “Reminders” block only in the context drawer.

## Creation changes

`ReminderService::validateCreate()` checks only Core invariants: reminders capability, active user, non-empty text, valid IANA timezone, future `run_at`. Telegram is not a create precondition.

`CreateReminderTool` persists without Telegram. Success payload includes `telegram_connected` and `delivery` (`telegram` | `none`). Recurrence is still rejected (`unsupported_recurrence`).

AI copy no longer says reminders require Telegram. After success: if Telegram is linked, confirm Telegram delivery; if not, say the reminder is saved in Jarvis and visible in the Web panel. No extra LLM call. No Web Push promise.

## Delivery semantics

Two different outcomes:

**A. No delivery channel** (Telegram not linked, or empty `external_chat_id`): not a reminder error. Status returns to `scheduled`. `last_error` stays null. Attempts are not incremented.

**B. Telegram linked, send failed:** existing bounded retry. Attempts increment. After 3 attempts → `failed`. `delivery_state=error`, `delivery_channel=telegram`.

Successful Telegram send → `delivered`, `delivery_state=delivered`.

Disabled user → still cancelled with `user_disabled`.

No live Telegram calls in this work. Dispatcher concurrency (`lockForUpdate` / `skipLocked`, status `processing`) is unchanged.

## No-channel due behavior

- Recheck interval: **30 minutes** (`ReminderDeliveryState::NO_CHANNEL_RECHECK_MINUTES`)
- Metadata keys: `delivery_state=no_channel`, `delivery_channel=null`, `next_retry_at` (UTC `Y-m-d H:i:s`)
- `attempts` is **not** incremented for no-channel
- Scheduler will not immediately reclaim the row because `metadata->next_retry_at` is in the future
- If the user later links Telegram, the next recheck can deliver an overdue reminder

## Web panel changes

- Routes: `GET /jarvis/reminders`, `GET /chat/reminders`; cancel `POST …/reminders/{id}/cancel`
- Header UI entry for Owner and `role=user` when `capabilities.reminders=true`: Bell + **Напоминания** on `sm+`; compact Bell, `aria-label="Напоминания"`, badge on mobile
- Panel visibility is not tied to Telegram. Empty list still opens
- Informational notice if Telegram is absent: saved in Jarvis; Telegram delivery unavailable; connect Telegram for delivery outside Web
- Panel JSON: `is_due`, `delivery_state`, `delivery_channel`, `delivery_available`, `telegram_connected`. Full metadata is not returned
- Due label: «Срок наступил»; no-channel: «Telegram не подключён»; real send failure: «Ошибка доставки»

## Ownership/isolation

Cancel and list still filter `user_id = current user`. Foreign id → `not_found` (404). Extra in-memory guard: loaded reminder `user_id` must match. Impersonation continues to use the effective authenticated user. Owner and ordinary user see only their own personal reminders. Same panel routes on `/jarvis` and `/chat`.

## Schema/migrations

**No migration.** Existing statuses + `metadata` JSON are enough.

## Tests

Isolated unit tests only. Production MySQL was not used as a test database. sqlite PDO is not installed here. Feature `RemindersTest` was updated to match the new behavior but **not executed** (it creates temporary rows on production).

Covered without persisting:

- create validation without Telegram (user and owner)
- rejects inactive, empty text, invalid timezone, past time
- no-channel deferral: scheduled, not failed/cancelled/delivered, future `next_retry_at`, attempts unchanged
- Telegram success / retry / fail-after-3 via `ReminderDeliveryState` (fake state, no live send)
- panel row `is_due` + `delivery_state=no_channel` without leaking metadata
- owned cancel vs foreign/missing → `not_found`
- `capabilities.reminders` true for user and owner without Telegram
- tool description/payload; recurrence still rejected
- reminder prompt has no `telegram_not_connected` create gate
- `/jarvis` and `/chat` reminder routes
- header entry is capability-gated, not Telegram-gated (static JSX assertion)

## Static/build checks

- targeted PHPUnit under `tests/Unit/Reminders` + `AiFailureFallbackTest`
- Pint on dirty PHP
- `php -l`
- `npm run build`
- `composer validate`
- `git diff --check`
- `php artisan route:list --name=reminders`
- `php artisan migrate:status` read-only

No live Telegram sends.

## Production safety

- No schema change
- No mass updates/deletes
- Owner Telegram identities were not touched
- Tests that persist were not run against `jarvis`

## Documentation updates

- `Docs/REMINDERS.md`
- `Docs/CURRENT_STATE.md`
- `Docs/IMPLEMENTATION_PLAN.md`
- `Docs/TASKS_AND_PRODUCTIVITY.md`
- `Docs/ROADMAP.md` (M25U.3.1 was explicitly pending)
- this file

M25U.3.1: **IMPLEMENTED / NOT VALIDATED**. Not MANUAL PASS.

## Known limitations

- No Web Push / browser notifications. Closed tab will not get a background ping
- No recurrence
- No-channel overdue reminders wait up to 30 minutes after a later Telegram pairing before the dispatcher retries delivery
- Existing feature tests that hit production were not executed here
- Live Telegram delivery was not re-validated in this session

## Owner manual checklist

A. User WITHOUT Telegram:
1. Open `/chat`
2. See explicit **Напоминания** control (text on desktop, Bell on mobile)
3. Open the panel even with 0 reminders
4. Write: `Напомни мне через 5 минут проверить чайник`
5. Confirm the reminder is created
6. Open the panel: reminder is visible
7. Telegram requirement does **not** appear as a create blocker

B. Cancel: create a reminder → Отменить → it moves to history as cancelled

C. Due, no Telegram: short reminder → after due it stays valid in Web (`Срок наступил`), not Failed because Telegram is missing

D. User WITH Telegram: create a reminder → Telegram delivery still works

E. Isolation: an ordinary user sees only their own reminders
