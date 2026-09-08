# Scheduled Reports

## Starting HEAD

`92b3170` (`feat: add telegram tts speed setting`).

Branch `main`. No dependency changes.

## Live production failure

Owner asked Jarvis (Основной, 2026-09-08) for three clock-time reports:

- 22:00 tomorrow plans from tasks + own calendar + linked Family calendar
- 08:30 plans for today
- 09:00 new mail + groups summary

Jarvis claimed success. Production objects were watchers, not reports: **#193** calendar-change watcher (primary only, accidental `in:inbox`, mail-oriented copy), **#194** Gmail digest, **#191** existing Gmail digest with no Telegram Groups source. Cursor did not mutate those Owner rows.

## Root architectural issue

A scheduled multi-source report is not a Reminder and not a Watcher. Watchers wait for a condition or source event. Reports fire at a known local time, collect configured sources, synthesize one grounded message, and deliver it.

## Reminder vs Watcher vs Scheduled Report

- Reminder: the user acts at a known time (“напомни в 9 проверить почту”).
- Watcher: future condition / source event (“жди письмо от школы”, “следи за задачей”).
- Scheduled Report: at a known time collect sources and send one report (“каждый вечер в 22 планы на завтра”).

## Existing Productivity Brief reuse

Collector / renderer / AI synthesizer / dispatch from `app/Services/Productivity/*` are reused for deterministic collection + optional phrasing. Named Scheduled Reports are the chat-created front-end. Generic B.2 morning/evening briefs stay opt-in and **off by default**. Matching brief modes are skipped when an active `daily_plan` / `tomorrow_plan` report exists so the engines do not double-send.

## Scheduled report model

Tables `scheduled_reports` and `scheduled_report_runs` (unique `scheduled_report_id` + `slot_key`). Status active/paused/cancelled. Timezone-aware `daily_local`. Owner timezone Europe/Rome.

## Report types

`daily_plan`, `tomorrow_plan`, `mail_groups_digest`, `custom_composite`.

## Source adapters

Semantic sources: tasks, reminders, projects, synthesis, google_calendar, gmail, telegram_groups, notifications. One report may combine several. Model never composes raw watcher configs.

## Calendar + shared calendar handling

`calendar_scope=all_relevant` includes primary, selected, and summaries matching Семья/family. IDs internally, names in UI. Read-only. Partial failure does not cancel the report.

## Gmail source

Read-only query at execution. Period `since_previous_report` (first run last 24h). No mark-read, archive, label, reply. Bodies are not stored in run metadata.

## Telegram Groups source

Summarizes stored `messages` for the user’s groups in the same period. Does not invent live Telegram history. Does not expose internal group ids.

## Task/plan source

Tasks due in the period, overdue important open work, reminders, grounded synthesis. 22:00 focuses on tomorrow; 08:30 on today.

## Period semantics

Execution time ≠ observation window. `today` / `tomorrow` / `since_previous_report` / `last_24h`.

## Scheduler/idempotency

`jarvis:reports:dispatch` every 5 minutes, `withoutOverlapping(4)`. Due when `next_run_at <= now`. Unique slot key prevents double-send. Not exact-second delivery.

## Partial recovery

One failed source still delivers. Copy notes the unavailable source in natural language.

## Delivery

Notification Center (`scheduled_report_ready`) + existing Web Push. Telegram via `SendsReminderTelegram`. Default telegram enabled. No new transport.

## NL routing

Periodic plan/mail/group reports → `create_scheduled_report`. Self-reminder wording stays Reminder. Gmail event wording stays Watcher. Old Gmail-digest-watcher hijack is retired for those phrases.

## Success grounding

Chat may say a report is configured only when `create_scheduled_report` returns `success=true` and `report_id`. Failed creation cannot claim success. Partial multi-report requests must name which ones exist.

## Conversational updates

Follow-ups update a unique trusted recent report (`add_source`). Ambiguous morning reports ask which one. No guessed ids.

## UI

Workspace Center **Отчеты** distinct from Напоминания and Автоматизации. Cards show name, “Каждый день · 22:00”, source labels. Pause / resume / cancel. Create through chat.

## Existing brief consolidation

Decision: Scheduled Reports are canonical named reports. B.2 briefs remain opt-in unnamed fallback and are skipped when an overlapping named report is active.

## Broken Owner data remediation

Do **not** patch production rows. After deploy Owner should cancel:

- Watcher **#193** (not a 22:00 tomorrow plan)
- Watcher **#194** (not an 08:30 today plan)
- Watcher **#191** if it would overlap the 09:00 mail+groups report

Then recreate via chat (validation scenarios A/B/C).

## Files changed

New report domain (models, migration, collector, composer, dispatch, tools, controller, command, Reports panel, tests). Wired into capabilities, tool registry, prompts, watcher/reminder hijack retirement, workspace chrome, scheduler, Productivity Brief skip, docs.

## Migration

`2026_09_08_195448_create_scheduled_reports_tables` — additive. `php artisan migrate --force`. No rollback. No `migrate:fresh`.

## Tests authored but NOT executed

Intent routing, period/sources/shared calendar, 08:30/09:00, since-previous vs first-run 24h, first-run before/after slot, partial send, duplicate slot, failed create, reminder/event wording, follow-up update, ambiguous clarification. Recurring Gmail digest hijack tests retargeted to Scheduled Reports. PHPUnit / `php artisan test` / Pest were **not** run.

## Static checks

`php -l` on touched PHP, `vendor/bin/pint --dirty --format agent`, `composer validate`, `npm run build`, `git diff --check`, `php artisan route:list`, `php artisan schedule:list`, additive migrate. No live report dispatch. No live Gmail/Calendar/Telegram API calls.

## Owner validation

After deploy, cancel obsolete watchers, then:

A) 22:00 Europe/Rome tomorrow plan, tasks + primary + Family
B) 08:30 today plan, tasks + calendars
C) 09:00 Gmail + Telegram groups

Inspect three Report cards. No Gmail/Calendar watcher pretending to be those reports. Wait for the slot or an Owner-authorized preview later. Cursor did not run a live report.

## Production safety

No phpunit. No Owner row writes. No proactive sends. No live provider calls. No migrate:fresh / rollback.
