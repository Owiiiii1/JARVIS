# Cursor Work Report — Milestone 18 Google Calendar

**Date:** 2026-09-04  
**Host:** `/var/www/jarvis`  
**GitHub:** `Owiiiii1/JARVIS`  
**Branch:** `main`

Live Google Calendar smoke deferred by Owner until Google integration milestones are complete.

---

## Before HEAD

`0e02f91` — `feat: add Google OAuth integration` (M17)

---

## What changed

Owner Conversation AI can list calendars, list/read/search events, check free/busy, and create/update/delete Google Calendar events through tools. Google remains the live source of truth. Reminder Engine is unchanged and separate.

---

## Migrations

`2026_09_04_030000_create_tool_confirmations_table`

- `public_id` UUID
- `user_id`, `conversation_id`
- `tool_name`, optional `tool_call_id`
- `arguments_encrypted`
- status `pending|confirmed|cancelled|expired|executed`
- `expires_at`, `confirmed_at`, `executed_at`

No local Calendar event cache table. Existing production tables were not altered.

Backup of users / conversations / integration_accounts / tool_execution_logs / AI settings / reminders was taken before migrate. Production counts after migrate: users 1, conversations 7, integration_accounts 0, tool_execution_logs 0, tool_confirmations 0, reminders 11.

---

## Calendar scopes

Least-privilege Calendar scope: `https://www.googleapis.com/auth/calendar`  
Covers calendar list, event read/write, and freebusy.

Identity connect still requests only `openid email profile`.  
Gmail scopes are not requested.

---

## Incremental OAuth

`GET /settings/integrations/google/connect?intent=calendar` adds Calendar scopes to the identity set. `include_granted_scopes=true`. Stored scopes = union of existing granted + newly granted. Missing refresh token in the incremental response does not overwrite the existing refresh token (M17 merge kept).

Identity-only connected card: Calendar permission required + Enable Calendar. Gmail stays not enabled.

---

## Adapter / service

`GoogleCalendarService` is the only Calendar HTTP client.

- `listCalendars`, `listEvents`, `getEvent`, `searchEvents`, `freeBusy`, `createEvent`, `updateEvent`, `deleteEvent`
- Access token only via `GoogleCredentialService::getValidAccessToken()`
- Laravel HTTP client, JSON, timeouts from `config/google_calendar.php`
- GET/list/search/freebusy may retry once; POST/PATCH/DELETE do not retry
- Errors normalized: `google_not_connected`, `calendar_scope_required`, `google_calendar_not_connected`, `google_calendar_scope_missing`, `calendar_not_found`, `event_not_found`, `calendar_forbidden`, `calendar_conflict`, `google_rate_limited`, `google_unavailable`

Tools contain no Google HTTP.

---

## Tool list

Owner (`google_calendar` capability):

| Tool | Operation |
| --- | --- |
| `list_google_calendars` | read |
| `list_calendar_events` | read |
| `get_calendar_event` | read |
| `search_calendar_events` | read |
| `google_calendar_freebusy` | read |
| `create_calendar_event` | write |
| `update_calendar_event` | write |
| `delete_calendar_event` | destructive |

Confirmation helpers (any active user, only while a pending row exists):

- `confirm_tool_action`
- `cancel_tool_action`

Normal user unchanged: `create_reminder`, `search_conversation_history`.

Definitions stay capability-based even if Google is disconnected. Runtime: `google_not_connected` / `calendar_scope_required` without Calendar HTTP.

---

## Tool metadata

All Calendar tools: capability `google_calendar`, provider `google`.  
Account resolution: current user → `IntegrationAccountService` → active Google account. Model cannot pass `integration_account_id`.

Successful Calendar calls update `last_used_at` / `last_success_at`. Failures update `last_used_at` / `last_error_*`.

---

## Timezones

Owner `users.timezone` is the authoritative fallback. Naive ISO datetimes are interpreted in that IANA zone and sent to Google as wall time + `timeZone`. All-day events stay dates (`all_day=true`). DST transitions use Carbon/DateTimeZone; create around Europe/Rome spring-forward keeps 01:30 and 03:30 wall times.

---

## Freebusy

Default calendar `primary`. Optional `calendar_ids` capped. Max range 31 days. Returns busy intervals + `has_busy`. Conversation AI decides “свободен / не свободен”.

---

## Create idempotency

Server-derived Google-compatible event id: `jvs` + sha256 hex of `user_id|conversation_id|tool_call_id`. Model cannot set the key. Same ToolCall retry reuses the id. Insert 409 fetches the existing event.

---

## Confirmation subsystem

`tool_confirmations` persists pending destructive / model-proposed writes. Encrypted arguments. TTL 10 minutes. Bound to user + conversation. One-time execute.

Conservative inbound phrases: `да`, `yes`, `confirm`, `подтверждаю`, `удалить` / `удали`; cancel `нет`, `no`, `cancel`, `отмена`. Web Confirm/Cancel and Telegram inline buttons send those phrases. Model cannot self-confirm (`confirmation_not_affirmed` without the server signal). Cross-user confirm is denied.

---

## Delete flow

1. `delete_calendar_event` → no Google DELETE, pending row, `confirmation_required`
2. Assistant asks for confirmation; Web/Telegram buttons available
3. User `да` → Core executes exactly one DELETE
4. Repeat confirm → `confirmation_already_executed`, no second DELETE
5. Expired / cancelled cannot execute

---

## Update / conflict

PATCH only supplied fields. `etag` sent as If-Match when present. Google 409/412 → `calendar_conflict`. Recurring series authoring is out of MVP; instance ids only.

---

## Pagination / bounds

Config: max calendars 20, events 25, search 15, freebusy 31 days, attendees 20, description 2000 chars, default search −90 / +365 days. Pages are followed only until the cap. `truncated` is returned when more remain.

---

## Tests

`tests/Feature/GoogleCalendarTest.php` plus updated owner tool lists in Integration Framework / Google OAuth tests.

`Http::fake` / local services only. No real Google connect, no real Calendar scope grant, no real events.

188 passed, 1 skipped.

---

## Build

`npm run build` succeeded after Integrations + Cabinet confirmation UI changes.

---

## Production counts

| Table | Count |
| --- | --- |
| users | 1 |
| conversations | 7 |
| integration_accounts | 0 |
| tool_execution_logs | 0 |
| tool_confirmations | 0 |
| reminders | 11 |

---

## Worker status

- `queue:work database --queue=analysis,memory,default` running
- Telegram queue worker running
- Scheduler: `jarvis:reminders:dispatch` every minute
- `queue:failed` empty

---

## Live smoke deferred

Live Google Calendar smoke deferred by Owner until Google integration milestones are complete.

Future combined smoke after M19 (do not run now):

- connect Google
- enable Calendar
- enable Gmail
- read calendar
- freebusy
- create/update/delete test event
- Gmail read/draft/send test
- token refresh
- disconnect/reconnect

---

## Known issues

- Production Google client id/secret still unset; Integrations Google card remains Not configured until Owner adds credentials and enables the Calendar API.
- Confirmation parser is conservative exact phrases only.
- Recurring rule authoring is out of scope.
- Google Meet links are not created.

---

## Next milestone

M19 — Gmail.
